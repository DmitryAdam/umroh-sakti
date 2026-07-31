<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Satu paket = satu baris, dan semua komponennya ikut di baris ini: hotel,
 * fasilitas, dan daftar akun yang memposting ulang. Tidak ada relasi.
 */
class Package extends Model
{
    protected $guarded = [];

    /** Disk flyer paket (config/filesystems.php) — local saat dev, s3 di produksi. */
    public const FLYER_DISK = 'flyers';

    /** Tier harga tetap: satu paket satu baris, tanpa tabel tier. */
    public const PRICE_COLUMNS = ['price_quad', 'price_triple', 'price_double', 'price_single'];

    public const FACILITY_CODES = ['visa', 'tiket', 'hotel', 'makan_3x', 'muthawif',
        'perlengkapan', 'handling', 'city_tour', 'asuransi'];

    /**
     * Status publikasi. Cuma `published` yang tampil ke pengunjung; `draft` dan
     * `review` beda asalnya saja (ImportExtractedPackages: `_needs_review` →
     * review), dua-duanya sama-sama belum publik.
     *
     * Tidak ada `rejected` walau migrasinya menyebutnya: paket yang ditolak
     * dihapus barisnya (tombol ×) sekalian mengecualikan postnya, jadi status
     * "ditolak" tidak pernah punya baris untuk ditempeli.
     */
    public const STATUSES = ['draft', 'review', 'published'];

    /** Koreksi manusia atas hasil ekstraksi — bahan perbaikan prompt, bukan status publikasi. */
    public const REVIEW_VERDICTS = [
        'oke' => 'Oke, datanya benar',
        'bukan_paket' => 'Bukan penawaran paket',
        'data_salah' => 'Data salah baca',
        'foto_salah' => 'Foto yang dipajang salah',
        'kurang_data' => 'Ada data di flyer yang tidak terambil',
    ];

    protected $casts = [
        'departure_date' => 'date',
        'source_posted_at' => 'datetime',
        'extracted_at' => 'datetime',
        'raw_extraction' => 'array',
        'facilities' => 'array',
        'reposts' => 'array',
        'confidence' => 'float',
        'price_starting_from' => 'bool',
        'reviewed_at' => 'datetime',
    ];

    /**
     * `Y-m-d` diterima apa adanya; `Y-m` (bulannya saja) dinormalkan ke tanggal 1.
     * Dijaga di setter supaya jalur mana pun (import, tinker, worker yang masih
     * memegang kode lama) kena aturan yang sama.
     *
     * Tahun saja TIDAK diterima. Flyer haji sering cuma menulis "Berangkat Tahun
     * 2027" dan model menyalinnya apa adanya; cast `date` membaca "2027" sebagai
     * unix timestamp — 2027 detik — jadi barisnya masuk bertanggal 1970-01-01
     * 07:33, terurut paling awal dan lolos semua filter rentang.
     *
     * Bulan tanpa tanggal sengaja TIDAK dibuang: banyak flyer memang cuma menulis
     * "Maret 2027", dan tanggal 1 masih bisa diurut & difilter rentang. Yang
     * membedakannya dari tanggal pasti adalah `date_certainty` = month — jangan
     * pernah melabeli hasil normalisasi ini `exact`, angka tanggalnya bikinan kita.
     */
    public static function tanggal(mixed $value): ?string
    {
        $value = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) $value;

