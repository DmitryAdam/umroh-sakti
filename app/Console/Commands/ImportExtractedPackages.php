<?php

namespace App\Console\Commands;

use App\Models\Package;
use App\Models\SourceAccount;
use App\Support\ExcludedPost;
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
        {--prune : hapus raw + hasil ekstraksi post yang bukan penawaran paket}';

    protected $description = 'Impor hasil ekstraksi probe.php ke database';

    /** Detik: umur minimal file hasil ekstraksi sebelum rawnya boleh dihapus (lihat buang()). */
    private const PRUNE_GRACE = 300;

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

        // Akun yang diblokir setelah ekstraksinya jalan: filenya sudah dibuang saat
        // blokir, tapi job `ai` yang masih di tengah jalan menulisnya lagi sesudah itu.
        // Tanpa saringan ini paketnya lahir kembali beberapa menit setelah diblokir.
        $diblokir = SourceAccount::blocked()->pluck('username')->flip();

        $created = $merged = $skipped = $bukanPaket = $lewatTanggal = $sudahAda = $slideBuntu = 0;
        $blokir = 0;
        $kurang = ['tanpa_gambar' => 0, 'tanpa_tanggal' => 0, 'tanpa_harga' => 0];
        $tolak = [];

        foreach ($files as $file) {
            // File bisa lenyap antara glob dan baca — tombol × dan prune menghapus
            // hasil ekstraksi sementara import menyusuri daftar lamanya. Bukan galat;
            // sama seperti loop post di probe.php cmdExtract().
            $json = @file_get_contents($file);
            $data = $json === false ? null : json_decode($json, true);
            if (! is_array($data) || ! isset($data['_media_id'])) {
                $skipped++;

                continue;
            }

            if ($diblokir->has($data['_source'] ?? '')) {
                File::delete($file);
                $blokir++;

                continue;
            }

            // Satu gambar bisa menjual banyak keberangkatan (flyer jadwal), jadi satu
            // file bisa jadi belasan baris. Ekspansinya di sini supaya semua saringan
            // di bawah menilai keberangkatan, bukan gambarnya.
            $offers = $this->offers($data);

            // Dua alasan buang yang keduanya permanen: bukan penawaran paket, atau
            // keberangkatannya sebelum ambang. Ditunda dulu, tidak langsung dieksekusi:
            // slide sesudahnya dari carousel yang sama bisa saja jadi paket.
            $alasan = match (true) {
                ! $this->isPackageOffer($data) => 'bukan_paket',
                $this->isHaji($data) => 'haji',
                $this->bukanUmroh($data) => 'bukan_umroh',
                // Postnya cuma dikecualikan kalau SEMUA keberangkatannya sudah lewat.
                // Flyer jadwal yang separuh barisnya kedaluwarsa tetap dipakai.
                array_filter($offers, fn ($o) => ! $this->tooEarly($o)) === [] => 'sebelum_ambang',
                default => null,
            };

            if ($alasan !== null) {
                // `haji` & `bukan_umroh` ikut dihitung "bukan penawaran paket": dua-duanya
                // vonis "ini bukan barang yang dijual portal ini", bukan soal tanggal.
                $alasan === 'sebelum_ambang' ? $lewatTanggal++ : $bukanPaket++;
                $tolak[] = [$file, $data, $alasan];

                continue;
            }

            foreach ($offers as $offerIndex => $offer) {
                // Keberangkatan yang sudah lewat ambang dilewat satu-satu — barisnya
                // saja, bukan filenya (baris lain di flyer jadwal yang sama masih laku).
                if ($this->tooEarly($offer)) {
                    $lewatTanggal++;

                    continue;
                }

                // Wajib: keberangkatan (minimal bulan) DAN harga. Paket tanpa salah satunya
                // tidak bisa dicari, tidak bisa diurut, dan tidak bisa dibandingkan — itu
                // seluruh gunanya portal ini.
                //
                // Sengaja TIDAK masuk $tolak: ini kegagalan membaca, bukan vonis atas
                // postnya. Yang mengecualikan post itu permanen (fetch + extract ikut
                // dilewati), jadi flyer yang harganya cuma gagal terbaca akan hilang
                // selamanya begitu promptnya membaik. Filenya dibiarkan di
                // storage/extracted — import berikutnya mencobanya lagi, gratis.
                if ($belum = $this->belumLengkap($offer)) {
                    $kurang[$belum]++;

                    continue;
                }

                DB::transaction(function () use ($offer, $offerIndex, &$created, &$merged, &$sudahAda) {
                    $this->importOne($offer, $offerIndex, $created, $merged, $sudahAda);
                });
            }
        }

        $slideBuntu = $this->buang($tolak);

        $ambang = (string) config('umroh.min_departure');
        $this->info("Paket baru: $created · digabung: $merged · sudah ada: $sudahAda"
            ." · bukan penawaran paket: $bukanPaket"
            ." · keberangkatan sebelum $ambang: $lewatTanggal"
            ." · tanpa gambar: {$kurang['tanpa_gambar']}"
            ." · tanpa tanggal: {$kurang['tanpa_tanggal']} · tanpa harga: {$kurang['tanpa_harga']}"
            ." · akun diblokir: $blokir"
            ." · slide ditolak tapi postnya dipakai: $slideBuntu · rusak: $skipped");
        $this->line('Review di: / (halaman pratinjau lokal)');

        return self::SUCCESS;
    }

    /** Batas keberangkatan per gambar. Flyer jadwal terpanjang yang terukur 18 baris. */
    private const MAX_DEPARTURES = 40;

    /**
     * Satu hasil ekstraksi -> satu data paket per keberangkatan.
     *
     * Flyer jadwal ("Edisi Agustus | Edisi September", tabel tanggal) menjual
     * belasan keberangkatan dalam SATU gambar, bedanya cuma tanggal, durasi, dan
     * harga; hotel/maskapai kadang ikut beda per program. Sampai `departures` ada,
     * cuma yang paling menonjol yang jadi baris dan sisanya hilang — padahal
     * justru di situ jadwalnya paling banyak.
     *
     * Yang null di satu keberangkatan diwarisi dari field tingkat atas (PPIU, kota,
     * pembimbing, fasilitas tidak pernah ditulis ulang per baris). `departures`
     * kosong = flyer satu keberangkatan, jalur lama, satu baris.
     *
     * @return array<int, array<string, mixed>>
     */
    private function offers(array $d): array
    {
        $departures = array_slice(
            array_filter($d['departures'] ?? [], is_array(...)), 0, self::MAX_DEPARTURES,
        );

        if ($departures === []) {
            return [$d];
        }

        // Tabel jadwalnya tidak ikut disalin ke tiap baris: isinya sama untuk semua
        // baris yang lahir dari gambar ini, jadi menyimpannya N kali cuma numpuk byte.
        unset($d['departures']);

        return array_values(array_map(function (array $dep) use ($d) {
            $override = array_filter([
                'departure_date' => $dep['departure_date'] ?? null,
                'duration_days' => $dep['duration_days'] ?? null,
                'airline' => $dep['airline'] ?? null,
                'hotel_makkah' => $dep['hotel_makkah'] ?? null,
                'hotel_madinah' => $dep['hotel_madinah'] ?? null,
                'price_tiers' => $dep['price_tiers'] ?? [],
                // 'none' itu jawaban ("tanpa extension"), 'unknown' berarti tidak
                // ditulis per baris — yang terakhir mewarisi tingkat atas.
                'extension' => ($dep['extension'] ?? 'unknown') === 'unknown' ? null : $dep['extension'],
                // Kepastian tanggal ikut tanggalnya: kalau barisnya punya tanggal
                // sendiri, label dari tingkat atas tidak berlaku untuk baris ini.
                'date_certainty' => ($dep['departure_date'] ?? null) ? ($dep['date_certainty'] ?? null) : null,
            ], fn ($v) => $v !== null && $v !== []);

            return $override + $d;
        }, $departures));
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

    /** Aturannya di `Package::tanggal()` — dipakai juga oleh setter model. */
    private function tanggal(array $d): ?string
    {
        return Package::tanggal($d['departure_date'] ?? null);
    }

    /**
     * Syarat masuk DB: ada keberangkatan (minimal bulan) DAN ada harga > 0.
     * Tanpa salah satunya paket tidak bisa dicari, diurut, atau dibandingkan —
     * itu seluruh gunanya portal ini.
     *
     * Gambar ikut syarat masuk: satu paket = satu flyer, dan flyer itu satu-satunya
     * bukti yang bisa dilihat manusia saat review. Hasil ekstraksi caption-only
     * (`_useful_images` kosong — semua gambarnya kena dedup hash, atau jalur `seed`)
     * lahir sebagai baris ber-`flyer_index` null: kartunya tampil tanpa gambar, dan
     * vision tidak pernah memvonis "ini penawaran" karena tidak ada yang dilihat.
     *
     * @return 'tanpa_gambar'|'tanpa_tanggal'|'tanpa_harga'|null
     */
    private function belumLengkap(array $d): ?string
    {
        $prices = array_intersect_key($this->prices($d), array_flip(Package::PRICE_COLUMNS));

        return match (true) {
            $this->flyerIndex($d) === null => 'tanpa_gambar',
            $this->tanggal($d) === null => 'tanpa_tanggal',
            array_sum($prices) <= 0 => 'tanpa_harga',   // harga 0 = gagal baca, bukan gratis
            default => null,
        };
    }

    /**
     * Haji khusus/furoda, bukan umroh. Portal ini agregator umroh.
     *
     * Nama travel BUKAN penanda: "UMROH & HAJI PLUS" dan "UMRAH & HAJI KHUSUS" itu
     * kop surat, paketnya umroh beneran — 34 dari 35 baris yang mengandung
     * "haji khusus"/"haji plus" ternyata umroh. Yang dipakai cuma istilah **produk**
     * haji yang tidak pernah nyangkut di nama PT, dan itu pun masih harus ditemani
     * satu sinyal ukuran: haji khusus tidak pernah lebih pendek dari 18 hari atau
     * lebih murah dari 100 juta. Terukur atas 279 hasil ekstraksi: kena 3, ketiganya
     * haji, nol salah tangkap.
     */
    private function isHaji(array $d): bool
    {
        $teks = mb_strtolower((string) ($d['_flyer_text'] ?? ''));

        if (! preg_match('/\b(visa haji|porsi haji|nomor porsi|maktab|haji furoda|badal haji)\b/', $teks)) {
            return false;
        }

        $harga = max([0, ...array_map(
            fn ($t) => $this->toIdr($t), array_filter($d['price_tiers'] ?? [], fn ($t) => isset($t['amount'])),
        )]);

        return ($d['duration_days'] ?? 0) >= 18 || $harga >= 100_000_000;
    }

    /**
     * Wisata halal ke tujuan yang bukan tanah suci — Korea, Jepang, China, Eropa.
     * Portal ini agregator umroh; paket begini lolos semua saringan lain karena
     * bentuknya memang paket: ada tanggal, durasi, harga, maskapai.
     *
     * Nama travel BUKAN penanda, sama seperti `isHaji()` — dan di sini lebih tajam
     * lagi: "Ramah Umroh & Halal Tour" dan "ABNA TOUR — The Ultimate Hajj & Umrah
     * Experience" itu kop surat yang memuat kata umroh, dipasang di flyer yang
     * menjual Seoul. Makanya kata `umroh`/`umrah` sendiri TIDAK dihitung jejak
     * tanah suci; yang dihitung cuma yang tidak pernah nyangkut di nama PT
     * (makkah/madinah/nabawi/haram/thawaf/…).
     *
     * Dua sinyal, sama seperti haji: ada tujuan yang tidak pernah jadi rute umroh,
     * DAN nol jejak tanah suci di seluruh teks slide itu. Sinyal kedua yang menahan
     * salah tangkap — umroh plus Turki/Dubai/Aqsa selalu menyebut Makkah atau
     * Madinah, dan "Japan Airlines"/"China Southern" di flyer umroh juga.
     * Yang TIDAK boleh masuk daftar tujuan: apa pun yang bisa jadi extension umroh
     * — Turki, Dubai, Kairo, Aqsa, Jordan, Petra, Andalusia, Taj Mahal. `petra`
     * sempat dicoba dan langsung salah tangkap: "PERJALANAN UMRAH ISTIMEWA — AQSHA
     * JORDAN PETRA, UMRAH PLUS AQSHA, 15 hari" tidak menyebut Makkah maupun Madinah
     * sekalipun, jadi sinyal kedua tidak menahannya. Kota domestik (Bali, Lombok)
     * juga tidak: itu kota keberangkatan, bukan tujuan.
     *
     * Terukur atas 604 baris paket: kena 9 — Korea, China, Seoul, Hongkong, New
     * Zealand, Uzbekistan — kesembilannya tur, nol salah tangkap. Sinyal kedua
     * tanpa pengecualian kata `umroh` kena 0: kop suratnya menutupi semuanya.
     *
     * Daftarnya memang perlu ditambah sesekali; yang tidak boleh berubah itu
     * bentuknya — tujuan yang tidak pernah jadi rute umroh, DAN nol jejak tanah suci.
     */
    private function bukanUmroh(array $d): bool
    {
        $teks = mb_strtolower(implode(' ', array_filter([
            $d['_flyer_text'] ?? '',
            $d['facilities_raw'] ?? '',
        ], 'is_string')));

        $tujuan = '/\b(korea|seoul|busan|jepang|japan|tokyo|osaka|kyoto|hokkaido|shirakawago'
            .'|china|tiongkok|beijing|shanghai|xian|terracotta|terracota'
            .'|nami island|everland|namsan|gyeongbok|lotte world'
            .'|hong ?kong|taiwan|bangkok|pattaya|thailand|vietnam|hanoi|danang'
            .'|uzbekistan|kazakh\w*|kyrgyz\w*|krygyz\w*|samarkand|bukhara|tashkent'
            .'|new zealand|auckland|aukland|queenstown|australia|sydney|melbourne'
            .'|eropa|europe|swiss|paris|london|amsterdam)\b/';

        $tanahSuci = '/(makkah|mekkah|mekah|makkatul|madinah|madinatul|masjidil ?haram|nabawi'
            ."|haramain|ka'?bah|tanah suci|miqat|thawaf|tawaf|multazam|raudhah)/";

        return preg_match($tujuan, $teks) === 1 && preg_match($tanahSuci, $teks) !== 1;
    }

    /**
     * Keberangkatan sebelum ambang: paketnya sudah lewat atau terlalu mepet.
     * Tanggal kosong tetap lolos — belum bisa dinilai, biar manusia yang lihat.
     */
    private function tooEarly(array $d): bool
    {
        $date = $this->tanggal($d);

        return $date !== null && $date < (string) config('umroh.min_departure');
    }

    /**
     * Eksekusi penolakan — setelah SEMUA file diimpor, bukan di tengah loop.
     *
     * Folder raw itu dipakai bersama satu carousel. Satu slide yang bukan penawaran
     * tidak boleh menyeret folder itu ke trash kalau slide lain dari post yang sama
     * jadi paket: `Package::flyers()` glob ke `storage/raw`, jadi paket saudaranya
     * langsung tampil tanpa gambar. Mengecualikan `media_id`-nya juga salah — itu
     * membanned seluruh post, termasuk slide yang laku. Aturan yang sama sudah
     * berlaku untuk tombol × di halaman review (`PackageSearchController::destroy`).
     *
     * Ditunda sampai loop selesai karena urutan file tidak dijamin: slide yang jadi
     * paket bisa saja diproses SESUDAH slide yang ditolak.
     *
     * @param  array<int, array{0: string, 1: array, 2: string}>  $tolak
     * @return int slide yang ditolak tapi postnya masih dipakai paket lain
     */
    private function buang(array $tolak): int
    {
        $buntu = 0;

        foreach ($tolak as [$file, $data, $alasan]) {
            if (Package::where('media_id', $data['_media_id'])->exists()) {
                // Post ini kadung dikecualikan di import sebelumnya — bisa terjadi
                // kalau import jalan saat carouselnya belum habis diekstrak. Cabut:
                // slide yang laku butuh postnya tetap bisa di-fetch ulang.
                DB::table('excluded_posts')->where('media_id', $data['_media_id'])->delete();
                $buntu++;

                continue;
            }

            ExcludedPost::add($data['_media_id'], $data['_source'] ?? null, $alasan);

            // Penghapusannya ditunda selama ekstraksi post ini mungkin masih jalan.
            // Antrian `ai` menulis slide carousel satu per satu, jadi import di
            // antrian `db` bisa membaca separuhnya: slide 1-2 ditolak -> folder raw
            // dihapus -> slide 3 yang menyusul jadi paket TANPA flyer (paket #375,
            // selisihnya 10 detik). Barisnya excluded_posts sudah menahan fetch &
            // extract ulang, jadi menunda hapusnya tidak membayar apa pun; import
            // berikutnya yang membereskan, dan saat itu slide yang laku sudah punya
            // barisnya sehingga cabang $buntu di atas yang menang.
            // `bukan_paket` itu vonis mesin, bukan vonis manusia — dan yang paling
            // sering salah: satu flyer promo yang kelewat gerbang vision hilang
            // selamanya begitu rawnya dibuang, padahal cuma promptnya yang meleset.
            // Filenya ditahan sampai operator menekan blokir di /accounts/{id}/posts;
            // itu yang memanggil prune-nya. Alasan lain tetap dibuang di sini.
            if ($alasan !== 'bukan_paket'
                && $this->option('prune')
                && time() - (int) @filemtime($file) >= self::PRUNE_GRACE) {
                $this->prune($file, $data);
            }
        }

        return $buntu;
    }

    /**
     * Hapus raw + hasil ekstraksi post yang ditolak. Yang menjaga post ini tidak
     * di-fetch & di-extract lagi itu baris `excluded_posts` (ditulis di `buang()`
     * tepat sebelum ini), bukan filenya — flyer post buangan cuma numpuk byte.
     */
    private function prune(string $file, array $d): void
    {
        $source = $d['_source'] ?? 'unknown';

        File::deleteDirectory(storage_path("raw/$source/{$d['_media_id']}"));
        File::delete($file);
    }

    private function importOne(array $d, int $offerIndex, int &$created, int &$merged, int &$sudahAda): void
    {
        // Identitas baris itu (media_id, flyer_index, offer_index), bukan dedup_key.
        // dedup_key ikut berubah kalau ekstraksi ulang membaca "Saudia" jadi "Saudia
        // Airlines" — cari lewat itu saja dan baris lamanya tidak ketemu, create()
        // menabrak UNIQUE, dan exception-nya membatalkan SISA backlog, bukan cuma
        // file ini. Barisnya sengaja tidak ditimpa: `status` hasil review manusia.
        $sudah = Package::where('media_id', $d['_media_id'])
            ->where('flyer_index', $this->flyerIndex($d))
            ->where('offer_index', $offerIndex)
            ->exists();

        if ($sudah) {
            $sudahAda++;

            return;
        }

        $key = Package::dedupKey(
            $this->tanggal($d),
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

        $package = Package::create([
            ...$this->prices($d),
            ...$this->hotels($d),
            'source_account' => $d['_source'] ?? null,
            'media_id' => $d['_media_id'],
            'flyer_index' => $this->flyerIndex($d),
            'offer_index' => $offerIndex,
            'source_permalink' => $d['_permalink'] ?? null,
            'source_posted_at' => $d['_posted_at'] ?? null,
            'departure_date' => $this->tanggal($d),
            'date_certainty' => Package::kepastian($d['departure_date'] ?? null, $d['date_certainty'] ?? null),
            'duration_days' => $d['duration_days'] ?? null,
            // Flyer paling sering tidak menulis kota berangkat karena default-nya
            // sudah dianggap tahu: Jakarta (CGK). Angka aslinya tetap di
            // raw_extraction, jadi asumsi ini bisa dicek ulang.
            'departure_city' => ($d['departure_city'] ?? null) ?: 'Jakarta',
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

        // Baris paket = vonis "ini beneran penawaran". Flyernya pindah dari
        // tempat singgah (storage/raw) ke disk `flyers`; slide lain dari carousel
        // yang sama tetap di raw sampai prune.
        $package->promoteFlyer();

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
