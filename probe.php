<?php

/**
 * Probe tool untuk langkah 1-3 Urutan Kerja.
 *
 *   php probe.php auth <short_lived_user_token>   tukar jadi IG_USER_ID + long-lived Page Token
 *   php probe.php fetch <username> [--limit=50]   ambil post + download media ke storage/
 *   php probe.php seed <dir>                      ingest caption manual (.txt) + flyer (.jpg)
 *   php probe.php extract [--limit=200] [--force] caption-first, vision kalau perlu
 *   php probe.php selftest                        cek logika gate + dedup
 *
 * `seed` ada supaya gate akurasi bisa diuji tanpa nunggu akses business_discovery.
 * Bentuk data yang dihasilkan identik dengan `fetch`, jadi extract-nya ga berubah.
 *
 * Tidak menyentuh Laravel: dipanggil oleh job lewat Process, dan satu-satunya
 * yang dibacanya dari DB adalah tabel excluded_posts (excludedIds(), read-only PDO).
 */

declare(strict_types=1);

// Carousel dikirim ke vision sebagai satu unit: tiap flyer di-base64 (+33%) lalu
// json_encode() menyalin seluruh payload jadi satu string lagi. Satu carousel 8
// gambar sudah menembus 128M default CLI ("Allowed memory size exhausted" di
// llmPost). Naikkan di sini, bukan di php.ini — job memanggil probe lewat Process.
ini_set('memory_limit', '512M');

const ROOT = __DIR__;
const RAW_DIR = ROOT.'/storage/raw';
const EXT_DIR = ROOT.'/storage/extracted';
const HASH_FILE = ROOT.'/storage/hashes.json';
const QUEUE_FILE = ROOT.'/storage/fetch_queue.json';
// Profil akun (bukan post): {username}.json + {username}.jpg. Bukan di storage/raw
// karena raw itu tempat singgah per-post dan ikut di-prune tiap import.
const PROFILE_DIR = ROOT.'/storage/profiles';

// Field yang harus terisi supaya sebuah post dihitung "berhasil dinormalisasi".
// Yang kosong masuk `_missing` -> `_needs_review` -> antrian review manusia.
const REQUIRED_FIELDS = ['departure_date', 'duration_days', 'departure_city', 'price_tiers'];

// Harga di bawah confidence ini wajib masuk review queue, ga pernah auto-publish.
const PRICE_CONFIDENCE_FLOOR = 0.8;

// ---------------------------------------------------------------- bootstrap

function env(string $key, ?string $default = null): ?string
{
    static $vars = null;
    if ($vars === null) {
        $vars = [];
        $path = ROOT.'/.env';
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $vars[trim($k)] = trim($v);
            }
        }
    }
    $value = $vars[$key] ?? getenv($key) ?: null;

    return ($value === null || $value === '') ? $default : $value;
}

/**
 * DSN read-only ke DB-nya Laravel, dirakit dari .env yang sama.
 *
 * @return array{0: ?string, 1: ?string, 2: ?string} [dsn, user, password]
 */
function dbDsn(): array
{
    if (env('DB_CONNECTION', 'sqlite') === 'mysql') {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            env('DB_HOST', '127.0.0.1'),
            env('DB_PORT', '3306'),
            env('DB_DATABASE', 'laravel'),
        );

        return [$dsn, env('DB_USERNAME'), env('DB_PASSWORD')];
    }

    $file = env('DB_DATABASE') ?: ROOT.'/database/database.sqlite';

    return [is_file($file) ? "sqlite:$file" : null, null, null];
}

/**
 * media_id yang sudah dikecualikan: bukan penawaran paket, keberangkatannya sudah
 * lewat, atau dibuang manual di halaman review. Jangan di-scrap lagi.
 *
 * Dibaca langsung dari DB-nya Laravel — cuma satu SELECT, tanpa boot framework.
 * Ikut DB_CONNECTION di .env (sqlite atau mysql); tabel belum ada / DB belum
 * dimigrasi = tidak ada yang dikecualikan.
 *
 * @return array<string, true>
 */
function excludedIds(): array
{
    static $ids = null;
    if ($ids !== null) {
        return $ids;
    }

    $ids = [];
    [$dsn, $user, $pass] = dbDsn();
    if ($dsn === null) {
        return $ids;
    }

    try {
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $rows = $pdo->query('SELECT media_id FROM excluded_posts')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $id) {
            $ids[(string) $id] = true;
        }
    } catch (Throwable $e) {
        out('  (daftar pengecualian tidak terbaca: '.$e->getMessage().')');
    }

    return $ids;
}

function need(string $key): string
{
    $value = env($key);
    if ($value === null) {
        fwrite(STDERR, "Isi $key di .env dulu (copy dari .env.example).\n");
        exit(1);
    }

    return $value;
}

function out(string $msg): void
{
    fwrite(STDOUT, $msg."\n");
}

// ------------------------------------------------------------------- schema

/** JSON Schema tolak `type: [x, null]`, jadi nullable pakai anyOf. */
function nullable(array $schema): array
{
    return ['anyOf' => [$schema, ['type' => 'null']]];
}

function extractionSchema(): array
{
    $tier = [
        'type' => 'object',
        'properties' => [
            'occupancy' => ['type' => 'string', 'enum' => ['quad', 'triple', 'double', 'single']],
            'amount' => ['type' => 'integer'],
            'currency' => ['type' => 'string', 'enum' => ['IDR', 'USD']],
            'is_starting_from' => ['type' => 'boolean'],
        ],
        'required' => ['occupancy', 'amount', 'currency', 'is_starting_from'],
        'additionalProperties' => false,
    ];

    $hotel = nullable([
        'type' => 'object',
        'properties' => [
            'raw_name' => ['type' => 'string'],
            'nights' => nullable(['type' => 'integer']),
        ],
        'required' => ['raw_name', 'nights'],
        'additionalProperties' => false,
    ]);

    // Satu keberangkatan dari flyer jadwal. Isinya cuma yang bisa berbeda per
    // baris; sisanya (PPIU, kota, pembimbing, fasilitas) diwarisi dari field
    // tingkat atas saat import — lihat ImportExtractedPackages::offers().
    $departure = [
        'type' => 'object',
        'properties' => [
            'departure_date' => nullable(['type' => 'string']),
            'date_certainty' => ['type' => 'string', 'enum' => ['exact', 'month', 'season', 'unknown']],
            'duration_days' => nullable(['type' => 'integer']),
            'price_tiers' => ['type' => 'array', 'items' => $tier],
            'airline' => nullable(['type' => 'string']),
            'extension' => ['type' => 'string', 'enum' => ['turki', 'dubai', 'aqsa', 'none', 'unknown']],
            'hotel_makkah' => $hotel,
            'hotel_madinah' => $hotel,
        ],
        'required' => [
            'departure_date', 'date_certainty', 'duration_days', 'price_tiers',
            'airline', 'extension', 'hotel_makkah', 'hotel_madinah',
        ],
        'additionalProperties' => false,
    ];

    $confidence = [
        'type' => 'object',
        'properties' => [
            'departure_date' => ['type' => 'number'],
            'price' => ['type' => 'number'],
            'hotels' => ['type' => 'number'],
            'ppiu' => ['type' => 'number'],
        ],
        'required' => ['departure_date', 'price', 'hotels', 'ppiu'],
        'additionalProperties' => false,
    ];

    return [
        'type' => 'object',
        'properties' => [
            // Diputuskan duluan: postingan travel mayoritas BUKAN penawaran paket.
            // Gemini cuma menyalin teks flyer; yang menilai konteks postingan ini.
            'post_kind' => ['type' => 'string', 'enum' => [
                'package_offer', 'hotel_info', 'education', 'testimonial', 'promo_generic', 'other',
            ]],
            'ppiu_name' => nullable(['type' => 'string']),
            'license_number' => nullable(['type' => 'string']),
            'departure_date' => nullable(['type' => 'string']),
            'date_certainty' => ['type' => 'string', 'enum' => ['exact', 'month', 'season', 'unknown']],
            'duration_days' => nullable(['type' => 'integer']),
            'departure_city' => nullable(['type' => 'string']),
            'airline' => nullable(['type' => 'string']),
            'guide_name' => nullable(['type' => 'string']),
            'extension' => ['type' => 'string', 'enum' => ['turki', 'dubai', 'aqsa', 'none', 'unknown']],
            'price_tiers' => ['type' => 'array', 'items' => $tier],
            // Semua keberangkatan yang ditawarkan gambar ini, termasuk yang sudah
            // ditaruh di field tingkat atas. Satu elemen = satu baris paket.
            'departures' => ['type' => 'array', 'items' => $departure],
            'hotel_makkah' => $hotel,
            'hotel_madinah' => $hotel,
            'facilities' => [
                'type' => 'array',
                'items' => [
                    'type' => 'string',
                    'enum' => ['visa', 'tiket', 'hotel', 'makan_3x', 'muthawif',
                        'perlengkapan', 'handling', 'city_tour', 'asuransi'],
                ],
            ],
            'facilities_raw' => nullable(['type' => 'string']),
            // Nomor gambar (sesuai pemisah di transkrip) yang memuat detail paket.
            // Slide dakwah teksnya bisa lebih panjang dari flyernya, jadi ini tidak
            // bisa ditebak dari panjang teks — harus dinilai maknanya.
            'detail_images' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'confidence' => $confidence,
        ],
        'required' => [
            'post_kind',
            'ppiu_name', 'license_number', 'departure_date', 'date_certainty', 'duration_days',
            'departure_city', 'airline', 'guide_name', 'extension', 'price_tiers', 'departures',
            'hotel_makkah', 'hotel_madinah', 'facilities', 'facilities_raw', 'detail_images', 'confidence',
        ],
        'additionalProperties' => false,
    ];
}

const SYSTEM_PROMPT = <<<'TXT'
Kamu mengekstrak data paket umroh dari postingan travel Indonesia menjadi JSON terstruktur.

Teks yang kamu terima bisa berasal dari transkrip gambar. Postingan bergambar sudah
dihakimi lebih dulu oleh model yang melihat flyernya — yang sampai ke kamu berarti
sudah lolos. Yang tanpa gambar belum dihakimi siapa pun, jadi `post_kind` tetap
tugasmu.

CAPTION DAN FLYER ITU SATU SUMBER, DIGABUNG:
Kamu menerima dua blok — "Caption postingan" dan "Teks yang terbaca di flyer".
Keduanya sama sahnya. Field yang tidak ada di flyer tapi ada di caption WAJIB
diisi dari caption, dan sebaliknya: caption sering memuat tanggal berangkat,
maskapai, fasilitas, atau kota yang tidak dicetak di flyer. Jangan pernah
mengosongkan field yang jawabannya cuma ada di salah satu blok.
Kalau keduanya bentrok soal angka yang sama (tanggal/harga beda), pakai flyer dan
turunkan `confidence` grup itu di bawah 0.8.

Tentukan `post_kind` dulu:
- `package_offer` — menjual keberangkatan konkret. Cirinya ada tanggal/bulan
  berangkat, atau harga, DITAMBAH komponen perjalanan (maskapai / hotel / durasi hari).
- `hotel_info`  — cuma mengenalkan atau mendaftar hotel: "Daftar Hotel dekat
  Masjidil Haram", perbandingan jarak, rekomendasi penginapan. Ada nama hotel
  BUKAN berarti ada paket.
- `education`   — tips, tata cara manasik, doa, konten dakwah, sejarah.
- `testimonial` — dokumentasi jamaah, pelepasan, ucapan terima kasih.
- `promo_generic` — ajakan tanpa detail keberangkatan: "hubungi kami", diskon
  tanpa paket, rekrutmen agen, giveaway.
- `other`       — sisanya (ucapan hari besar, lowongan, pengumuman kantor).

Kalau `post_kind` BUKAN `package_offer`, kosongkan SEMUA field data: semua null,
`price_tiers` [], `facilities` [], semua confidence 0. Jangan memanen nama hotel
atau maskapai dari postingan yang tidak menjual keberangkatan.

Poster yang cuma memajang nama-nama hotel, tanpa tanggal dan tanpa harga, itu
`hotel_info`. Poster yang cuma menampilkan logo maskapai tanpa keberangkatan
yang dijual juga bukan paket.

Aturan sisanya (hanya berlaku kalau `post_kind` = `package_offer`):
- Isi `null` untuk field yang tidak disebutkan. JANGAN menebak, jangan mengarang.

TANGGAL (`departure_date`) — format ketat, tidak ada bentuk lain yang diterima:
- "YYYY-MM-DD" kalau hari, bulan, dan tahunnya semua tertulis -> date_certainty `exact`.
- "YYYY-MM" kalau cuma bulan + tahun ("Maret 2027", "Awal April 2027") -> `month`.
- null kalau lebih kabur dari itu. Tahun saja ("Berangkat Tahun 2027"), musim
  ("musim liburan", "libur sekolah"), atau bulan Hijriah tanpa padanan Masehi
  ("Ramadhan 1448") -> departure_date null, date_certainty `season`/`unknown`.
  JANGAN mengarang tanggal 1, jangan menghitung sendiri konversi Hijriah.