        return match (true) {
            (bool) preg_match('/^\d{4}-\d{2}-\d{2}/', $value) => $value,
            (bool) preg_match('/^\d{4}-\d{2}$/', $value) => "$value-01",
            default => null,
        };
    }

    /** Tanggal yang cuma bulan -> `month`, apa pun kata model. Lihat tanggal(). */
    public static function kepastian(mixed $value, ?string $certainty): string
    {
        return match (true) {
            self::tanggal($value) === null => 'unknown',
            (bool) preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $value) => $certainty ?: 'exact',
            default => 'month',
        };
    }

    public function setDepartureDateAttribute(mixed $value): void
    {
        $this->attributes['departure_date'] = self::tanggal($value);
    }

    /**
     * Kunci dedup: satu paket yang diposting ulang puluhan agen harus menyatu
     * jadi satu baris. Sengaja TIDAK memakai source_account.
     *
     * ponytail: tanpa penanda travel, dua travel berbeda dengan tanggal + hotel
     * + maskapai yang sama akan menyatu. Tambahkan penanda PPIU ke kunci ini
     * begitu izinnya melekat ke source_accounts.
     */
    public static function dedupKey(
        ?string $departureDate, ?string $hotelMakkah,
        ?string $hotelMadinah, ?string $airline,
    ): string {
        return implode('|', [
            self::tanggal($departureDate) ?? '-',
            ...array_map(self::fold(...), [$hotelMakkah, $hotelMadinah, $airline]),
        ]);
    }

    /**
     * Nilai untuk kunci dedup. Transliterasi Arab-Latin di flyer tidak konsisten
     * dan pemisah daftar juga tidak: paket yang sama diposting dua kali oleh akun
     * yang sama sebagai "Qashr Al Anshar" + "Saudia, Garuda Indonesia" lalu
     * "Qasr Al Anshar" + "Saudia / Garuda Indonesia" — dulu jadi dua baris.
     *
     * Yang dibuang: tanda baca, spasi, kata sandang "al", dan huruf "h" (dh/kh/sh/th
     * dan -ah di akhir semuanya varian ejaan). Token sisanya diurut supaya "A, B"
     * sama dengan "B / A".
     *
     * ponytail: mengurutkan token berarti dua hotel yang katanya sama tapi
     * urutannya beda ikut menyatu. Belum pernah kejadian di data; kalau nanti
     * kejadian, hilangkan sort()-nya dan bandingkan sebagai himpunan bersorot.
     */
    private static function fold(?string $value): string
    {
        $tokens = preg_split('/[^a-z0-9]+/', mb_strtolower(trim((string) $value)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_filter(
            array_map(fn ($t) => str_replace('h', '', $t), $tokens),
            fn ($t) => $t !== '' && $t !== 'al',
        );
        sort($tokens);

        return implode('', $tokens) ?: '-';
    }

    /**
     * Hari terakhir paket. "9 hari" itu inklusif — berangkat 15 Agustus, pulang
     * 23 Agustus — jadi orang tidak perlu menghitung sendiri.
     */
    public function returnDate(): ?Carbon
    {
        return $this->departure_date && $this->duration_days
            ? $this->departure_date->copy()->addDays((int) $this->duration_days - 1)
            : null;
    }

    /** "03 – 11 Nov 2026", atau cuma tanggal berangkat kalau durasinya kosong. */
    public function dateLabel(string $month = 'M'): ?string
    {
        if (! $this->departure_date) {
            return null;
        }

        return ($pulang = $this->returnDate())
            ? $this->departure_date->translatedFormat("d $month").' – '.$pulang->translatedFormat("d $month Y")
            : $this->departure_date->translatedFormat("d $month Y");
    }

    /**
     * Tier yang terisi saja: ['quad' => 25900000, 'double' => 29000000].
     *
     * @return array<string, int>
     */
    public function prices(): array
    {
        $out = [];
        foreach (self::PRICE_COLUMNS as $column) {
            if ($this->$column !== null) {
                $out[substr($column, 6)] = (int) $this->$column;
            }
        }

        return $out;
    }

    /**
     * Caption post asalnya, dibaca dari `storage/raw/{akun}/{media_id}/post.json`.
     * Tidak disalin ke kolom: caption yang sama dipakai bersama semua slide satu
     * carousel, dan post.json memang sengaja ditinggal di raw (itu yang bikin fetch
     * berikutnya melewati post ini). Null kalau rawnya sudah kebuang.
     */
    public function caption(): ?string
    {
        $file = storage_path("raw/{$this->source_account}/{$this->media_id}/post.json");
        $post = is_file($file) ? json_decode((string) File::get($file), true) : null;

        return trim($post['caption'] ?? '') ?: null;
    }

    /**
     * Semua post yang memuat paket ini: post asal ekstraksi dulu, lalu repostnya.
     *
     * @return array<int, array{media_id: ?string, account: ?string, permalink: ?string}>
     */
    public function posts(): array
    {
        return [
            [
                'media_id' => $this->media_id,
                'account' => $this->source_account,
                'permalink' => $this->source_permalink,
            ],
            ...array_values($this->reposts ?? []),
        ];
    }

    /**
     * Letak flyer paket ini di disk `flyers`: satu paket = satu gambar, jadi
     * kuncinya `{media_id}/{flyer_index}.jpg`. Null untuk baris lama yang
     * flyer_index-nya belum ada — itu masih dilayani dari storage/raw.
     */
    public function flyerPath(): ?string
    {
        return $this->flyer_index === null ? null : "{$this->media_id}/{$this->flyer_index}.jpg";
    }

    /**
     * Pindahkan flyer dari tempat singgah (storage/raw) ke disk `flyers`.
     * Dipanggil sekali, tepat setelah barisnya dibuat — jadi yang menetap cuma
     * gambar yang sudah divonis penawaran paket oleh pipeline; sisa isi carousel
     * (foto suasana, slide dakwah) tetap di raw dan ikut kebuang saat prune.
     *
     * Raw-nya dihapus setelah tersalin: kalau tidak, "pindah ke s3" cuma jadi
     * penggandaan byte. post.json sengaja ditinggal — itu yang bikin fetch
     * berikutnya melewati post ini.
     */
    public function promoteFlyer(): bool
    {
        $path = $this->flyerPath();
        $raw = storage_path("raw/{$this->source_account}/{$this->media_id}/{$this->flyer_index}.jpg");

        if ($path === null || ! is_file($raw)) {
            return false;
        }

        Storage::disk(self::FLYER_DISK)->put($path, File::get($raw));
        File::delete($raw);

        return true;
    }

    /**
     * Kebalikan promoteFlyer: kembalikan flyer ke storage/raw supaya `probe.php
     * extract` bisa membacanya lagi (tombol "baca ulang"). Yang dibaca extract
     * cuma raw — flyer yang sudah dipindah ke disk `flyers` tidak kelihatan dari
     * sana, dan tanpa ini vision cuma dikirimi sisa gambar carousel.
     *
     * File raw yang masih ada tidak ditimpa; import berikutnya yang memindahkannya
     * lagi (dan menghapus raw-nya) lewat promoteFlyer.
     */
    public function restoreFlyer(): bool
    {
        $path = $this->flyerPath();
        $raw = storage_path("raw/{$this->source_account}/{$this->media_id}/{$this->flyer_index}.jpg");

        if ($path === null || is_file($raw) || ! Storage::disk(self::FLYER_DISK)->exists($path)) {
            return false;
        }

        File::ensureDirectoryExists(dirname($raw));
        File::put($raw, Storage::disk(self::FLYER_DISK)->get($path));

        return true;
    }

    /**
     * Thumbnail flyer paket ini. Yang keluar selalu versi kecil — flyer full
     * tidak pernah di-re-host, lihat FlyerThumbController.
     *
     * Satu paket satu gambar (`flyer_index`), jadi tidak ada glob dan tidak ada
     * cek keberadaan file: di s3 cek itu satu HEAD request per kartu. Kalau
     * gambarnya benar-benar hilang, controllernya yang balas 404.
     *
     * Baris lama tanpa `flyer_index` jatuh ke jalur raw yang lama: glob folder
     * post, disaring `_useful_images`.
     *
     * ponytail: cuma flyer post asal — repost tidak dipajang karena flyernya
     * gambar yang itu-itu juga (rebranding).
     *
     * @return array<int, string>
     */
    public function flyers(): array
    {
        if ($this->flyer_index !== null) {
            return [route('flyer', ['media' => $this->media_id, 'index' => $this->flyer_index])];
        }

        $files = glob(storage_path("raw/{$this->source_account}/{$this->media_id}/*.jpg")) ?: [];
        sort($files, SORT_NATURAL);

        $indexes = array_map(fn ($f) => (int) pathinfo($f, PATHINFO_FILENAME), $files);

        if ($useful = $this->raw_extraction['_useful_images'] ?? null) {
            $keep = array_map(fn ($f) => (int) pathinfo($f, PATHINFO_FILENAME), $useful);
            $indexes = array_values(array_intersect($indexes, $keep));
        }

        return array_map(
            fn ($i) => route('flyer', ['media' => $this->media_id, 'index' => $i]),
            $indexes,
        );
    }

    /**
     * Hapus raw + hasil ekstraksi seluruh post paket ini. Yang menjaga post ini
     * tidak ter-fetch & ter-extract lagi itu baris `excluded_posts`, bukan filenya
     * — jadi filenya tidak perlu disimpan di mana pun.
     */
    public function deleteSources(): void
    {
        if ($path = $this->flyerPath()) {
            Storage::disk(self::FLYER_DISK)->delete($path);
        }

        foreach ($this->posts() as $post) {
            if (! $post['media_id']) {
                continue;
            }

            File::deleteDirectory(storage_path("raw/{$post['account']}/{$post['media_id']}"));
            File::delete(storage_path("extracted/{$post['media_id']}.json"));
        }
    }

    /**
     * Harga di flyernya USD; kolomnya sudah dikonversi ke IDR saat import
     * (ImportExtractedPackages::toIdr). Angka rupiahnya hasil kurs, bukan
     * angka yang tertulis — jadi ditandai di UI.
     */
    public function convertedFromUsd(): bool
    {
        foreach ($this->raw_extraction['price_tiers'] ?? [] as $tier) {
            if (($tier['currency'] ?? 'IDR') === 'USD') {
                return true;
            }
        }

        return false;
    }

    /** Harga termurah, dipakai untuk sorting & facet harga. */
    public function lowestPrice(): ?int
    {
        $prices = $this->prices();

        return $prices ? min($prices) : null;
    }

    /** Di bawah BPIU Referensi Kemenag -> wajib warning, jangan disembunyikan. */
    public function isBelowReferencePrice(): bool
    {
        $low = $this->lowestPrice();

        return $low !== null && $low < (int) config('umroh.bpiu_reference');
    }

    public function scopePublished($q)
    {
        return $q->where('status', 'published');
    }

    public function scopeNeedsReview($q)
    {
        return $q->whereIn('status', ['draft', 'review']);
    }
}
