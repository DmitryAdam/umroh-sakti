<?php

namespace App\Console\Commands;

use App\Models\Package;
use App\Support\BannedPost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Memasukkan hasil `php probe.php extract` (storage/extracted/*.json) ke database.
 *
 * Tidak pernah mem-publish. Semua paket masuk draft/review — publikasi hanya
 * setelah dilihat manusia di halaman review.
 */
class ImportExtractedPackages extends Command
{
    protected $signature = 'packages:import
        {--dir=}
        {--fresh : kosongkan paket lama dulu}
        {--prune : pindahkan post yang bukan penawaran paket ke storage/trash}';

    protected $description = 'Impor hasil ekstraksi probe.php ke database';

    public function handle(): int
    {
        $dir = $this->option('dir') ?: storage_path('extracted');
        $files = glob(rtrim($dir, '/').'/*.json') ?: [];

        if ($files === []) {
            $this->error("Tidak ada file di $dir. Jalankan: php probe.php extract");

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            DB::table('packages')->delete();
        }

        $created = $merged = $skipped = $bukanPaket = $lewatTanggal = 0;

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (! is_array($data) || ! isset($data['_media_id'])) {
                $skipped++;

                continue;
            }

            // Dua alasan buang yang keduanya permanen: bukan penawaran paket, atau
            // keberangkatannya sebelum ambang. Post-nya dibanned supaya fetch dan
            // extract berikutnya tidak menyentuhnya lagi.
            $alasan = match (true) {
                ! $this->isPackageOffer($data) => 'bukan_paket',
                $this->tooEarly($data) => 'sebelum_ambang',
                default => null,
            };

            if ($alasan !== null) {
                $alasan === 'bukan_paket' ? $bukanPaket++ : $lewatTanggal++;
                BannedPost::add($data['_media_id'], $data['_source'] ?? null, $alasan);
                if ($this->option('prune')) {
                    $this->prune($file, $data);
                }

                continue;
            }

            DB::transaction(function () use ($data, &$created, &$merged) {
                $this->importOne($data, $created, $merged);
            });
        }

        $ambang = (string) config('umroh.min_departure');
        $this->info("Paket baru: $created · digabung: $merged · bukan penawaran paket: $bukanPaket"
            ." · keberangkatan sebelum $ambang: $lewatTanggal · rusak: $skipped");
        $this->line('Review di: / (halaman pratinjau lokal)');

        return self::SUCCESS;
    }

    /**
     * Mayoritas postingan travel bukan penawaran paket: daftar hotel, manasik,
     * testimoni, ucapan hari besar. Dua lapis saringan supaya tidak masuk DB.
     *
     * Lapis 1 label konteks dari model. Lapis 2 cek struktural, karena model
     * kadang tetap melabeli package_offer padahal tidak ada satu pun angka:
     * paket itu minimal punya sinyal keberangkatan (tanggal / durasi / harga)
     * DAN satu sinyal pendukung lain. Nama hotel saja tidak cukup — poster
     * "Daftar Hotel dekat Masjidil Haram" penuh nama hotel tapi tidak menjual apa pun.
     */
    private function isPackageOffer(array $d): bool
    {
        if (($d['post_kind'] ?? 'package_offer') !== 'package_offer') {
            return false;
        }

        $prices = array_intersect_key($this->prices($d), array_flip(Package::PRICE_COLUMNS));
        $hasPrice = array_sum($prices) > 0;   // harga 0 = hasil baca yang gagal, bukan sinyal

        $departure = array_filter([
            ! empty($d['departure_date']),
            ! empty($d['duration_days']),
            $hasPrice,
        ]);
        if ($departure === []) {
            return false;
        }

        $signals = count($departure) + count(array_filter([
            ! empty($d['departure_city']),
            ! empty($d['airline']),
            ! empty($d['hotel_makkah']['raw_name']),
            ! empty($d['hotel_madinah']['raw_name']),
        ]));

        return $signals >= 2;
    }

    /**
     * Keberangkatan sebelum ambang: paketnya sudah lewat atau terlalu mepet.
     * Tanggal kosong tetap lolos — belum bisa dinilai, biar manusia yang lihat.
     */
    private function tooEarly(array $d): bool
    {
        $date = $d['departure_date'] ?? null;

        return $date !== null && $date !== '' && $date < (string) config('umroh.min_departure');
    }

    /**
     * Pindahkan, bukan hapus: saringan di atas heuristik, jadi hasil buangnya
     * harus bisa dicek ulang kalau prompt membaik. Folder rawnya ikut pindah
     * supaya `extract` tidak memanggil model lagi untuk post yang sama, dan
     * storage/raw isinya tinggal yang lanjut.
     */
    private function prune(string $file, array $d): void
    {
        $source = $d['_source'] ?? 'unknown';
        $dest = storage_path("trash/$source/{$d['_media_id']}");
        $raw = storage_path("raw/$source/{$d['_media_id']}");

        // Tata letaknya disamakan dengan Package::trashSources(): isi folder raw
        // langsung di $dest, hasil ekstraksi di sebelahnya sebagai extracted.json.
        // rename() gagal diam-diam kalau folder induk tujuan belum ada.
        File::ensureDirectoryExists(dirname($dest));

        is_dir($raw) ? File::moveDirectory($raw, $dest, overwrite: true) : File::ensureDirectoryExists($dest);

        File::move($file, "$dest/extracted.json");
    }

    private function importOne(array $d, int &$created, int &$merged): void
    {
        $key = Package::dedupKey(
            $d['departure_date'] ?? null,
            $d['hotel_makkah']['raw_name'] ?? null,
            $d['hotel_madinah']['raw_name'] ?? null,
            $d['airline'] ?? null,
        );

        $package = Package::where('dedup_key', $key)->first();

        if ($package) {
            // Repost dari akun agen lain: catat di kolom reposts, jangan bikin paket baru.
            $this->addRepost($package, $d);
            $merged++;

            return;
        }

        Package::create([
            ...$this->prices($d),
            ...$this->hotels($d),
            'source_account' => $d['_source'] ?? null,
            'media_id' => $d['_media_id'],
            'flyer_index' => $this->flyerIndex($d),
            'source_permalink' => $d['_permalink'] ?? null,
            'source_posted_at' => $d['_posted_at'] ?? null,
            'departure_date' => $d['departure_date'] ?? null,
            'date_certainty' => $d['date_certainty'] ?? 'unknown',
            'duration_days' => $d['duration_days'] ?? null,
            'departure_city' => $d['departure_city'] ?? null,
            'airline' => $d['airline'] ?? null,
            'guide_name' => $d['guide_name'] ?? null,
            'extension' => $d['extension'] ?? 'none',
            // Tidak pernah langsung published — selalu lewat review manusia.
            'status' => ($d['_needs_review'] ?? true) ? 'review' : 'draft',
            'extracted_at' => now(),
            'confidence' => $d['confidence']['price'] ?? null,
            'raw_extraction' => $d,
            'dedup_key' => $key,
            'facilities' => array_values(array_intersect(
                $d['facilities'] ?? [], Package::FACILITY_CODES,
            )),
            'facilities_raw' => $d['facilities_raw'] ?? null,
        ]);
        $created++;
    }

    /**
     * Gambar carousel yang jadi sumber baris ini: "3.jpg" -> 3. Satu carousel bisa
     * memuat beberapa paket, jadi angka inilah yang membedakan barisnya.
     */
    private function flyerIndex(array $d): ?int
    {
        $file = $d['_useful_images'][0] ?? null;

        return $file === null ? null : (int) pathinfo($file, PATHINFO_FILENAME);
    }

    /**
     * Akun lain yang memposting paket yang sama. Idempoten per media_id supaya
     * import berulang atas backlog yang sama tidak menggandakan barisnya.
     */
    private function addRepost(Package $package, array $d): void
    {
        $reposts = $package->reposts ?? [];

        foreach ($reposts as $repost) {
            if (($repost['media_id'] ?? null) === $d['_media_id']) {
                return;
            }
        }

        // Post asal ekstraksi sudah tercatat di kolom paket itu sendiri.
        if ($package->media_id === $d['_media_id']) {
            return;
        }

        $reposts[] = [
            'media_id' => $d['_media_id'],
            'account' => $d['_source'] ?? 'unknown',
            'permalink' => $d['_permalink'] ?? null,
            'posted_at' => $d['_posted_at'] ?? null,
        ];

        $package->update(['reposts' => $reposts]);
    }

    /**
     * price_tiers dari ekstraksi -> kolom tetap. Okupansi di luar keempatnya dibuang.
     *
     * @return array<string, int|string|bool>
     */
    private function prices(array $d): array
    {
        $out = [];
        foreach ($d['price_tiers'] ?? [] as $tier) {
            if (! isset($tier['occupancy'], $tier['amount'])
                || ! in_array("price_{$tier['occupancy']}", Package::PRICE_COLUMNS, true)) {
                continue;
            }
            $out["price_{$tier['occupancy']}"] = $this->toIdr($tier);
            $out['currency'] = 'IDR';
            // Satu tier "mulai dari" sudah cukup untuk menandai seluruh paket.
            $out['price_starting_from'] = ($out['price_starting_from'] ?? false)
                || (bool) ($tier['is_starting_from'] ?? false);
        }

        return $out;
    }

    /**
     * Flyer berharga USD dikonversi ke IDR di sini — satu-satunya tempatnya.
     * Kalau tidak, "USD 3.300" masuk apa adanya lalu tampil "0,0 jt", selalu
     * jadi paket termurah saat diurutkan, dan selalu memicu warning BPIU.
     * Angka + mata uang aslinya tetap utuh di raw_extraction.
     */
    private function toIdr(array $tier): int
    {
        $amount = (int) $tier['amount'];

        return ($tier['currency'] ?? 'IDR') === 'USD'
            ? (int) round($amount * (float) config('umroh.usd_rate'))
            : $amount;
    }

    /**
     * Nama hotel disimpan apa adanya dari flyer. Tidak ada master, tidak ada
     * fuzzy match — "Anjum" dan "setaraf Anjum" jadi dua nilai berbeda.
     *
     * @return array<string, int|string|null>
     */
    private function hotels(array $d): array
    {
        $out = [];
        foreach (['makkah', 'madinah'] as $city) {
            $raw = $d["hotel_$city"]['raw_name'] ?? null;
            if (! $raw) {
                continue;
            }

            $out["hotel_$city"] = $raw;
            $out["nights_$city"] = $d["hotel_$city"]['nights'] ?? null;
        }

        return $out;
    }
}