- Tanggalnya rentang ("03 - 11 Nov 2026", "3 s/d 11 November") -> ambil yang
  PERTAMA (tanggal berangkat). Yang kedua itu tanggal pulang, bukan keberangkatan.
- Hari + bulan tanpa tahun ("14 Maret") -> pakai tahun dari "Tanggal posting" yang
  dilampirkan di bawah caption: ambil kejadian PERTAMA setelah tanggal posting itu.
  Tidak ada tanggal posting -> null.
- Satu flyer JADWAL memuat BANYAK keberangkatan sekaligus: tabel tanggal, daftar
  "Edisi Agustus/September/Oktober", atau beberapa program yang masing-masing punya
  harga sendiri. SEMUANYA dipanen ke `departures`, satu objek per keberangkatan,
  urut seperti di flyer. Satu baris tabel = satu objek `departures`.
- Yang paling menonjol (judul/paling besar/paling awal) ditaruh JUGA di field
  tingkat atas (`departure_date`, `duration_days`, `price_tiers`, dst) dan TETAP
  ikut sebagai salah satu elemen `departures` — jangan dihilangkan dari daftar.
- Flyer yang cuma menjual satu keberangkatan -> `departures` berisi satu objek itu.
- Di dalam `departures`, isi null (atau [] untuk `price_tiers`, `unknown` untuk
  `extension`) kalau keberangkatan itu tidak punya nilainya sendiri: itu berarti
  "sama dengan field tingkat atas". Yang dicetak per baris — tanggal, durasi,
  harga per tanggal, maskapai/hotel per program — WAJIB diisi di objeknya.
- Satu baris tabel yang menyebut beberapa tanggal sekaligus ("03 & 27 September",
  "03, 12 & 29 Oktober") = beberapa objek `departures` dengan durasi & harga sama.
- JANGAN mengarang tanggal untuk melengkapi tabel. Yang masuk `departures` cuma
  baris yang benar-benar tertulis di flyer atau caption.
- KEBERANGKATAN YANG SUDAH HABIS TIDAK DIJUAL — jangan dimasukkan ke `departures`
  sama sekali. Penandanya stempel/label di baris itu: "SOLD OUT", "SOLDOUT",
  "HABIS", "FULL", "FULLBOOK"/"FULL BOOKED", "CLOSED", "TUTUP", "WAITING LIST",
  "WL", "EXPIRED", atau harganya dicoret. Stempelnya sering ditempel MIRING di
  atas satu baris tabel dan cuma mengenai baris itu — baris lain di tabel yang
  sama tetap dipanen. Baris begitu juga tidak boleh jadi field tingkat atas:
  yang "paling menonjol" dihitung dari baris yang MASIH dijual, walaupun baris
  sold out ada di paling atas atau hurufnya paling besar.
- Semua barisnya sold out -> `departures` [], `departure_date` null,
  `price_tiers` [], `confidence.price` 0. Jangan dipaksa memilih satu.

HARGA (`price_tiers`) — cuma angka yang benar-benar tertulis sebagai harga paket:
- "25jt" / "25 juta" = 25000000, "25,9jt" = 25900000, "Rp 25.900.000" = 25900000.
- "mulai dari" / "start from" / "*" -> is_starting_from = true.
- USD ditulis apa adanya: "USD 3.300" -> amount 3300, currency "USD". JANGAN
  dikonversi sendiri ke rupiah — kursnya diurus di luar.
- Cocokkan tipe kamarnya: sekamar 4/berempat = quad, 3/bertiga = triple,
  2/berdua = double, sendiri = single. Tipe kamar tidak disebut tapi ada satu
  harga -> quad (harga dasar/termurah).
- BUKAN harga paket, jangan pernah dimasukkan: DP / uang muka / tanda jadi /
  booking fee, cicilan per bulan, sisa pelunasan, biaya tambahan (visa progresif,
  perlengkapan, handling), potongan diskon, dan harga paket LAIN yang cuma
  disebut sebagai pembanding.
- Angka yang bukan harga sama sekali: nomor telepon/WA, nomor izin PPIU, tahun,
  jumlah kursi/jamaah, jarak hotel ke masjid (meter), bintang hotel, nomor
  penerbangan. Jangan ada satu pun yang nyasar jadi amount.
- Cuma ada DP atau cicilan, harga penuhnya tidak tertulis -> price_tiers []
  (kosong), confidence.price 0. Jangan dikali-kali sendiri.
- price_tiers [] itu jawaban yang sah dan lebih baik daripada angka karangan:
  post tanpa harga memang tidak dipakai.
- `departure_city`: kota tempat jamaah BERANGKAT (embarkasi). "Berangkat dari
  Jakarta", "CGK"/"Soekarno-Hatta" -> "Jakarta"; "SUB"/"Juanda" -> "Surabaya".
  Kota tujuan (Jeddah/Madinah), kota kantor travel, dan asal pembimbing
  ("Ustadz dari Jakarta") BUKAN kota keberangkatan — kalau cuma itu yang ada,
  isi null.
- Kalau tipe kamar tidak disebut tapi ada satu harga, anggap `quad` (harga termurah/dasar).

HOTEL (`hotel_makkah` / `hotel_madinah`) — SATU FIELD = SATU HOTEL:
- Nama hotel disalin apa adanya ke `raw_name` ("setaraf Anjum" tetap ditulis penuh).
- JANGAN pernah menaruh dua nama hotel di satu `raw_name`. "Maysan Al Maqom /
  Dar Naeem" itu DUA hotel, bukan satu nama — pecah ke dua field.
- Kecuali keterangan "setaraf": "Nada Ajyad / Setaraf", "Sofwah atau setaraf",
  "Marwa Rotana / sekelas 5 star" itu SATU hotel plus catatan mutu — salin utuh
  ke satu field, jangan dianggap dua hotel dan jangan dibuang catatannya.
- Flyer paling sering cuma menulis "Hotel Pilihan" lalu dua nama tanpa label kota.
  Dua nama = satu Makkah, satu Madinah. Tentukan mana yang mana dari nama
  hotelnya sendiri: hotel di Madinah biasanya bernama Dar/Daar (Dar Naeem, Dar
  Al Iman), Saja, Frontel Al Harithia, Emaar Royal, Anwar Al Madinah Movenpick;
  di Makkah biasanya Maysan, Anjum, Jabal Omar, Swissotel, Fairmont, Hilton
  Suites, Al Kiswah, Grand Zamzam.
- Kalau kamu tidak yakin kota mana untuk nama-nama itu: nama PERTAMA -> Makkah,
  nama KEDUA -> Madinah (urutan tulis flyer Indonesia), dan `confidence.hotels`
  WAJIB di bawah 0.8 supaya masuk review manusia.
- Cuma SATU nama hotel disebut tanpa label kota -> taruh di `hotel_makkah`,
  `hotel_madinah` null, `confidence.hotels` di bawah 0.8.
- Ada label kotanya ("Makkah: …", "Madinah: …") -> ikut labelnya, jangan pakai
  urutan, dan confidence boleh tinggi.

FASILITAS:
- `facilities_raw`: SALIN SEMUA keunggulan/fasilitas yang disebut, dari flyer
  MAUPUN caption, apa adanya, dipisah koma. Termasuk yang tidak ada kodenya di
  `facilities`: "Direct Flight", "2x Jum'atan", "Bonus Menginap di Thaif",
  "Kereta Cepat Haramain", "Kajian Tematik", "Ticket Confirmed", "All In",
  "Terima beres". Ini yang dibaca calon jamaah — jangan diringkas, jangan
  dibuang cuma karena tidak masuk enum.
- `facilities` cuma kode dari enum yang benar-benar tertulis. "All In" saja tidak
  berarti semua kode boleh diisi — biarkan `facilities` pendek, `facilities_raw`
  yang panjang.
- `guide_name`: pembimbing/ustadz yang memimpin rombongan, kalau disebut. Salin apa
  adanya beserta gelarnya ("Ustadz Muhammad Nuzul Dzikri", "Ustadz H. Heri Suyadi, S.Kom").
  Ini pembimbing rombongan, bukan muthawif lokal dan bukan nama pemilik travel.
- `confidence` 0.0-1.0 per grup: seberapa yakin kamu, bukan seberapa lengkap datanya.
  Harga yang kamu baca dari gambar buram atau harus dihitung sendiri -> di bawah 0.8.
- `detail_images`: nomor gambar (dari pemisah "--- gambar N ---" di transkrip) yang
  benar-benar memuat detail paket — tanggal, harga, hotel, maskapai, durasi, itinerary.
  Slide dakwah, kutipan motivasi, salam pembuka, dan foto suasana TIDAK termasuk
  walaupun teksnya panjang. Carousel delapan gambar sering cuma satu yang flyer,
  tujuh sisanya cerita pengantar — jangan disamaratakan.
  Tidak ada transkrip -> []. Cuma satu gambar dan itu flyernya -> [1].
TXT;

// -------------------------------------------------------------------- fetch

function graphGet(string $url): array
{
    // Kuota kepakai dilaporkan Meta di header, bukan di body. Dicatat supaya
    // ambang `#4` itu angka terukur, bukan tebakan dari dokumentasi — dan supaya
    // kelihatan apakah tiga app benar-benar tiga kuota terpisah atau numpuk di
    // satu Page yang sama.
    $usage = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$usage) {
            [$name, $val] = array_pad(explode(':', $line, 2), 2, '');
            if (in_array(strtolower(trim($name)), ['x-app-usage', 'x-business-use-case-usage'], true)) {
                $usage[strtolower(trim($name))] = trim($val);
            }

            return strlen($line);
        },
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    foreach ($usage as $name => $val) {
        out("    $name: $val");
    }
    lastAppUsage(json_decode($usage['x-app-usage'] ?? '', true) ?: []);

    if ($body === false) {
        throw new RuntimeException('curl gagal');
    }
    $json = json_decode($body, true);
    if ($code !== 200) {
        $err = $json['error'] ?? [];
        $msg = $err['message'] ?? $body;
        // Kode Graph API yang sering muncul di jalur ini, diterjemahkan ke penyebab nyata.
        $hint = match ($err['code'] ?? 0) {
            10 => "Permission kurang. business_discovery butuh SEMUA ini:\n"
                 ."       instagram_basic, instagram_manage_insights, pages_show_list,\n"
                 ."       pages_read_engagement, business_management.\n"
                 .'       Yang paling sering kelewat: instagram_manage_insights.',
            110 => 'Username tidak ditemukan, atau akun target bukan Professional (Business/Creator).',
            190 => 'Token invalid/kedaluwarsa. Ambil token baru lalu jalankan: php probe.php auth <token>',
            4, 17, 32 => 'Kena rate limit tingkat app. Semua request numpuk di satu token — kasih jeda.',
            // Node di path-nya yang ditolak, bukan username targetnya: token slot ini
            // tidak bisa melihat IG_USER_ID yang dipasangkan dengannya. Slot dipilih
            // dari crc32(username), jadi gejalanya "sebagian akun gagal, sebagian
            // jalan" — bukan konfigurasi yang mati total.
            default => str_contains($msg, 'Invalid user id')
                ? 'IG_USER_ID tidak cocok dengan token di slot ini. Cek urutan IG_ACCESS_TOKEN vs '
                    ."IG_USER_ID, atau link Page-nya ke app itu lalu:\n"
                    .'       php probe.php auth <short_token> --app=<slot>'
                : null,
        };
        throw new RuntimeException("Graph API HTTP $code: $msg".($hint ? "\n       -> $hint" : ''));
    }

    return $json;
}

/**
 * Persen kuota app dari header `x-app-usage` request terakhir, sudah di-decode.
 *
 * Meta tidak pernah mengirim waktu reset untuk limit tingkat app:
 * `estimated_time_to_regain_access` cuma ada di `x-business-use-case-usage`, dan
 * header itu tidak pernah dikirim di jalur `business_discovery`. Jadi satu-satunya
 * cara tahu limitnya sudah keluar atau belum = tembak request lalu baca headernya.
 * Jendelanya bergulir 1 jam, jadi angkanya turun sendiri tanpa dipancing.
 *
 * @return array{call_count?: int, total_cputime?: int, total_time?: int}
 */
function lastAppUsage(?array $set = null): array
{
    static $usage = [];
    if ($set !== null) {
        $usage = $set;
    }

    return $usage;
}

/**
 * Cek kuota tiap app tanpa membakar kuota. Request-nya GET id akun sendiri: satu
 * field, tanpa ekspansi apa pun — yang mengikat itu `total_time`, dan request
 * sekecil ini nyaris nol dibanding `business_discovery` + `children{}`.
 *
 * App yang sedang kena `#4` tetap membalas headernya (403 + `x-app-usage`), jadi
 * angkanya terbaca justru saat paling dibutuhkan. >= 100 di salah satu dari tiga
 * angka = masih diblokir. Polling: `watch -n60 php probe.php quota`.
 */
function cmdQuota(array $argv): void
{
    $version = env('IG_GRAPH_VERSION', 'v25.0');
    $creds = igCreds();

    foreach ($creds as $i => $cred) {
        $url = sprintf(
            'https://graph.facebook.com/%s/%s?fields=id&access_token=%s',
            $version,
            $cred['user'],
            urlencode($cred['token']),
        );
        try {
            graphGet($url);
            $status = 'bebas';
        } catch (RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'rate limit')
                ? 'KENA LIMIT'
                : trim(strtok($e->getMessage(), "\n"));
        }
        $u = lastAppUsage();
        out(sprintf(
            'app %d/%d  call_count %s%%  cputime %s%%  total_time %s%%  -> %s',
            $i + 1,
            count($creds),
            $u['call_count'] ?? '?',
            $u['total_cputime'] ?? '?',
            $u['total_time'] ?? '?',
            $status,
        ));
    }
}

/**
 * Kredensial Graph API, sepasang per Meta App. `IG_ACCESS_TOKEN` dan `IG_USER_ID`
 * boleh berisi daftar JSON berkutip — `'["EAA…app1","EAA…app2"]'`. Rate limit `#4`
 * itu tingkat app, jadi dua app = dua kuota terpisah.
 *
 * @return list<array{user: string, token: string}>
 */
function igCreds(): array
{
    return igPair(envList(need('IG_USER_ID'), ''), envList(need('IG_ACCESS_TOKEN'), ''));
}

/**
 * Satu IG_USER_ID + banyak token = satu Page yang di-link ke beberapa app; itu
 * kasus paling umum, jadi user id-nya dipakai ulang. Selain itu jumlahnya wajib
 * sama banyak — salah pasang berarti token app A dikirim dengan id akun app B,
 * dan Graph membalasnya `#190`, bukan sesuatu yang kelihatan salah konfigurasi.
 *
 * @param  string[]  $users
 * @param  string[]  $tokens
 * @return list<array{user: string, token: string}>
 */
function igPair(array $users, array $tokens): array
{
    if (count($users) !== 1 && count($users) !== count($tokens)) {
        throw new RuntimeException(sprintf(
            'IG_USER_ID %d entri vs IG_ACCESS_TOKEN %d entri. Isi satu IG_USER_ID '
            .'(Page yang sama di beberapa app) atau sepasang per app, urutannya sama.',
            count($users),
            count($tokens),
        ));
    }

    return array_map(
        fn (int $i, string $token) => [
            'user' => $users[count($users) === 1 ? 0 : $i],
            'token' => $token,
        ],
        array_keys($tokens),
        $tokens,
    );
}

function cmdFetch(array $argv): void
{
    $username = $argv[2] ?? null;
    if ($username === null) {
        out('Usage: php probe.php fetch <username> [--limit=50]');
        exit(1);
    }
    $limit = (int) (optval($argv, 'limit') ?? 50);

    // Satu proses = satu akun (FetchAccount), jadi giliran tidak bisa dititip ke
    // variabel static seperti llmPostAny. Awalnya diambil dari nama akunnya:
    // tanpa state apa pun, akun yang berbeda mulai di app yang berbeda.
    $creds = igCreds();
    $c = crc32($username) % count($creds);
    $sisa = count($creds) - 1;
    $version = env('IG_GRAPH_VERSION', 'v25.0');

    // thumbnail_url dipakai untuk VIDEO/Reels — flyer sering diposting sebagai Reels,
    // dan media_url untuk video isinya mp4 yang ga bisa dikirim ke vision LLM.
    // like_count/comments_count dibuang: tidak pernah dibaca di mana pun, dan yang
    // mengikat kuota Graph itu total_time — field lebih sedikit = response lebih murah.
    $fields = 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp,'
        .'children{id,media_type,media_url,thumbnail_url}';

    $after = null;
    $count = 0;
    $skipped = 0;
    $scanned = 0;
    $excluded = excludedIds();

    out(sprintf('fetch @%s limit=%d (%d post dikecualikan, dilewat)', $username, $limit, count($excluded)));

    // ponytail: batas pindai supaya akun yang isinya dikecualikan semua tidak bikin
    // paginasi jalan sampai habis. Naikkan kalau backlognya memang panjang.
    $scanLimit = max(50, $limit * 3);

    // ponytail: page 25 ditolak HTTP 500 "reduce the amount of data" di akun yang
    // carousel-nya panjang — ekspansi children bikin response membengkak. Dibelah
    // dua sampai lolos; naikkan lagi kalau Graph melonggarkan batasnya.
    $page = 25;

    // Jeda sebelum SETIAP request ke Graph, termasuk yang pertama. Antrian `ig`
    // cuma satu worker, jadi satu proses = satu akun: jeda di sini sekaligus
    // memberi jarak antar-halaman dan antar-akun. Download gambar tidak ikut
    // dijeda — itu ke CDN scontent, bukan graph.facebook.com, kuotanya beda.
    $sleep = (float) (optval($argv, 'sleep') ?? env('IG_FETCH_SLEEP', '3'));

    while ($count < $limit && $scanned < $scanLimit) {
        if ($sleep > 0) {
            usleep((int) ($sleep * 1_000_000));
        }
        $mediaArgs = $after === null ? "media.limit($page)" : "media.after($after).limit($page)";
        // Profil cuma diminta di halaman pertama: nilainya sama di tiap halaman dan
        // yang mengikat kuota itu total_time. is_verified & tanggal bergabung tidak
        // ada di business_discovery (dijawab `#100 nonexisting field`), jangan dicari.
        $profileFields = $after === null
            ? 'name,followers_count,follows_count,media_count,profile_picture_url,'
            : '';
        $url = sprintf(
            'https://graph.facebook.com/%s/%s?fields=business_discovery.username(%s){%s%s{%s}}&access_token=%s',
            $version,
            $creds[$c]['user'],
            $username,
            $profileFields,
            $mediaArgs,
            $fields,
            urlencode($creds[$c]['token'])
        );

        out(sprintf(
            '  GET graph.facebook.com/%s business_discovery(@%s) %s%s',
            $version,
            $username,
            $after === null ? "media.limit($page)" : 'media.after('.substr($after, 0, 12)."…).limit($page)",
            count($creds) > 1 ? ' [app '.($c + 1).'/'.count($creds).']' : '',
        ));

        try {
            $res = graphGet($url);
        } catch (RuntimeException $e) {
            if ($page > 1 && str_contains($e->getMessage(), 'reduce the amount of data')) {
                $page = intdiv($page, 2);
                out("  response kegedean, ulangi dengan media.limit($page)");

                continue;
            }
            // Limitnya per app, bukan per akun: pindah app, ulangi halaman yang sama.
            // Kalau semua app habis, galatnya dilempar — FetchAccount yang menunggu.
            //
            // `Invalid user id` ikut memicu pindah dengan alasan yang sama bentuknya:
            // itu vonis atas pasangan (IG_USER_ID, token) di slot ini, bukan atas
            // username targetnya. Slotnya dipilih crc32(username), jadi satu slot
            // yang salah pasang bikin sebagian akun gagal selamanya sementara
            // sisanya jalan — dan itu gagal yang bisa diselamatkan app lain.
            $pindah = str_contains($e->getMessage(), 'rate limit')
                || str_contains($e->getMessage(), 'Invalid user id');
            if ($sisa > 0 && $pindah) {
                $c = ($c + 1) % count($creds);
                $sisa--;
                out('  '.strtok($e->getMessage(), "\n").' — pindah ke app '.($c + 1).'/'.count($creds));

                continue;
            }
            throw $e;
        }
        $bd = $res['business_discovery'] ?? null;
        if ($bd === null) {
            // Akun personal / bukan Professional -> ga terbaca sama sekali.
            throw new RuntimeException("@$username tidak terbaca. Pastikan akun Professional.");
        }

        if ($after === null) {
            saveProfile($username, $bd);
        }

        $items = $bd['media']['data'] ?? [];
        if ($items === []) {
            break;
        }

        foreach ($items as $post) {
            if ($count >= $limit) {
                break;
            }
            $scanned++;
            $id = (string) $post['id'];

            // Sudah dikecualikan: tidak ada gunanya di-download lagi, dan sengaja TIDAK
            // dihitung ke $limit — "9 post, 2 dikecualikan" berarti ambil 7 yang lain.
            if (isset($excluded[$id])) {
                $skipped++;
                out("  $id dilewat: sudah dikecualikan");

                continue;
            }
            // Sudah ada di disk: rawnya masih dipakai untuk flyer, jangan di-download ulang.
            if (is_file(RAW_DIR."/$username/$id/post.json")) {
                $skipped++;
                out("  $id dilewat: sudah ada di storage/raw");

                continue;
            }
            // VIDEO/REELS dan carousel yang isinya video semua: tidak ada flyer yang
            // bisa dicek mata, jadi jangan disimpan sama sekali. Kalau cuma download
            // gambarnya yang dilewat, caption-nya tetap masuk dan ikut diekstrak.
            if (imageUrlsOf($post) === []) {
                $skipped++;
                out("  $id dilewat: {$post['media_type']} tanpa gambar");

                continue;
            }

            out(sprintf('  %s %s %s', $id, $post['media_type'] ?? '?', substr((string) ($post['timestamp'] ?? '-'), 0, 10)));
            savePost($username, $post);
            $count++;
        }

        $after = $bd['media']['paging']['cursors']['after'] ?? null;
        if ($after === null) {
            break;
        }
    }

    out("@$username: $count post baru tersimpan di storage/raw/$username/"
        .($skipped > 0 ? " ($skipped dilewat, $scanned dipindai)" : ''));
}

/** "https://www.instagram.com/foo/?x=1" atau "@foo" -> "foo". */
function usernameOf(string $line): ?string
{
    $line = trim($line);
    if ($line === '' || $line[0] === '#') {
        return null;
    }
    if (str_contains($line, 'instagram.com')) {
        $line = parse_url($line, PHP_URL_PATH) ?? '';
    }
    $line = trim($line, "/@ \t");

    return preg_match('/^[A-Za-z0-9._]+$/', $line) ? $line : null;
}

/**
 * Antrian fetch untuk banyak akun. Statusnya di storage/fetch_queue.json, jadi
 * run yang mati di tengah tinggal dijalankan ulang tanpa argumen — yang `done`
 * dilewat. Sengaja sekuensial: rate limit Graph API itu tingkat app, semua
 * request numpuk di satu token, jadi paralel cuma bikin kena #4 lebih cepat.
 */
/**
 * Profil saja, tanpa media: `business_discovery` tanpa ekspansi `media{children{}}`.
 * Itu bagian yang mahal — yang mengikat kuota `total_time`, bukan jumlah request —
 * jadi mengisi profil 200 akun jauh lebih murah daripada men-scrap ulang semuanya.
 *
 * Tidak menyentuh storage/raw dan tidak mengubah apa pun soal post: cuma menulis
 * storage/profiles/{username}.json + .jpg, sama seperti halaman pertama `fetch`.
 */
function cmdProfile(array $argv): void
{
    $usernames = array_values(array_filter(
        array_slice($argv, 2),
        fn (string $arg) => ! str_starts_with($arg, '--'),
    ));
    if ($usernames === []) {
        out('Usage: php probe.php profile <username> [username…] [--sleep=3]');
        exit(1);
    }

    $creds = igCreds();
    $version = env('IG_GRAPH_VERSION', 'v25.0');
    $sleep = (float) (optval($argv, 'sleep') ?? env('IG_FETCH_SLEEP', '3'));
    $fields = 'name,followers_count,follows_count,media_count,profile_picture_url';

    $ok = 0;
    foreach ($usernames as $i => $username) {
        if ($sleep > 0 && $i > 0) {
            usleep((int) ($sleep * 1_000_000));
        }

        // Limitnya per app: kalau kena, coba app berikutnya untuk akun yang sama.
        $c = crc32($username) % count($creds);
        for ($try = 0; $try < count($creds); $try++, $c = ($c + 1) % count($creds)) {
            try {
                $res = graphGet(sprintf(
                    'https://graph.facebook.com/%s/%s?fields=business_discovery.username(%s){%s}&access_token=%s',
                    $version,
                    $creds[$c]['user'],
                    $username,
                    $fields,
                    urlencode($creds[$c]['token']),
                ));
            } catch (RuntimeException $e) {
                if ($try + 1 < count($creds) && str_contains($e->getMessage(), 'rate limit')) {
                    continue;
                }
                // Satu akun mati (username salah / bukan Professional) tidak boleh
                // menghentikan sisa daftarnya — 196 akun, satu proses.
                out('  gagal @'.$username.': '.strtok($e->getMessage(), "\n"));
                break;
            }

            if (($bd = $res['business_discovery'] ?? null) === null) {
                out("  gagal @$username: tidak terbaca, pastikan akun Professional");
                break;
            }
            saveProfile($username, $bd);
            $ok++;
            break;
        }
    }

    out(sprintf('profil: %d/%d akun tersimpan di storage/profiles/', $ok, count($usernames)));
}

function cmdFetchAll(array $argv): void
{
    $limit = (int) (optval($argv, 'limit') ?? 50);
    $sleep = (int) (optval($argv, 'sleep') ?? 3);
    $cool = (int) (optval($argv, 'cooldown') ?? 300);

    $queue = is_file(QUEUE_FILE)
        ? (json_decode((string) file_get_contents(QUEUE_FILE), true) ?: [])
        : [];

    // File daftar akun opsional: tanpa itu, lanjutkan antrian yang sudah ada.
    // Argumen ber-prefix "--" itu flag, bukan nama file.
    $file = ($argv[2] ?? null) !== null && ! str_starts_with($argv[2], '--') ? $argv[2] : null;
    if ($file !== null) {
        if (! is_file($file)) {
            out("File $file tidak ada. Isi satu username/URL per baris.");
            exit(1);
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            if (($u = usernameOf($line)) && ! isset($queue[$u])) {
                $queue[$u] = ['status' => 'pending', 'error' => null];
            }
        }
    }
    if (in_array(optval($argv, 'retry'), ['', '1', 'true'], true) || in_array('--retry', $argv, true)) {
        foreach ($queue as $u => $row) {
            if ($row['status'] === 'failed') {
                $queue[$u] = ['status' => 'pending', 'error' => null];
            }
        }
    }

    $save = function () use (&$queue) {
        file_put_contents(QUEUE_FILE, json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    };
    $save();

    $pending = array_keys(array_filter($queue, fn ($r) => $r['status'] === 'pending'));
    $total = count($queue);
    out(count($pending)." pending dari $total akun.");

    $i = 0;
    foreach ($pending as $username) {
        $i++;
        out(sprintf('[%d/%d] @%s', $i, count($pending), $username));

        // Rate limit dapat satu kali cooldown lalu dicoba ulang; sisanya failed.
        for ($attempt = 1; ; $attempt++) {
            try {
                cmdFetch(['probe.php', 'fetch', $username, "--limit=$limit"]);
                $queue[$username] = ['status' => 'done', 'error' => null];
                break;
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                if (str_contains($msg, 'rate limit') && $attempt < 3) {
                    out("       rate limit, tunggu {$cool}s lalu coba lagi...");
                    sleep($cool);

                    continue;
                }
                // Token mati bukan masalah per-akun: sisa antrian pasti gagal juga.
                if (str_contains($msg, 'Token invalid')) {
                    $save();
                    throw $e;
                }
                out("       GAGAL: $msg");
                $queue[$username] = ['status' => 'failed', 'error' => $msg];
                break;
            }
        }

        $save();
        if ($i < count($pending)) {
            sleep($sleep);
        }
    }

    $by = array_count_values(array_column($queue, 'status'));
    out(sprintf(
        'Selesai. done=%d failed=%d pending=%d — ulangi yang gagal: php probe.php fetchall --retry',
        $by['done'] ?? 0,
        $by['failed'] ?? 0,
        $by['pending'] ?? 0
    ));
}

/**
 * URL gambar untuk satu node media. Hanya IMAGE yang diambil.
 *
 * VIDEO/REELS sengaja dilewat walau `thumbnail_url` tersedia: thumbnail Reels
 * itu satu frame yang belum tentu memuat flyer, jadi rawan menghasilkan
 * ekstraksi ngawur. Di carousel campuran, child video dibuang dan child gambar
 * tetap diambil; carousel yang isinya video semua otomatis tidak menghasilkan
 * gambar sama sekali.
 */
function imageUrlOf(array $media): ?string
{
    return ($media['media_type'] ?? '') === 'IMAGE'
        ? ($media['media_url'] ?? null)
        : null;
}

/**
 * Semua URL gambar dari satu post. Carousel diambil child bertipe IMAGE saja;
 * VIDEO/REELS dan carousel yang isinya video semua menghasilkan array kosong.
 */
function imageUrlsOf(array $post): array
{
    if (($post['media_type'] ?? '') === 'CAROUSEL_ALBUM') {
        return array_values(array_filter(array_map('imageUrlOf', $post['children']['data'] ?? [])));
    }

    return ($url = imageUrlOf($post)) ? [$url] : [];
}

/** media_url = signed CDN URL yang expire. Download sekarang, jangan simpan URL-nya. */
/**
 * Profil akun -> storage/profiles/{username}.json (+ .jpg).
 *
 * Foto profilnya di-download, bukan disimpan URL-nya: `profile_picture_url` itu
 * signed CDN scontent yang mati dalam hitungan hari — aturan yang sama dengan
 * `media_url`. Ukurannya belasan KB per akun, sekali per scrap.
 */
function saveProfile(string $username, array $bd): void
{
    @mkdir(PROFILE_DIR, 0775, true);

    $profile = [
        'username' => $username,
        'full_name' => $bd['name'] ?? null,
        'followers_count' => $bd['followers_count'] ?? null,
        'follows_count' => $bd['follows_count'] ?? null,
        'media_count' => $bd['media_count'] ?? null,
        'fetched_at' => date('c'),
    ];
    file_put_contents(PROFILE_DIR."/$username.json", json_encode($profile, JSON_PRETTY_PRINT));

    out(sprintf(
        '  profil @%s: %s pengikut, %s diikuti, %s post di IG',
        $username,
        $profile['followers_count'] ?? '?',
        $profile['follows_count'] ?? '?',
        $profile['media_count'] ?? '?',
    ));

    if (($url = $bd['profile_picture_url'] ?? null) && ($bytes = @file_get_contents($url)) !== false) {
        file_put_contents(PROFILE_DIR."/$username.jpg", $bytes);
        out(sprintf('    GET foto profil -> storage/profiles/%s.jpg (%.1f KB)', $username, strlen($bytes) / 1024));
    }
}

function savePost(string $username, array $post): void
{
    $dir = RAW_DIR."/$username/".$post['id'];
    @mkdir($dir, 0775, true);

    // Jalur seed: flyer sudah ada di disk, ga perlu di-download.
    $urls = array_merge(imageUrlsOf($post), $post['_local'] ?? []);
    unset($post['_local']);

    $files = [];
    foreach ($urls as $i => $url) {
        $bytes = @file_get_contents($url);
        if ($bytes === false) {
            out(sprintf('    gagal download gambar %d dari %s', $i, parse_url($url, PHP_URL_HOST) ?: '?'));

            continue;
        }
        $path = "$dir/$i.jpg";
        file_put_contents($path, $bytes);
        $files[] = basename($path);
        out(sprintf(
            '    GET %s -> storage/raw/%s/%s/%d.jpg (%.1f KB)',
            parse_url($url, PHP_URL_HOST) ?: '?',
            $username,
            $post['id'],
            $i,
            strlen($bytes) / 1024,
        ));
    }

    // Raw disimpan terpisah dari hasil ekstraksi -> bisa re-extract tanpa crawl ulang.
    $post['_source_account'] = $username;
    $post['_images'] = $files;
    $post['_fetched_at'] = date('c');
    file_put_contents("$dir/post.json", json_encode($post, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// --------------------------------------------------------------------- auth

/**
 * Tukar short-lived User Token (dari Graph API Explorer) jadi kredensial siap pakai.
 * Cetak IG Business Account ID + long-lived Page Token buat ditempel ke .env.
 *
 * Token yang benar diawali "EAA". Kalau punya lo diawali "IGAA", itu Business Login
 * for Instagram — salah produk, business_discovery ga akan ada.
 */
/**
 * Kredensial app buat menukar token. META_APP_ID/META_APP_SECRET boleh berisi
 * daftar JSON berkutip seperti IG_ACCESS_TOKEN, urutannya sama — jadi `--app=N`
 * menukar token untuk slot ke-N di IG_ACCESS_TOKEN. Jumlahnya wajib sepadan:
 * id app A dipasang dengan secret app B cuma kelihatan sebagai galat OAuth.
 *
 * @param  string[]  $ids
 * @param  string[]  $secrets
 * @return array{0: string, 1: string}
 */
function metaApp(array $ids, array $secrets, int $slot): array
{
    if (count($ids) !== count($secrets)) {
        throw new RuntimeException(sprintf(
            'META_APP_ID %d entri vs META_APP_SECRET %d entri. Isi sepasang per app, urutannya sama.',
            count($ids),
            count($secrets),
        ));
    }
    if (! isset($ids[$slot])) {
        throw new RuntimeException(sprintf(
            '--app=%d di luar daftar: META_APP_ID cuma punya %d entri (slot 0..%d).',
            $slot,
            count($ids),
            count($ids) - 1,
        ));
    }

    return [$ids[$slot], $secrets[$slot]];
}

function cmdAuth(array $argv): void
{
    $short = $argv[2] ?? null;
    if ($short === null) {
        out('Usage: php probe.php auth <short_lived_user_token> [--app=0]');
        exit(1);
    }
    if (str_starts_with($short, 'IGAA')) {
        throw new RuntimeException(
            "Token diawali IGAA = Business Login for Instagram. Tidak punya business_discovery.\n"
            .'       Butuh User Token dari Meta App (produk "Instagram API with Facebook Login"), diawali EAA.'
        );
    }

    $slot = (int) (optval($argv, 'app') ?? 0);
    [$appId, $secret] = metaApp(
        envList(need('META_APP_ID'), ''),
        envList(need('META_APP_SECRET'), ''),
        $slot,
    );
    out("App slot $slot: $appId");

    $version = env('IG_GRAPH_VERSION', 'v25.0');
    $base = "https://graph.facebook.com/$version";

    // 1. Short-lived -> long-lived user token (60 hari).
    $res = graphGet($base.'/oauth/access_token?'.http_build_query([
        'grant_type' => 'fb_exchange_token',
        'client_id' => $appId,
        'client_secret' => $secret,
        'fb_exchange_token' => $short,
    ]));
    $longUser = $res['access_token'];

    // 2. Page + IG Business Account yang ter-link.
    $pages = graphGet($base.'/me/accounts?'.http_build_query([
        'fields' => 'id,name,access_token,instagram_business_account{id,username}',
        'access_token' => $longUser,
    ]));

    $found = false;
    foreach ($pages['data'] ?? [] as $page) {
        $ig = $page['instagram_business_account'] ?? null;
        out(str_repeat('-', 46));
        out('Page          : '.$page['name'].' ('.$page['id'].')');
        if ($ig === null) {
            out('IG account    : BELUM ter-link. Link dari Meta Business Suite dulu.');

            continue;
        }
        $found = true;
        out('IG account    : @'.$ig['username']);
        out('');
        out('Tempel ke .env:');
        out('  IG_USER_ID='.$ig['id']);
        // Page token turunan long-lived user token tidak expire selama izin tidak dicabut.
        out("  IG_ACCESS_TOKEN slot $slot = ".$page['access_token']);
    }
    out(str_repeat('-', 46));

    if (! $found) {
        throw new RuntimeException('Tidak ada Page dengan IG Business Account ter-link.');
    }
    out('Lalu tes: php probe.php fetch <username_travel_target> --limit=5');
}

// --------------------------------------------------------------------- seed

/**
 * Ingest manual: satu paket = satu <nama>.txt (caption) + opsional <nama>.jpg / <nama>-*.jpg (flyer).
 * Dipakai untuk mengukur akurasi ekstraksi sebelum akses Instagram beres.
 */
function cmdSeed(array $argv): void
{
    $src = $argv[2] ?? null;
    if ($src === null || ! is_dir($src)) {
        out('Usage: php probe.php seed <dir berisi .txt (+ .jpg opsional)>');
        exit(1);
    }

    $count = 0;
    foreach (glob(rtrim($src, '/').'/*.txt') ?: [] as $txt) {
        $slug = pathinfo($txt, PATHINFO_FILENAME);
        $id = 'manual_'.preg_replace('/[^a-z0-9_-]/i', '_', $slug);

        $flyers = array_merge(
            glob("$src/$slug.jpg") ?: [],
            glob("$src/$slug-*.jpg") ?: []
        );

        savePost('manual', [
            'id' => $id,
            'caption' => file_get_contents($txt),
            'media_type' => $flyers === [] ? 'IMAGE' : 'CAROUSEL_ALBUM',
            'permalink' => null,
            'timestamp' => null,
            '_local' => $flyers,
        ]);
        $count++;
    }

    out("$count paket manual masuk ke storage/raw/manual/. Lanjut: php probe.php extract");
}

// ------------------------------------------------------------------ extract

function cmdExtract(array $argv): void
{
    $limit = (int) (optval($argv, 'limit') ?? 200);
    $force = in_array('--force', $argv, true);
    // --only=<media_id>: ekstrak ulang satu post saja, buat ngetes perubahan prompt.
    $only = optval($argv, 'only');
    // --no-gate: gerbang vision dilewati. Buat tombol "baca ulang AI" di halaman post —
    // operator sudah melihat flyernya sendiri dan menilai vonis vision salah.
    $noGate = in_array('--no-gate', $argv, true);
    $models = envList(env('EXTRACT_MODEL'), 'logic-model');
    // Daftar satu model = tidak ada tempat pindah saat routernya menggantung, dan
    // llmPost cuma mengulang ke pintu yang sama: dua timeout = postnya hilang.
    // Nama combo (tanpa prefix provider) dikecualikan: gilirannya di router.
    $sendirian = fn (array $l) => count($l) < 2 && str_contains($l[0] ?? '', '/');
    $sendirian($models) && out('  ! EXTRACT_MODEL cuma 1 model — satu router yang menggantung membuang seluruh post');
    $sendirian(envList(env('VISION_MODEL'), 'image-model')) && out('  ! VISION_MODEL cuma 1 model — satu router yang menggantung membuang seluruh post');

    @mkdir(EXT_DIR, 0775, true);

    $done = 0;
    $visionCalls = 0;
    $ditolak = 0;
    $excluded = excludedIds();

    foreach (glob(RAW_DIR.'/*/*/post.json') ?: [] as $postFile) {
        if ($done >= $limit) {
            break;
        }
        // Antrian db (prune / tombol ×) menghapus folder raw sementara
        // antrian ai masih menyusuri hasil glob-nya — folder yang lenyap di tengah
        // jalan bukan galat, cukup dilewat. Sama seperti claimImages().
        $json = @file_get_contents($postFile);
        if ($json === false) {
            continue;
        }
        $post = json_decode($json, true);
        if ($only !== null && $post['id'] !== $only) {
            continue;
        }
        // Satu post bisa menghasilkan beberapa file (carousel dipecah per gambar),
        // jadi "sudah pernah diekstrak" dicek dengan pola, bukan satu nama file.
        //
        // `--only` melewati saringan ini tapi TIDAK memaksa slide-nya ditulis ulang
        // (dulu `--only` mengimplikasikan `--force`). Bedanya kelihatan justru saat
        // gagal: ExtractPost punya batas 570 detik, dan satu carousel 12 slide bisa
        // menembusnya — slide yang kadung ditulis membuat retry-nya mulai lagi dari
        // nol, menembus batas yang sama, dan begitu terus sampai `tries` habis.
        // Sekarang retry-nya melanjutkan sisanya. Jalur "baca ulang" tetap menulis
        // ulang semuanya karena `bacaUlang()` menghapus file lamanya lebih dulu;
        // dari CLI, pakai `--force` kalau memang mau membayar model lagi.
        if (! $force && $only === null && glob(EXT_DIR.'/'.$post['id'].'{.json,-*.json}', GLOB_BRACE)) {
            continue;
        }
        if (isset($excluded[(string) $post['id']])) {
            out("  {$post['id']} dilewat: sudah dikecualikan");

            continue;
        }
        // Usulan yang belum di-approve admin: rawnya sudah ada, tapi belum boleh
        // dibayar ke model. `--only` lolos — itu jalur tombol "setujui", dan
        // penandanya sudah dibuang sebelum job-nya dilempar.
        if ($only === null && isset($post['_suggested_by'])) {
            out("  {$post['id']} dilewat: usulan menunggu approval");

            continue;
        }

        $dir = dirname($postFile);
        $images = [];
        $sent = [];   // nama file per gambar yang dikirim, urutannya = urutan di prompt
        foreach (claimImages($dir, $post) as $name => $bytes) {
            $images[] = base64_encode($bytes);
            $sent[] = $name;
        }

        $caption = trim((string) ($post['caption'] ?? ''));
        out(sprintf(
            'extract %s (@%s) %d gambar, caption %d char',
            $post['id'],
            $post['_source_account'] ?? '?',
            count($images),
            strlen($caption),
        ));

        // Pass 0 — pra-gerbang caption. Satu call teks (murah) buat menyaring post yang
        // captionnya SENDIRI sudah mengaku bukan penawaran: dokumentasi keberangkatan,
        // manasik, ucapan hari besar. Vision (gambar base64, model termahal di pipeline)
        // tidak pernah dipanggil untuk post begitu. Sengaja asimetris — cuma boleh
        // menolak, tidak pernah meloloskan: caption travel sering cuma "chat admin"
        // padahal flyernya paket beneran, jadi ragu = lanjut ke vision.
        if (! $noGate && $images !== [] && strlen($caption) >= CAPTION_GATE_MIN) {
            $kind = readCaption($models, $caption);
            if ($kind !== null) {
                out("  caption: $kind — vision dilewat");
                $data = ['post_kind' => $kind, '_rejected_by' => 'caption'];
                writeExtraction(EXT_DIR.'/'.$post['id'].'.json', $data, $post, '', null);
                $ditolak++;
                $done++;

                continue;
            }
        }

        // Pass 1 — gerbang. Vision melihat flyer, menyalin teksnya per gambar, dan
        // memutuskan ini penawaran paket atau bukan. Dijalankan lebih dulu (bukan cuma
        // kalau field kosong) supaya mayoritas post yang bukan paket berhenti di sini
        // dan tidak pernah dibayar ke penyusun.
        $verdict = null;
        if ($images !== []) {
            $verdict = readFlyer($images, $caption);   // carousel = satu call, hasilnya per slide
            $visionCalls++;
            out(sprintf(
                '  vision: post_kind=%s, %d/%d gambar berisi penawaran',
                $verdict['post_kind'],
                count(array_filter($verdict['slides'], fn ($s) => $s['is_offer'])),
                count($verdict['slides']),
            ));
        }

        // Balasan vision yang tidak terbaca (JSON rusak/terpotong, `slides` kosong,
        // entri tanpa `n`) BUKAN vonis. jsonOf() balik [] diam-diam, dan tanpa
        // saringan ini visionVerdict([]) membacanya jadi post_kind=other -> import
        // mengecualikan postnya selamanya + rawnya dihapus, padahal modelnya cuma
        // gagal menjawab. Terukur 2026-07-31: 46 dari ~200 ekstraksi kena begini.
        // Tidak ditulis apa-apa, jadi post-nya tetap di raw dan extract berikutnya
        // mencobanya lagi — gratis, karena gerbangnya belum sempat memutuskan.
        if ($verdict !== null && $verdict['slides'] === []) {
            out('  vision: balasan tidak terbaca (0 slide) — tidak divonis, dicoba lagi nanti');

            continue;
        }

        if (! $noGate && $verdict !== null && $verdict['post_kind'] !== 'package_offer') {
            // Ditolak di gerbang: simpan alasan + transkripnya saja. Penyusun tidak
            // dipanggil, dan `packages:import --prune` yang mengecualikan + memindahkannya.
            $data = ['post_kind' => $verdict['post_kind'], '_rejected_by' => 'vision'];
            writeExtraction(EXT_DIR.'/'.$post['id'].'.json', $data, $post, $verdict['flyer_text'], null);
            $ditolak++;
            $done++;

            continue;
        }

        // Pass 2 — penyusun. Caption + teks flyer -> JSON. Tidak pernah lihat gambar.
        //
        // Satu carousel bisa memuat beberapa paket yang berbeda (gambar 1 Ramadhan,
        // gambar 2 Syawal, ...), jadi tiap gambar penawaran disusun SENDIRI dan jadi
        // barisnya sendiri. Gambar yang bukan penawaran (slide dakwah, foto suasana)
        // tidak pernah dikirim ke penyusun.
        $offers = array_values(array_filter($verdict['slides'] ?? [], fn ($s) => $s['is_offer']));

        // Post tanpa gambar (jalur seed) lewat tanpa gerbang: caption saja.
        if ($verdict === null) {
            $offers = [['n' => 0, 'text' => '', 'is_offer' => true]];
        }

        // --no-gate dan tidak ada satu pun gambar yang dinilai penawaran: transkrip
        // semua gambar digabung jadi SATU penyusunan bareng caption, bukan satu baris
        // per gambar. Slide promo biasanya memuat potongan info yang sama — dipecah
        // per gambar hasilnya beberapa baris kembar dari satu paket.
        if ($offers === [] && $noGate && $verdict !== null) {
            $offers = [[
                'n' => 1,
                'text' => trim(implode("\n", array_column($verdict['slides'], 'text'))),
                'is_offer' => true,
            ]];
            out('  gerbang dilewati (--no-gate): transkrip '.count($verdict['slides']).' gambar + caption -> 1 penyusunan');
        }

        foreach ($offers as $offer) {
            $file = slideFile($sent, $offer['n']);
            $label = count($offers) > 1 ? "{$post['id']}-{$offer['n']}" : $post['id'];

            // Slide yang sudah punya hasil dilewat: itu yang bikin job yang kena
            // timeout bisa dilanjutkan, bukan diulang dari nol. Vision-nya tetap
            // dibayar lagi (transkripnya tidak disimpan), tapi bagian yang paling
            // lama — satu panggilan penyusun per slide — tidak.
            if (! $force && is_file(EXT_DIR."/$label.json")) {
                out("  $label dilewat: sudah ada hasilnya");

                continue;
            }

            out(sprintf(
                '  POST %s model=%s slide %s (%d char) -> JSON',
                parse_url(routerUrl(), PHP_URL_HOST),
                implode('|', $models),
                $offer['n'] ?: '-',
                strlen($offer['text']),
            ));

            // Satu slide yang gagal jangan menjatuhkan slide lain di post yang sama.
            // Flyer jadwal bisa 11 gambar penawaran; sampai 2026-07-31 exception di
            // slide ke-3 membuang delapan sisanya — dan file slide 1-2 yang kadung
            // ditulis bikin extract berikutnya melewati postnya, jadi kehilangannya
            // permanen. Yang gagal diambil lagi lewat tombol "baca ulang" per kartu.
            try {
                $data = callExtractor($models, $caption, $offer['text'], $post['timestamp'] ?? null);
            } catch (RuntimeException $e) {
                out("  $label GAGAL: ".$e->getMessage());

                continue;
            }
            $data = writeExtraction(
                EXT_DIR."/$label.json",
                $data,
                $post,
                $offer['text'] ?: ($verdict['flyer_text'] ?? ''),
                $file,
            );
            $done++;

            out(sprintf('  %-24s %s%s', $label, $data['_missing'] === []
                ? 'OK'
                : 'kurang: '.implode(',', $data['_missing']),
                count($data['departures'] ?? []) > 1
                    ? ' · '.count($data['departures']).' keberangkatan'
                    : ''));
        }
    }

    out("\n$done hasil ditulis ($visionCalls vision, $ditolak ditolak di gerbang).");
}

/**
 * Nomor gambar dari vision -> nama file flyernya.
 *
 * Nomor N menunjuk gambar ke-N yang DIKIRIM ke vision, bukan file ke-N di folder:
 * gambar yang hash-nya sudah pernah diproses tidak ikut dikirim, jadi nomornya
 * bisa bergeser. Nomor ngawur -> null, barisnya tampil tanpa flyer.
 *
 * @param  array<int, string>  $sent
 */
function slideFile(array $sent, int $n): ?string
{
    return $n > 0 ? ($sent[$n - 1] ?? null) : null;
}

/**
 * Gambar post yang belum pernah diproses, sekalian mencatat hashnya.
 *
 * Flyer rebranding dipakai ulang oleh puluhan akun agen — hash yang sudah pernah
 * dilihat tidak dikirim lagi ke vision. Read-modify-write-nya dikunci flock:
 * beberapa worker extract jalan barengan, dan tanpa lock catatan hash saling
 * ketiban sehingga flyer yang sama dibayar dua kali.
 *
 * @return array<string, string> nama file => bytes
 */
function claimImages(string $dir, array $post): array
{
    $names = $post['_images'] ?? [];
    if ($names === []) {
        return [];
    }

    $fh = fopen(HASH_FILE, 'c+');
    flock($fh, LOCK_EX);

    $raw = stream_get_contents($fh);
    $hashes = $raw === '' ? [] : (json_decode($raw, true) ?: []);

    $keep = [];
    foreach ($names as $name) {
        $bytes = @file_get_contents("$dir/$name");
        if ($bytes === false) {
            continue;
        }
        $hash = hash('sha256', $bytes);
        if (isset($hashes[$hash]) && $hashes[$hash] !== $post['id']) {
            out("    $name dilewat: flyer identik sudah diproses di {$hashes[$hash]}");

            continue;
        }
        $hashes[$hash] = $post['id'];
        $keep[$name] = $bytes;
    }

    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($hashes));
    flock($fh, LOCK_UN);
    fclose($fh);

    return $keep;
}

/**
 * Tempel metadata jejak lalu tulis satu file hasil ekstraksi.
 *
 * $flyerFile = gambar yang jadi sumber baris ini (null kalau caption-only). Yang
 * dipajang ke publik cuma gambar itu, bukan seluruh carousel.
 *
 * @return array<string, mixed>
 */
function writeExtraction(string $target, array $data, array $post, string $flyerText, ?string $flyerFile): array
{
    $data['_media_id'] = $post['id'];
    $data['_permalink'] = $post['permalink'] ?? null;
    $data['_source'] = $post['_source_account'];
    $data['_posted_at'] = $post['timestamp'] ?? null;
    // Transkrip ikut disimpan: kalau harga meleset, ketahuan salahnya di mata (vision)
    // atau di penyusun (teks), tanpa perlu panggil ulang.
    $data['_flyer_text'] = $flyerText ?: null;
    $data['_used_vision'] = $flyerText !== '';
    $data['_useful_images'] = $flyerFile !== null ? [$flyerFile] : [];
    $data['_missing'] = missingFields($data);
    $data['_needs_review'] = $data['_missing'] !== []
        || ($data['confidence']['price'] ?? 0) < PRICE_CONFIDENCE_FLOOR;

    file_put_contents($target, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    out('    tulis storage/extracted/'.basename($target));

    return $data;
}

/**
 * Caption sependek ini tidak pernah memuat bukti yang cukup untuk menolak — "Info
 * lengkap chat admin 🕋" itu caption paket maupun caption dakwah. Langsung ke vision.
 *
 * ponytail: satu angka, bukan env. Turunkan kalau mau hemat lebih agresif; naikkan
 * kalau ada flyer paket yang kejaring pra-gerbang.
 */
const CAPTION_GATE_MIN = 200;

const CAPTION_GATE_PROMPT = <<<'TXT'
Kamu penjaga gerbang MURAH sebelum flyernya dilihat model vision. Kamu HANYA
melihat caption — gambarnya tidak dikirim ke kamu, dan kamu tidak boleh menebak
isinya.

Tugasmu satu: apakah caption ini SENDIRI sudah membuktikan postingannya BUKAN
penawaran paket umroh?

Default jawabanmu `lanjut`. Menolak itu pengecualian, bukan kebiasaan: travel
sering memposting flyer paket lengkap dengan caption yang cuma emoji, doa, atau
"info lengkap chat admin" — harga dan tanggalnya ada di gambar, dan gambar itu
tidak kamu lihat. Caption yang tidak memuat detail paket BUKAN alasan menolak.

Tolak HANYA kalau captionnya bercerita tentang hal lain, jelas, dengan kata-katanya
sendiri:
- `testimonial`   — dokumentasi/laporan jamaah yang SUDAH berangkat, pelepasan,
                    penyambutan kepulangan, ucapan terima kasih, foto suasana.
- `education`     — manasik, kajian, tips, doa, dakwah, sejarah, tanya-jawab fiqih.
- `hotel_info`    — mengenalkan hotel atau jaraknya ke Haram, tanpa menjual paket.
- `other`         — ucapan hari besar, lowongan kerja, rekrutmen agen, giveaway,
                    pengumuman kantor/libur, promosi produk non-umroh.

Kalau captionnya menyebut harga, tanggal keberangkatan, durasi hari, kuota, atau
mengajak mendaftar paket tertentu — SELALU `lanjut`, walau juga berisi doa dan
hashtag. Ragu sedikit pun = `lanjut`.

Balas HANYA satu objek JSON:
{"keputusan":"lanjut"}   atau   {"keputusan":"tolak","post_kind":"testimonial"}
TXT;

/**
 * Balasan pra-gerbang -> null (lanjut ke vision) atau kategori penolakan.
 *
 * Fail-open di semua cabang: JSON rusak, kategori asing, `tolak` tanpa post_kind —
 * semuanya jadi null. Ini gerbang penghemat, bukan hakim; yang melihat pixel tetap
 * vision, dan penolakan di sini menghapus raw postnya selamanya lewat import.
 */
function captionGate(array $out): ?string
{
    if (($out['keputusan'] ?? '') !== 'tolak') {
        return null;
    }
    $kind = (string) ($out['post_kind'] ?? '');

    // `package_offer` dan `promo_generic` sengaja tidak ada di daftar: yang pertama
    // bukan penolakan, yang kedua vonis "detailnya kurang" yang cuma sah kalau
    // flyernya sudah dilihat.
    return in_array($kind, ['testimonial', 'education', 'hotel_info', 'other'], true)
        ? $kind
        : null;
}

/** @param string[] $models penyusun teks — pra-gerbang tidak pernah menerima gambar. */
function readCaption(array $models, string $caption): ?string
{
    out(sprintf(
        '  POST %s model=%s (caption %d char) -> pra-gerbang',
        parse_url(routerUrl(), PHP_URL_HOST),
        implode('|', $models),
        strlen($caption),
    ));

    try {
        $teks = llmPostAny($models, [
            'messages' => [
                ['role' => 'user', 'content' => "Caption postingannya:\n\n$caption"],
                ['role' => 'user', 'content' => CAPTION_GATE_PROMPT],
            ],
            'response_format' => ['type' => 'json_object'],
        ]);
    } catch (RuntimeException $e) {
        // Semua model mati: pra-gerbang ini opsional, jangan menahan post yang
        // sebenarnya bisa dibaca vision.
        out('  pra-gerbang gagal ('.$e->getMessage().') — lanjut ke vision');

        return null;
    }

    return captionGate(jsonOf($teks));
}

const TRANSCRIBE_PROMPT = <<<'TXT'
Kamu punya dua tugas atas gambar-gambar ini: MENYALIN teksnya, lalu MEMUTUSKAN
apakah ini penawaran paket umroh.

TUGAS 1 — salin, SATU ENTRI PER GAMBAR (`slides`)

Untuk SETIAP gambar yang dikirim, buat satu entri di `slides` dengan `n` = nomor
gambar (mulai dari 1, urut sesuai urutan yang dikirim) dan `text` = SEMUA teks
yang terlihat di gambar ITU apa adanya, baris per baris, termasuk angka harga,
tanggal, nama hotel, nama maskapai, nomor izin PPIU, dan tulisan kecil.
Pertahankan urutan dan pengelompokannya (mis. tabel harga per tipe kamar tetap
berpasangan dengan tipe kamarnya).

JANGAN menggabungkan dua gambar jadi satu entri, dan jangan membawa teks dari
gambar lain ke dalam sebuah entri. Ini penting: satu carousel sering memuat
BEBERAPA paket yang berbeda — gambar 1 keberangkatan Ramadhan, gambar 2 Syawal,
dan seterusnya. Masing-masing harus utuh sendiri.

JANGAN menyimpulkan, menghitung, membetulkan, atau menerjemahkan apa pun.

Stempel status ikut disalin PERSIS DI BARIS YANG DIKENAINYA: "SOLD OUT", "HABIS",
"FULL", "FULLBOOK", "CLOSED", "WAITING LIST", "EXPIRED", harga yang dicoret.
Stempel begini biasanya ditempel miring/menumpang di atas satu baris tabel — tulis
di baris itu juga (mis. "4 AGT Fantastic *5 ... Rp 29.999jt [SOLD OUT]"), jangan
dikumpulkan di akhir teks dan jangan dibuang cuma karena bukan bagian tabelnya.
Harga yang dicoret tulis "[CORET]" di sebelahnya.

Caption postingannya ikut dilampirkan sebagai KONTEKS: pakai untuk membaca yang
buram atau disingkat di gambar (nama hotel lengkap, kota keberangkatan, mata uang).
Isi caption JANGAN disalin ke `text` — `text` hanya yang terlihat di gambar itu.

Gambar yang tidak memuat teks berguna — foto suasana, dekorasi, logo saja, foto
jamaah, kalender kosong — `text` diisi "(tidak ada teks)".

TUGAS 2 — putuskan, JUGA PER GAMBAR

Untuk tiap entri `slides`, nilai dari apa yang BENAR-BENAR TERTULIS di gambar itu,
bukan dari kesan atau niat postingannya. Isi ketiga penanda ini apa adanya:

- `has_price`    : ada angka harga untuk paketnya, rupiah ATAU USD. "25jt",
                   "Rp 25.900.000", "USD 3.300", tabel harga per tipe kamar.
                   DP/cicilan/angsuran BUKAN harga paket.
- `has_date`     : ada tanggal atau bulan keberangkatan. "14 Maret 2026", "Maret 2026",
                   "Ramadhan 1447". Jadwal manasik atau tanggal acara BUKAN keberangkatan.
- `has_duration` : ada lama perjalanan dalam hari. "9 Hari", "12D/11N", "9H8M".

Ketiganya dinilai atas gambar itu SENDIRI. Kalau tanggal/durasi/harga cuma ada di
gambar lain ATAU cuma di caption, penanda gambar ini tetap false — gerbang ini
sengaja ketat.

`post_kind` (satu untuk seluruh postingan) = `package_offer` kalau ADA minimal satu
gambar yang ketiga penandanya benar. Kalau tidak ada satu pun, pilih kategori lain —
walaupun jelas-jelas flyer paket, walaupun sudah ada nama hotel dan maskapai.

TUJUANNYA WAJIB MAKKAH/MADINAH. Travel umroh juga menjual wisata halal ke Korea,
Jepang, China, Hongkong, Uzbekistan, Eropa, New Zealand — bentuknya sama persis
(tanggal, durasi, harga, maskapai) tapi itu bukan umroh dan tidak boleh masuk. Yang
begitu `other`, walaupun ketiga penandanya benar dan walaupun kop suratnya bertulis
"Umroh & Halal Tour" atau "The Ultimate Hajj & Umrah Experience" — nama travel bukan
penanda. Transit atau extension ke negara lain TETAP paket umroh selama tanah sucinya
ikut dijual: "Umroh plus Turki", "Umroh plus Aqsa", "Umroh plus Dubai" = `package_offer`.

Kategori kalau bukan `package_offer`:
- `hotel_info`    — mengenalkan/mendaftar hotel, perbandingan jarak ke Haram.
- `education`     — tips, manasik, doa, dakwah, sejarah.
- `testimonial`   — dokumentasi jamaah, pelepasan, ucapan terima kasih.
- `promo_generic` — ajakan tanpa detail lengkap: "hubungi admin", diskon tanpa paket,
                    flyer paket yang tanggal/durasi/harganya tidak lengkap, rekrutmen
                    agen, giveaway.
- `other`         — sisanya (ucapan hari besar, lowongan, pengumuman kantor).

Balas HANYA satu objek JSON, dengan satu entri `slides` per gambar yang dikirim:
{"post_kind":"...","slides":[{"n":1,"has_price":true,"has_date":true,"has_duration":true,"text":"..."}]}
TXT;

/**
 * Mata sekaligus hakim: model vision menyalin teks flyer DAN memutuskan post_kind.
 * Yang menyusun data tetap model teks — di sini tidak ada field paket satu pun.
 *
 * Labelnya ada di sini, bukan di penyusun, karena cuma model ini yang melihat
 * pixel. Caption travel sering cuma emoji + "chat admin", jadi penyusun yang
 * menilai dari teks saja itu menebak.
 *
 * Carousel dikirim sebagai satu unit — sampai batas `VISION_CHUNK` gambar per call.
 *
 * Batasnya ada karena satu call yang kegedean bukan cuma lambat, tapi **gagal
 * seluruhnya**: terukur 2026-07-31, carousel 4 gambar (1,9 MB base64) balas HTTP 200
 * dalam 9,0 detik, sementara carousel 14 gambar (5,4 MB) enam kali berturut-turut
 * kena `Operation timed out after 60002 ms with 0 bytes received`, dan sekali yang
 * lolos pun balas 89,5 KB yang di-parse jadi 0 slide (terpotong) — jadi postnya
 * tidak pernah divonis dan tidak pernah jadi paket. Yang mengikat ukuran payload
 * DAN panjang balasan; dua-duanya turun kalau gambarnya dipotong.
 *
 * Mengecilkan gambarnya bukan jalan keluar: flyer IG sudah 1024x1280–1080x1350,
 * jadi downscale ke ambang mana pun yang teksnya masih terbaca = no-op.
 *
 * Yang dibayar tambahan cuma prompt + caption per potongan (teks, murah); token
 * gambarnya sama saja. Carousel ≤ `VISION_CHUNK` tetap satu call seperti dulu.
 *
 * @return array{post_kind: string, slides: array<int, array{n: int, text: string, is_offer: bool}>, flyer_text: string}
 */
function readFlyer(array $images, string $caption = ''): array
{
    $per = max(1, (int) (env('VISION_CHUNK') ?: 5));
    $potongan = array_chunk($images, $per);
    if (count($potongan) < 2) {
        return lihatFlyer($images, $caption);
    }

    out(sprintf('  %d gambar dipotong jadi %d call vision (VISION_CHUNK=%d)', count($images), count($potongan), $per));

    $slides = [];
    $kind = 'other';
    foreach ($potongan as $i => $chunk) {
        $bagian = lihatFlyer($chunk, $caption, $i * $per);

        // Satu potongan tidak terbaca = seluruh postnya belum divonis. Balik
        // `slides` kosong supaya cmdExtract melewatinya tanpa menulis apa pun —
        // aturan yang sama dengan balasan rusak di call tunggal. Menyimpan yang
        // separuh justru mengunci: filenya ada, jadi extract berikutnya melewatinya
        // dan gambar yang tidak terbaca itu hilang selamanya.
        if ($bagian['slides'] === []) {
            out('  vision: potongan '.($i + 1).' dari '.count($potongan).' tidak terbaca — seluruh post dicoba lagi nanti');

            return ['post_kind' => 'other', 'slides' => [], 'flyer_text' => ''];
        }

        $slides = array_merge($slides, $bagian['slides']);
        if ($kind !== 'package_offer') {
            $kind = $bagian['post_kind'] === 'other' ? $kind : $bagian['post_kind'];
        }
    }

    return [
        'post_kind' => $kind,
        'slides' => $slides,
        'flyer_text' => implode("\n", array_map(
            fn ($s) => "--- gambar {$s['n']} ---\n{$s['text']}",
            $slides,
        )),
    ];
}

/** Satu call vision. `$offset` = berapa gambar sudah dikirim di potongan sebelumnya. */
function lihatFlyer(array $images, string $caption, int $offset = 0): array
{
    $content = [];
    foreach ($images as $b64) {
        $content[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,'.$b64]];
    }
    // Caption ikut dikirim sebagai konteks: angka yang buram di flyer sering
    // tertulis jelas di caption (daftar jadwal, kurs, nama hotel lengkap).
    // Aturan "jangan disalin, jangan menegakkan penanda" ada di promptnya.
    if ($caption !== '') {
        $content[] = ['type' => 'text', 'text' => "Caption postingannya (konteks saja):\n\n$caption"];
    }
    $content[] = ['type' => 'text', 'text' => TRANSCRIBE_PROMPT];

    $models = envList(env('VISION_MODEL'), 'image-model');
    out(sprintf(
        '  POST %s model=%s (%d gambar%s, %.1f KB base64) -> transkrip',
        parse_url(routerUrl(), PHP_URL_HOST),
        implode('|', $models),
        count($images),
        $offset ? ' mulai #'.($offset + 1) : '',
        array_sum(array_map('strlen', $images)) / 1024,
    ));

    $teks = llmPostAny($models, [
        'messages' => [['role' => 'user', 'content' => $content]],
        'response_format' => ['type' => 'json_object'],
    ]);

    return visionVerdict(jsonOf($teks), $offset);
}

/**
 * Balasan vision -> putusan gerbang. Ketiga penanda ditegakkan di sini, bukan cuma
 * diminta di prompt: model sering menulis has_price=false lalu tetap melabeli
 * `package_offer`. Yang dipercaya penandanya, bukan labelnya.
 *
 * @return array{post_kind: string, flyer_text: string}
 */
function visionVerdict(array $out, int $offset = 0): array
{
    $kind = (string) ($out['post_kind'] ?? '');

    // Penanda dinilai per gambar: satu carousel bisa memuat beberapa paket berbeda,
    // dan gambar yang bukan penawaran tidak boleh ikut dikirim ke penyusun.
    $slides = [];
    foreach ($out['slides'] ?? [] as $slide) {
        $n = (int) ($slide['n'] ?? 0);
        if ($n < 1) {
            continue;
        }
        // Model menomori gambar dalam potongan yang DIA terima (1..k); yang dipakai
        // hilir (`$sent[$n-1]`, `flyer_index`) nomor gambar di postnya.
        $n += $offset;
        $slides[$n] = [
            'n' => $n,
            'text' => trim((string) ($slide['text'] ?? '')),
            'is_offer' => ($slide['has_price'] ?? false)
                && ($slide['has_date'] ?? false)
                && ($slide['has_duration'] ?? false),
        ];
    }
    ksort($slides);
    $slides = array_values($slides);

    $adaPenawaran = array_filter($slides, fn ($s) => $s['is_offer']) !== [];

    return [
        'post_kind' => ($kind === 'package_offer' && ! $adaPenawaran) ? 'promo_generic' : ($kind ?: 'other'),
        'slides' => $slides,
        // Transkrip gabungan, cuma untuk audit — penyusun dikirimi teks per gambar.
        'flyer_text' => implode("\n", array_map(
            fn ($s) => "--- gambar {$s['n']} ---\n{$s['text']}",
            $slides,
        )),
    ];
}

/** Balasan model -> array. Sebagian model tetap membungkus JSON dengan ```json. */
function jsonOf(string $text): array
{
    $text = trim($text);
    if (str_starts_with($text, '```')) {
        $text = trim((string) preg_replace('/^```[a-z]*|```$/m', '', $text));
    }

    return json_decode($text, true) ?? [];
}

/** Satu pintu keluar: 9router (OpenAI-compatible). Mata & penyusun beda `model` saja. */
function routerUrl(): string
{
    return env('AI_API_URL', 'https://9router.jinno.my.id/v1/chat/completions');
}

/**
 * Isi env yang boleh berisi daftar: satu nilai polos, atau daftar berbentuk JSON —
 * `'["ds/deepseek-v4-flash","openrouter/deepseek/deepseek-v4-flash"]'`.
 * EXTRACT_MODEL/VISION_MODEL dipakai bergiliran oleh llmPostAny() (yang galat
 * dilewat); IG_ACCESS_TOKEN/IG_USER_ID oleh igCreds().
 *
 * @return string[] minimal satu nilai
 */
function envList(?string $raw, string $default): array
{
    // Kutip luar dilepas: JSON di .env wajib dikutip supaya parser Laravel tidak
    // tersandung spasi di dalam daftarnya.
    $raw = trim((string) ($raw ?? $default), " \t\n\r\"'");
    $list = str_starts_with($raw, '[') ? json_decode($raw, true) : [$raw];
    $list = array_values(array_filter(array_map(
        fn ($m) => trim((string) $m),
        is_array($list) ? $list : [],
    )));

    // JSON rusak / daftar kosong jangan bikin ekstraksi diam-diam berhenti.
    return $list ?: [$default];
}

/**
 * Round-robin + fallback: tiap panggilan mulai dari model berikutnya (kuota free
 * tier kebagi rata, satu provider kena limit tidak menghentikan batch), dan model
 * yang galat dilewat ke sesudahnya. Kalau semuanya mati, galat terakhir dilempar —
 * itu memang harus berisik.
 *
 * @param  string[]  $models
 */
function llmPostAny(array $models, array $payload): string
{
    static $giliran = 0;

    $geser = $giliran++ % count($models);
    $urut = array_merge(array_slice($models, $geser), array_slice($models, 0, $geser));

    foreach ($urut as $i => $model) {
        try {
            return llmPost(routerUrl(), need('AI_API_KEY'), ['model' => $model] + $payload);
        } catch (RuntimeException $e) {
            if ($i === count($urut) - 1) {
                throw $e;
            }
            out("    $model gagal: ".substr($e->getMessage(), 0, 120)." — coba {$urut[$i + 1]}");
        }
    }

    throw new RuntimeException('Daftar model kosong');
}

/**
 * Penyusun: teks (caption + transkrip flyer) -> JSON. Tidak pernah menerima gambar.
 * Ganti provider = ganti AI_API_URL + AI_API_KEY + EXTRACT_MODEL.
 */
function callExtractor(array $models, string $caption, string $flyerText, ?string $postedAt = null): array
{
    $prompt = "Caption postingan:\n\n".($caption === '' ? '(kosong)' : $caption);
    if ($flyerText !== '') {
        $prompt .= "\n\nTeks yang terbaca di flyer:\n\n$flyerText";
    }
    // Flyer sering menulis "14 Maret" tanpa tahun. Tanpa jangkar ini model menebak
    // tahun berjalan dan paket 2027 masuk sebagai 2026 — lolos filter ambang.
    if ($postedAt !== null) {
        $prompt .= "\n\nTanggal posting: ".substr($postedAt, 0, 10);
    }

    // Router mengabaikan `json_schema` (diuji 2026-07-29: prompt prosa tetap dibalas
    // prosa), jadi schema-nya dititipkan di prompt lalu hasilnya divalidasi sendiri.
    $payload = [
        'messages' => [
            [
                'role' => 'system',
                'content' => SYSTEM_PROMPT."\n\nBalas HANYA satu objek JSON yang patuh pada JSON Schema ini:\n"
                    .json_encode(extractionSchema(), JSON_UNESCAPED_SLASHES),
            ],
            ['role' => 'user', 'content' => $prompt],
        ],
        'response_format' => ['type' => 'json_object'],
    ];

    return jsonOf(llmPostAny($models, $payload));
}

/** POST OpenAI-compatible + backoff 429. @return string isi balasan model */
function llmPost(string $url, string $key, array $payload): string
{
    for ($try = 0; ; $try++) {
        $mulai = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) (env('AI_TIMEOUT') ?: 60),
            CURLOPT_POST => true,
            // Router suka memutus stream h2 di tengah balasan panjang
            // ("INTERNAL_ERROR (err 2)"). HTTP/1.1 tidak punya multiplexing yang
            // bisa direset sepihak begitu; satu koneksi satu request.
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer '.$key],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        // Timeout/koneksi putus di tengah batch panjang: coba lagi, jangan buang 100 post sebelumnya.
        //
        // Timeout diulang SEKETIKA: router memilih API key per request, jadi POST baru
        // dapat key baru — menunggu cuma memakan jatah job. Galat koneksi lain (DNS,
        // connection refused) dijeda 1s. Maksimal dua percobaan di sini, sisanya
        // urusan llmPostAny(): pindah model = pindah provider, jauh lebih mungkin
        // berhasil daripada mengetuk pintu yang sama untuk ketiga kalinya.
        //
        // Anggarannya mengikat: ExtractPost punya $timeout detik untuk satu post
        // (1 vision + N penyusun). AI_TIMEOUT x 2 percobaan x jumlah model harus muat
        // di situ — dulu 180s x 4 percobaan sendirian sudah melewatinya.
        if ($body === false) {
            if ($try < 1) {
                $jeda = $errno === CURLE_OPERATION_TIMEDOUT ? 0 : 1;
                out("    $err — ulangi ".($jeda ? "dalam {$jeda}s" : 'seketika (key baru)'));
                $jeda && sleep($jeda);

                continue;
            }
            throw new RuntimeException("curl gagal: $err");
        }
        if ($code === 429 && $try < 5) {
            preg_match('/retry in ([\d.]+)/i', $body, $m);
            $wait = (int) ceil((float) ($m[1] ?? 30)) + 1;
            // Model + alasan wajib ikut: baris POST mencetak seluruh daftar kandidat,
            // dan baris "<- HTTP" yang menyebut pemenangnya dilewat saat 429 — jadi
            // tanpa ini tidak ketahuan slot bayar atau slot gratis yang kena kuota.
            out(sprintf(
                '    429 %s: %s — tunggu %ds...',
                $payload['model'] ?? '?',
                substr(preg_replace('/\s+/', ' ', strip_tags((string) $body)), 0, 200),
                $wait,
            ));
            sleep($wait);

            continue;
        }

        // Model dicatat di sini, bukan cuma di baris POST: yang dikirim itu daftar,
        // yang menjawab satu — dan mutunya beda-beda saat menilai hasil ekstraksi.
        out(sprintf(
            '    <- HTTP %d %.1fs %.1f KB dari %s (%s)',
            $code,
            microtime(true) - $mulai,
            strlen((string) $body) / 1024,
            parse_url($url, PHP_URL_HOST),
            $payload['model'] ?? '?',
        ));

        if ($code !== 200) {
            $json = json_decode($body, true);
            throw new RuntimeException("LLM HTTP $code: ".($json['error']['message'] ?? substr($body, 0, 500)));
        }

        $teks = llmContent((string) $body);
        // Balasan kosong bukan "tidak ada data": router pernah balas 200 dengan galat
        // di badan, dan itu harus berisik, bukan jadi paket tanpa field.
        if (trim($teks) === '') {
            throw new RuntimeException('LLM balas kosong: '.substr((string) $body, 0, 300));
        }

        return $teks;
    }
}

/**
 * Badan balasan -> teks. Router selalu membalas SSE walau `stream` tidak diset,
 * menempel `data: [DONE]` di ekor, dan sebagian provider membungkus hasil di
 * `{"data":{"choices":…}}`. Provider yang membalas satu objek biasa (deepseek)
 * lewat jalur pertama.
 */
function llmContent(string $body): string
{
    // `data: [DONE]` dilem ke ekor JSON tanpa newline walau non-streaming -> json_decode gagal.
    $body = trim((string) preg_replace('/\s*data:\s*\[DONE\]\s*$/', '', trim($body)));

    if (is_array($json = json_decode($body, true))) {
        $c = ($json['data'] ?? $json)['choices'][0] ?? [];

        return (string) ($c['message']['content'] ?? $c['delta']['content'] ?? '');
    }

    $teks = '';
    foreach (preg_split('/\r?\n/', $body) ?: [] as $baris) {
        $baris = trim($baris);
        if (str_starts_with($baris, 'data:')) {
            $baris = trim(substr($baris, 5));
        }
        if ($baris === '' || $baris === '[DONE]' || ! is_array($j = json_decode($baris, true))) {
            continue;
        }
        $c = ($j['data'] ?? $j)['choices'][0] ?? [];
        $teks .= (string) ($c['delta']['content'] ?? $c['message']['content'] ?? '');
    }

    return $teks;
}

// ------------------------------------------------------------------- gerbang

/** @return string[] field wajib yang masih kosong */
function missingFields(array $data): array
{
    $missing = [];
    foreach (REQUIRED_FIELDS as $field) {
        $value = $data[$field] ?? null;
        if ($value === null || $value === [] || $value === '') {
            $missing[] = $field;
        }
    }

    return $missing;
}

function cmdSelftest(): void
{
    $full = [
        'departure_date' => '2026-03-14',
        'duration_days' => 9,
        'departure_city' => 'Jakarta',
        'price_tiers' => [['occupancy' => 'quad', 'amount' => 25900000, 'currency' => 'IDR', 'is_starting_from' => false]],
    ];
    assert(missingFields($full) === [], 'post lengkap harusnya lolos');

    // price_tiers kosong = array kosong, bukan null. Harus tetap terhitung kurang.
    assert(missingFields(['departure_date' => '2026-03', 'duration_days' => 9,
        'departure_city' => 'Jakarta', 'price_tiers' => []]) === ['price_tiers']);

    // Field hilang total (key ga ada) juga harus kedetek.
    assert(missingFields([]) === REQUIRED_FIELDS);

    // departure_date "YYYY-MM" tetap valid — date_certainty yang bedain, bukan gate.
    assert(missingFields(['departure_date' => '2026-03'] + $full) === []);

    // Angka 0 jangan dianggap kosong (duration_days 0 itu data salah, tapi bukan "hilang").
    assert(! in_array('duration_days', missingFields(['duration_days' => 0] + $full), true));

    // Pra-gerbang caption: fail-open di semua cabang yang tidak tegas menolak.
    assert(captionGate(['keputusan' => 'tolak', 'post_kind' => 'testimonial']) === 'testimonial');
    assert(captionGate(['keputusan' => 'lanjut']) === null, 'lanjut = vision tetap jalan');
    assert(captionGate([]) === null, 'balasan rusak bukan vonis');
    assert(captionGate(['keputusan' => 'tolak']) === null, 'tolak tanpa kategori = lanjut');
    assert(captionGate(['keputusan' => 'tolak', 'post_kind' => 'promo_generic']) === null,
        'promo_generic cuma sah kalau flyernya sudah dilihat');

    // x-app-usage yang absen jangan menimpa angka terakhir dengan sampah.
    lastAppUsage(['total_time' => 42]);
    assert(lastAppUsage() === ['total_time' => 42]);
    assert(lastAppUsage([]) === [], 'header hilang -> kosong, bukan angka basi');

    // Pemilihan URL gambar per tipe media: hanya IMAGE.
    assert(imageUrlOf(['media_type' => 'IMAGE', 'media_url' => 'a.jpg']) === 'a.jpg');
    assert(imageUrlOf(['media_type' => 'VIDEO', 'media_url' => 'v.mp4', 'thumbnail_url' => 't.jpg']) === null,
        'VIDEO harus dilewat walau punya thumbnail');
    assert(imageUrlOf(['media_type' => 'REELS', 'thumbnail_url' => 't.jpg']) === null, 'REELS dilewat');
    assert(imageUrlOf(['media_type' => 'CAROUSEL_ALBUM']) === null, 'carousel diproses lewat children');

    // Carousel campuran: child video dibuang, child gambar tetap terambil.
    $mixed = ['media_type' => 'CAROUSEL_ALBUM', 'children' => ['data' => [
        ['media_type' => 'IMAGE', 'media_url' => '1.jpg'],
        ['media_type' => 'VIDEO', 'media_url' => '2.mp4', 'thumbnail_url' => '2.jpg'],
        ['media_type' => 'IMAGE', 'media_url' => '3.jpg'],
    ]]];
    assert(imageUrlsOf($mixed) === ['1.jpg', '3.jpg'], 'carousel campuran harus sisakan gambar saja');

    // Post yang tidak menghasilkan gambar tidak boleh disimpan -> [] jadi sinyal skip.
    assert(imageUrlsOf(['media_type' => 'VIDEO', 'thumbnail_url' => 't.jpg']) === [], 'reels dilewat total');
    assert(imageUrlsOf(['media_type' => 'CAROUSEL_ALBUM', 'children' => ['data' => [
        ['media_type' => 'VIDEO', 'media_url' => 'a.mp4', 'thumbnail_url' => 'a.jpg'],
        ['media_type' => 'VIDEO', 'media_url' => 'b.mp4'],
    ]]]) === [], 'carousel isi video semua = tidak ada flyer');
    assert(imageUrlsOf(['media_type' => 'IMAGE', 'media_url' => 'x.jpg']) === ['x.jpg']);

    // Nomor gambar memetakan ke file yang DIKIRIM, bukan urutan file di folder
    // (0.jpg bisa saja dilewat karena hash-nya duplikat).
    assert(slideFile(['1.jpg', '3.jpg'], 2) === '3.jpg', 'nomor gambar ikut urutan kirim');
    assert(slideFile(['0.jpg', '1.jpg', '2.jpg'], 1) === '0.jpg');
    assert(slideFile(['0.jpg'], 99) === null, 'nomor ngawur jangan bikin flyer akun lain kepajang');
    assert(slideFile([], 0) === null, 'jalur caption-only tidak punya flyer');

    // Antrian fetchall: URL, @handle, dan baris sampah.
    assert(usernameOf('https://www.instagram.com/sunnatravel.id') === 'sunnatravel.id');
    assert(usernameOf('https://www.instagram.com/hamdantour/?hl=id') === 'hamdantour');
    assert(usernameOf('@nakhlatour') === 'nakhlatour');
    assert(usernameOf('# komentar') === null);
    assert(usernameOf('') === null);
    assert(usernameOf('https://instagram.com/p/Cxyz/') === null, 'URL post bukan username');

    // Dedup hash: dua file byte-identik -> hash sama.
    assert(hash('sha256', 'flyer-a') === hash('sha256', 'flyer-a'));
    assert(hash('sha256', 'flyer-a') !== hash('sha256', 'flyer-b'));

    // Schema harus valid JSON dan semua required ada di properties.
    $schema = extractionSchema();
    foreach ($schema['required'] as $field) {
        assert(isset($schema['properties'][$field]), "required '$field' ga ada di properties");
    }
    assert(json_encode($schema) !== false, 'schema harus serializable');

    // guide_name diekstrak tapi TIDAK menggeser gate — banyak paket memang tanpa pembimbing.
    assert(isset($schema['properties']['guide_name']));
    assert(! in_array('guide_name', REQUIRED_FIELDS, true), 'guide_name jangan masuk gate');
    assert(missingFields(['guide_name' => null] + $full) === [], 'guide_name kosong tetap lolos');

    // Gerbang vision: yang dipercaya penandanya, bukan labelnya. Dinilai per gambar.
    $slide = ['n' => 1, 'has_price' => true, 'has_date' => true, 'has_duration' => true, 'text' => 'x'];
    $lolos = ['post_kind' => 'package_offer', 'slides' => [$slide]];
    assert(visionVerdict($lolos)['post_kind'] === 'package_offer', 'tiga penanda lengkap harus lolos');
    foreach (['has_price', 'has_date', 'has_duration'] as $penanda) {
        $kurang = ['post_kind' => 'package_offer', 'slides' => [[$penanda => false] + $slide]];
        assert(visionVerdict($kurang)['post_kind'] === 'promo_generic',
            "$penanda kosong harus ditolak walau dilabeli package_offer");
    }
    assert(visionVerdict([])['post_kind'] === 'other', 'balasan rusak jangan jadi paket');
    // ...tapi juga jangan jadi vonis: `slides` kosong itu tanda balasannya tidak
    // terbaca, dan cmdExtract() memakainya untuk melewati post tanpa menulis apa pun
    // (kalau ditulis, import mengecualikan postnya selamanya + rawnya dihapus).
    assert(visionVerdict([])['slides'] === [], 'balasan rusak wajib 0 slide, itu penanda "belum divonis"');
    assert(visionVerdict(['slides' => [['has_price' => true]]])['slides'] === [],
        'slide tanpa nomor gambar tidak bisa dipetakan ke file — sama dengan balasan rusak');
    assert(visionVerdict(['post_kind' => 'education'])['post_kind'] === 'education', 'label non-paket dibiarkan');

    // Carousel: tiap gambar penawaran berdiri sendiri, gambar dakwah tidak ikut.
    $campur = visionVerdict(['post_kind' => 'package_offer', 'slides' => [
        ['n' => 2, 'has_price' => true, 'has_date' => true, 'has_duration' => true, 'text' => 'syawal'],
        ['n' => 1, 'has_price' => true, 'has_date' => true, 'has_duration' => true, 'text' => 'ramadhan'],
        ['n' => 3, 'has_price' => false, 'has_date' => false, 'has_duration' => false, 'text' => 'dakwah'],
    ]]);
    assert(array_column($campur['slides'], 'n') === [1, 2, 3], 'slide wajib urut nomor gambar');
    assert(array_column($campur['slides'], 'is_offer') === [true, true, false], 'penawaran dinilai per gambar');

    // Carousel yang dipotong: model menomori 1..k per potongan, offset yang
    // mengembalikannya ke nomor gambar di post. Salah di sini = flyer_index
    // meleset dan kartunya memajang gambar tetangganya.
    $potongan2 = visionVerdict(['post_kind' => 'package_offer', 'slides' => [
        ['n' => 1, 'has_price' => true, 'has_date' => true, 'has_duration' => true],
        ['n' => 2, 'has_price' => true, 'has_date' => true, 'has_duration' => true],
    ]], 5);
    assert(array_column($potongan2['slides'], 'n') === [6, 7], 'nomor slide digeser sejumlah gambar potongan sebelumnya');
    assert(str_contains($campur['flyer_text'], '--- gambar 3 ---'), 'transkrip audit tetap memuat semua gambar');

    // Daftar model: satu nama, JSON array, atau isi ngawur -> tetap ada yang dipakai.
    assert(envList('ds/satu', 'x') === ['ds/satu'], 'satu nama polos');
    assert(envList(null, 'x') === ['x'], 'kosong jatuh ke default');
    assert(envList('\'["a/1", "b/2" , ""]\'', 'x') === ['a/1', 'b/2'], 'JSON berkutip, entri kosong dibuang');
    assert(envList('[rusak', 'x') === ['x'], 'JSON rusak jatuh ke default');

    // --- kredensial Graph API: satu Page di beberapa app, atau sepasang per app
    assert(igPair(['u1'], ['t1', 't2']) === [
        ['user' => 'u1', 'token' => 't1'],
        ['user' => 'u1', 'token' => 't2'],
    ], 'satu IG_USER_ID dipakai semua token');
    assert(igPair(['u1', 'u2'], ['t1', 't2'])[1]['user'] === 'u2', 'pasangan per app ikut urutan');
    try {
        igPair(['u1', 'u2'], ['t1', 't2', 't3']);
        assert(false, 'jumlah user vs token tidak sepadan harus galat, bukan diam-diam salah pasang');
    } catch (RuntimeException) {
    }

    // Slot app untuk `auth`: id + secret sepasang, slot di luar daftar galat.
    assert(metaApp(['a1', 'a2'], ['s1', 's2'], 1) === ['a2', 's2'], 'slot memilih pasangan yang sama');
    foreach ([[['a1'], ['s1', 's2'], 0], [['a1', 'a2'], ['s1', 's2'], 2]] as [$ids, $secrets, $slot]) {
        try {
            metaApp($ids, $secrets, $slot);
            assert(false, 'id/secret tidak sepadan atau slot di luar daftar harus galat');
        } catch (RuntimeException) {
        }
    }

    // Tiga bentuk balasan router yang harus sama-sama kebaca.
    assert(llmContent('{"choices":[{"message":{"content":"a"}}]}') === 'a', 'objek biasa');
    assert(llmContent('{"data":{"choices":[{"message":{"content":"b"}}]}}') === 'b', 'bungkus data');
    assert(llmContent('{"choices":[{"message":{"content":"c"}}]}data: [DONE]') === 'c', '[DONE] dilem di ekor');
    assert(llmContent(
        "data: {\"choices\":[{\"delta\":{\"role\":\"assistant\"}}]}\n\n"
        ."data: {\"choices\":[{\"delta\":{\"content\":\"{\\\"a\\\":\"}}]}\n\n"
        ."data: {\"choices\":[{\"delta\":{\"content\":\"1}\"}}]}\n\n"
        ."data: [DONE]\n"
    ) === '{"a":1}', 'SSE digabung, [DONE] dilewat');

    out('selftest OK');
}

// ---------------------------------------------------------------- dispatcher

function optval(array $argv, string $name): ?string
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, "--$name=")) {
            return substr($arg, strlen($name) + 3);
        }
    }

    return null;
}

$cmd = $argv[1] ?? 'help';

try {
    match ($cmd) {
        'auth' => cmdAuth($argv),
        'fetch' => cmdFetch($argv),
        'fetchall' => cmdFetchAll($argv),
        'profile' => cmdProfile($argv),
        'quota' => cmdQuota($argv),
        'seed' => cmdSeed($argv),
        'extract' => cmdExtract($argv),
        'selftest' => cmdSelftest(),
        default => out(
            "php probe.php auth <short_lived_user_token>\n".
            "php probe.php fetch <username> [--limit=50]\n".
            "php probe.php fetchall [accounts.txt] [--limit=50] [--sleep=3] [--retry]\n".
            "php probe.php profile <username> [username…] [--sleep=3]\n".
            "php probe.php quota\n".
            "php probe.php seed <dir>\n".
            "php probe.php extract [--limit=200] [--force]\n".
            'php probe.php selftest'
        ),
    };
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: '.$e->getMessage()."\n");
    exit(1);
}
