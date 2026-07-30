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
 * yang dibacanya dari DB adalah tabel banned_posts (bannedIds(), read-only PDO).
 */

declare(strict_types=1);

const ROOT     = __DIR__;
const RAW_DIR  = ROOT . '/storage/raw';
const EXT_DIR  = ROOT . '/storage/extracted';
const HASH_FILE = ROOT . '/storage/hashes.json';
const QUEUE_FILE = ROOT . '/storage/fetch_queue.json';

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
        $path = ROOT . '/.env';
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
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
 * media_id yang sudah dibanned: bukan penawaran paket, keberangkatannya sudah
 * lewat, atau dibuang manual di halaman review. Jangan di-scrap lagi.
 *
 * Dibaca langsung dari SQLite-nya Laravel — cuma satu SELECT, tanpa boot framework.
 * Tabel belum ada / DB belum dimigrasi = tidak ada yang dibanned.
 *
 * @return array<string, true>
 */
function bannedIds(): array
{
    static $ids = null;
    if ($ids !== null) {
        return $ids;
    }

    $ids = [];
    $db  = ROOT . '/database/database.sqlite';
    if (!is_file($db)) {
        return $ids;
    }

    try {
        $pdo  = new PDO("sqlite:$db", options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $rows = $pdo->query('SELECT media_id FROM banned_posts')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $id) {
            $ids[(string) $id] = true;
        }
    } catch (Throwable $e) {
        out('  (daftar banned tidak terbaca: ' . $e->getMessage() . ')');
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
    fwrite(STDOUT, $msg . "\n");
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
        'type'       => 'object',
        'properties' => [
            'occupancy'        => ['type' => 'string', 'enum' => ['quad', 'triple', 'double', 'single']],
            'amount'           => ['type' => 'integer'],
            'currency'         => ['type' => 'string', 'enum' => ['IDR', 'USD']],
            'is_starting_from' => ['type' => 'boolean'],
        ],
        'required'             => ['occupancy', 'amount', 'currency', 'is_starting_from'],
        'additionalProperties' => false,
    ];

    $hotel = nullable([
        'type'       => 'object',
        'properties' => [
            'raw_name' => ['type' => 'string'],
            'nights'   => nullable(['type' => 'integer']),
        ],
        'required'             => ['raw_name', 'nights'],
        'additionalProperties' => false,
    ]);

    $confidence = [
        'type'       => 'object',
        'properties' => [
            'departure_date' => ['type' => 'number'],
            'price'          => ['type' => 'number'],
            'hotels'         => ['type' => 'number'],
            'ppiu'           => ['type' => 'number'],
        ],
        'required'             => ['departure_date', 'price', 'hotels', 'ppiu'],
        'additionalProperties' => false,
    ];

    return [
        'type'       => 'object',
        'properties' => [
            // Diputuskan duluan: postingan travel mayoritas BUKAN penawaran paket.
            // Gemini cuma menyalin teks flyer; yang menilai konteks postingan ini.
            'post_kind'      => ['type' => 'string', 'enum' => [
                'package_offer', 'hotel_info', 'education', 'testimonial', 'promo_generic', 'other',
            ]],
            'ppiu_name'      => nullable(['type' => 'string']),
            'license_number' => nullable(['type' => 'string']),
            'departure_date' => nullable(['type' => 'string']),
            'date_certainty' => ['type' => 'string', 'enum' => ['exact', 'month', 'season', 'unknown']],
            'duration_days'  => nullable(['type' => 'integer']),
            'departure_city' => nullable(['type' => 'string']),
            'airline'        => nullable(['type' => 'string']),
            'guide_name'     => nullable(['type' => 'string']),
            'extension'      => ['type' => 'string', 'enum' => ['turki', 'dubai', 'aqsa', 'none', 'unknown']],
            'price_tiers'    => ['type' => 'array', 'items' => $tier],
            'hotel_makkah'   => $hotel,
            'hotel_madinah'  => $hotel,
            'facilities'     => [
                'type'  => 'array',
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
            'detail_images'  => ['type' => 'array', 'items' => ['type' => 'integer']],
            'confidence'     => $confidence,
        ],
        'required' => [
            'post_kind',
            'ppiu_name', 'license_number', 'departure_date', 'date_certainty', 'duration_days',
            'departure_city', 'airline', 'guide_name', 'extension', 'price_tiers', 'hotel_makkah',
            'hotel_madinah', 'facilities', 'facilities_raw', 'detail_images', 'confidence',
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
- `departure_date`: "YYYY-MM-DD" kalau tanggal pasti, "YYYY-MM" kalau cuma bulan.
  Set `date_certainty` sesuai: exact / month / season (mis. "musim liburan") / unknown.
- Harga Indonesia sering disingkat: "25jt" / "25 juta" = 25000000, "25,9jt" = 25900000.
  Kalau tertulis "mulai dari" / "start from" / "*", set is_starting_from = true.
- Harga dalam dolar ditulis apa adanya: "USD 3.300" -> amount 3300, currency "USD".
  JANGAN dikonversi sendiri ke rupiah — kursnya diurus di luar.
- `departure_city`: kota tempat jamaah BERANGKAT (embarkasi). "Berangkat dari
  Jakarta", "CGK"/"Soekarno-Hatta" -> "Jakarta"; "SUB"/"Juanda" -> "Surabaya".
  Kota tujuan (Jeddah/Madinah), kota kantor travel, dan asal pembimbing
  ("Ustadz dari Jakarta") BUKAN kota keberangkatan — kalau cuma itu yang ada,
  isi null.
- Kalau tipe kamar tidak disebut tapi ada satu harga, anggap `quad` (harga termurah/dasar).
- Nama hotel disalin apa adanya ke `raw_name` ("setaraf Anjum" tetap ditulis penuh).
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
        CURLOPT_TIMEOUT        => 30,
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

    if ($body === false) {
        throw new RuntimeException('curl gagal');
    }
    $json = json_decode($body, true);
    if ($code !== 200) {
        $err  = $json['error'] ?? [];
        $msg  = $err['message'] ?? $body;
        // Kode Graph API yang sering muncul di jalur ini, diterjemahkan ke penyebab nyata.
        $hint = match ($err['code'] ?? 0) {
            10  => "Permission kurang. business_discovery butuh SEMUA ini:\n"
                 . "       instagram_basic, instagram_manage_insights, pages_show_list,\n"
                 . "       pages_read_engagement, business_management.\n"
                 . "       Yang paling sering kelewat: instagram_manage_insights.",
            110 => 'Username tidak ditemukan, atau akun target bukan Professional (Business/Creator).',
            190 => 'Token invalid/kedaluwarsa. Ambil token baru lalu jalankan: php probe.php auth <token>',
            4, 17, 32 => 'Kena rate limit tingkat app. Semua request numpuk di satu token — kasih jeda.',
            default => null,
        };
        throw new RuntimeException("Graph API HTTP $code: $msg" . ($hint ? "\n       -> $hint" : ''));
    }
    return $json;
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
 * @param  string[] $users
 * @param  string[] $tokens
 * @return list<array{user: string, token: string}>
 */
function igPair(array $users, array $tokens): array
{
    if (count($users) !== 1 && count($users) !== count($tokens)) {
        throw new RuntimeException(sprintf(
            'IG_USER_ID %d entri vs IG_ACCESS_TOKEN %d entri. Isi satu IG_USER_ID '
            . '(Page yang sama di beberapa app) atau sepasang per app, urutannya sama.',
            count($users),
            count($tokens),
        ));
    }

    return array_map(
        fn (int $i, string $token) => [
            'user'  => $users[count($users) === 1 ? 0 : $i],
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
    $creds   = igCreds();
    $c       = crc32($username) % count($creds);
    $sisa    = count($creds) - 1;
    $version = env('IG_GRAPH_VERSION', 'v25.0');

    // thumbnail_url dipakai untuk VIDEO/Reels — flyer sering diposting sebagai Reels,
    // dan media_url untuk video isinya mp4 yang ga bisa dikirim ke vision LLM.
    $fields = 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp,'
        . 'like_count,comments_count,children{id,media_type,media_url,thumbnail_url}';

    $after   = null;
    $count   = 0;
    $skipped = 0;
    $scanned = 0;
    $banned  = bannedIds();

    out(sprintf('fetch @%s limit=%d (%d post dibanned, dilewat)', $username, $limit, count($banned)));

    // ponytail: batas pindai supaya akun yang isinya banned semua tidak bikin
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
        $url = sprintf(
            'https://graph.facebook.com/%s/%s?fields=business_discovery.username(%s){%s{%s}}&access_token=%s',
            $version,
            $creds[$c]['user'],
            $username,
            $mediaArgs,
            $fields,
            urlencode($creds[$c]['token'])
        );

        out(sprintf(
            '  GET graph.facebook.com/%s business_discovery(@%s) %s%s',
            $version,
            $username,
            $after === null ? "media.limit($page)" : "media.after(" . substr($after, 0, 12) . "…).limit($page)",
            count($creds) > 1 ? ' [app ' . ($c + 1) . '/' . count($creds) . ']' : '',
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
            if ($sisa > 0 && str_contains($e->getMessage(), 'rate limit')) {
                $c = ($c + 1) % count($creds);
                $sisa--;
                out('  kena rate limit, pindah ke app ' . ($c + 1) . '/' . count($creds));
                continue;
            }
            throw $e;
        }
        $bd  = $res['business_discovery'] ?? null;
        if ($bd === null) {
            // Akun personal / bukan Professional -> ga terbaca sama sekali.
            throw new RuntimeException("@$username tidak terbaca. Pastikan akun Professional.");
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

            // Sudah dibanned: tidak ada gunanya di-download lagi, dan sengaja TIDAK
            // dihitung ke $limit — "9 post, 2 dibanned" berarti ambil 7 yang lain.
            if (isset($banned[$id])) {
                $skipped++;
                out("  $id dilewat: sudah dibanned");
                continue;
            }
            // Sudah ada di disk: rawnya masih dipakai untuk flyer, jangan di-download ulang.
            if (is_file(RAW_DIR . "/$username/$id/post.json")) {
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
        . ($skipped > 0 ? " ($skipped dilewat, $scanned dipindai)" : ''));
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
function cmdFetchAll(array $argv): void
{
    $limit = (int) (optval($argv, 'limit') ?? 50);
    $sleep = (int) (optval($argv, 'sleep') ?? 3);
    $cool  = (int) (optval($argv, 'cooldown') ?? 300);

    $queue = is_file(QUEUE_FILE)
        ? (json_decode((string) file_get_contents(QUEUE_FILE), true) ?: [])
        : [];

    // File daftar akun opsional: tanpa itu, lanjutkan antrian yang sudah ada.
    // Argumen ber-prefix "--" itu flag, bukan nama file.
    $file = ($argv[2] ?? null) !== null && !str_starts_with($argv[2], '--') ? $argv[2] : null;
    if ($file !== null) {
        if (!is_file($file)) {
            out("File $file tidak ada. Isi satu username/URL per baris.");
            exit(1);
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            if (($u = usernameOf($line)) && !isset($queue[$u])) {
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

    $pending = array_keys(array_filter($queue, fn($r) => $r['status'] === 'pending'));
    $total   = count($queue);
    out(count($pending) . " pending dari $total akun.");

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
function savePost(string $username, array $post): void
{
    $dir = RAW_DIR . "/$username/" . $post['id'];
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
    $post['_images']         = $files;
    $post['_fetched_at']     = date('c');
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
 * @param  string[] $ids
 * @param  string[] $secrets
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
    if (!isset($ids[$slot])) {
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
            . '       Butuh User Token dari Meta App (produk "Instagram API with Facebook Login"), diawali EAA.'
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
    $base    = "https://graph.facebook.com/$version";

    // 1. Short-lived -> long-lived user token (60 hari).
    $res = graphGet($base . '/oauth/access_token?' . http_build_query([
        'grant_type'        => 'fb_exchange_token',
        'client_id'         => $appId,
        'client_secret'     => $secret,
        'fb_exchange_token' => $short,
    ]));
    $longUser = $res['access_token'];

    // 2. Page + IG Business Account yang ter-link.
    $pages = graphGet($base . '/me/accounts?' . http_build_query([
        'fields'       => 'id,name,access_token,instagram_business_account{id,username}',
        'access_token' => $longUser,
    ]));

    $found = false;
    foreach ($pages['data'] ?? [] as $page) {
        $ig = $page['instagram_business_account'] ?? null;
        out(str_repeat('-', 46));
        out('Page          : ' . $page['name'] . ' (' . $page['id'] . ')');
        if ($ig === null) {
            out('IG account    : BELUM ter-link. Link dari Meta Business Suite dulu.');
            continue;
        }
        $found = true;
        out('IG account    : @' . $ig['username']);
        out('');
        out('Tempel ke .env:');
        out('  IG_USER_ID=' . $ig['id']);
        // Page token turunan long-lived user token tidak expire selama izin tidak dicabut.
        out("  IG_ACCESS_TOKEN slot $slot = " . $page['access_token']);
    }
    out(str_repeat('-', 46));

    if (!$found) {
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
    if ($src === null || !is_dir($src)) {
        out('Usage: php probe.php seed <dir berisi .txt (+ .jpg opsional)>');
        exit(1);
    }

    $count = 0;
    foreach (glob(rtrim($src, '/') . '/*.txt') ?: [] as $txt) {
        $slug = pathinfo($txt, PATHINFO_FILENAME);
        $id   = 'manual_' . preg_replace('/[^a-z0-9_-]/i', '_', $slug);

        $flyers = array_merge(
            glob("$src/$slug.jpg") ?: [],
            glob("$src/$slug-*.jpg") ?: []
        );

        savePost('manual', [
            'id'         => $id,
            'caption'    => file_get_contents($txt),
            'media_type' => $flyers === [] ? 'IMAGE' : 'CAROUSEL_ALBUM',
            'permalink'  => null,
            'timestamp'  => null,
            '_local'     => $flyers,
        ]);
        $count++;
    }

    out("$count paket manual masuk ke storage/raw/manual/. Lanjut: php probe.php extract");
}

// ------------------------------------------------------------------ extract

function cmdExtract(array $argv): void
{
    $limit  = (int) (optval($argv, 'limit') ?? 200);
    $force  = in_array('--force', $argv, true);
    // --only=<media_id>: ekstrak ulang satu post saja, buat ngetes perubahan prompt.
    $only   = optval($argv, 'only');
    $force  = $force || $only !== null;
    $models = envList(env('EXTRACT_MODEL'), 'ds/deepseek-v4-flash');

    @mkdir(EXT_DIR, 0775, true);

    $done = 0;
    $visionCalls = 0;
    $ditolak = 0;
    $banned  = bannedIds();

    foreach (glob(RAW_DIR . '/*/*/post.json') ?: [] as $postFile) {
        if ($done >= $limit) {
            break;
        }
        // Antrian db (prune / tombol ×) memindahkan raw ke storage/trash sementara
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
        if (!$force && glob(EXT_DIR . '/' . $post['id'] . '{.json,-*.json}', GLOB_BRACE)) {
            continue;
        }
        if (isset($banned[(string) $post['id']])) {
            out("  {$post['id']} dilewat: sudah dibanned");
            continue;
        }

        $dir    = dirname($postFile);
        $images = [];
        $sent   = [];   // nama file per gambar yang dikirim, urutannya = urutan di prompt
        foreach (claimImages($dir, $post) as $name => $bytes) {
            $images[] = base64_encode($bytes);
            $sent[]   = $name;
        }

        $caption = trim((string) ($post['caption'] ?? ''));
        out(sprintf(
            'extract %s (@%s) %d gambar, caption %d char',
            $post['id'],
            $post['_source_account'] ?? '?',
            count($images),
            strlen($caption),
        ));

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

        if ($verdict !== null && $verdict['post_kind'] !== 'package_offer') {
            // Ditolak di gerbang: simpan alasan + transkripnya saja. Penyusun tidak
            // dipanggil, dan `packages:import --prune` yang membanned + memindahkannya.
            $data = ['post_kind' => $verdict['post_kind'], '_rejected_by' => 'vision'];
            writeExtraction(EXT_DIR . '/' . $post['id'] . '.json', $data, $post, $verdict['flyer_text'], null);
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

        foreach ($offers as $offer) {
            $file  = slideFile($sent, $offer['n']);
            $label = count($offers) > 1 ? "{$post['id']}-{$offer['n']}" : $post['id'];

            out(sprintf(
                '  POST %s model=%s slide %s (%d char) -> JSON',
                parse_url(routerUrl(), PHP_URL_HOST),
                implode('|', $models),
                $offer['n'] ?: '-',
                strlen($offer['text']),
            ));

            $data = callExtractor($models, $caption, $offer['text']);
            $data = writeExtraction(
                EXT_DIR . "/$label.json",
                $data,
                $post,
                $offer['text'] ?: ($verdict['flyer_text'] ?? ''),
                $file,
            );
            $done++;

            out(sprintf('  %-24s %s', $label, $data['_missing'] === []
                ? 'OK'
                : 'kurang: ' . implode(',', $data['_missing'])));
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
 * @param  array<int, string> $sent
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

    $raw    = stream_get_contents($fh);
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
        $keep[$name]   = $bytes;
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
    $data['_media_id']    = $post['id'];
    $data['_permalink']   = $post['permalink'] ?? null;
    $data['_source']      = $post['_source_account'];
    $data['_posted_at']   = $post['timestamp'] ?? null;
    // Transkrip ikut disimpan: kalau harga meleset, ketahuan salahnya di mata (vision)
    // atau di penyusun (teks), tanpa perlu panggil ulang.
    $data['_flyer_text']  = $flyerText ?: null;
    $data['_used_vision'] = $flyerText !== '';
    $data['_useful_images'] = $flyerFile !== null ? [$flyerFile] : [];
    $data['_missing']     = missingFields($data);
    $data['_needs_review'] = $data['_missing'] !== []
        || ($data['confidence']['price'] ?? 0) < PRICE_CONFIDENCE_FLOOR;

    file_put_contents($target, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    out('    tulis storage/extracted/' . basename($target));

    return $data;
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
 * Carousel dikirim sebagai satu unit dalam satu call.
 *
 * @return array{post_kind: string, flyer_text: string}
 */
function readFlyer(array $images, string $caption = ''): array
{
    $content = [];
    foreach ($images as $b64) {
        $content[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . $b64]];
    }
    // Caption ikut dikirim sebagai konteks: angka yang buram di flyer sering
    // tertulis jelas di caption (daftar jadwal, kurs, nama hotel lengkap).
    // Aturan "jangan disalin, jangan menegakkan penanda" ada di promptnya.
    if ($caption !== '') {
        $content[] = ['type' => 'text', 'text' => "Caption postingannya (konteks saja):\n\n$caption"];
    }
    $content[] = ['type' => 'text', 'text' => TRANSCRIBE_PROMPT];

    $models = envList(env('VISION_MODEL'), 'gemini/gemini-3.1-flash-lite-preview');
    out(sprintf(
        '  POST %s model=%s (%d gambar, %.1f KB base64) -> transkrip',
        parse_url(routerUrl(), PHP_URL_HOST),
        implode('|', $models),
        count($images),
        array_sum(array_map('strlen', $images)) / 1024,
    ));

    $teks = llmPostAny($models, [
        'messages'        => [['role' => 'user', 'content' => $content]],
        'response_format' => ['type' => 'json_object'],
    ]);

    return visionVerdict(jsonOf($teks));
}

/**
 * Balasan vision -> putusan gerbang. Ketiga penanda ditegakkan di sini, bukan cuma
 * diminta di prompt: model sering menulis has_price=false lalu tetap melabeli
 * `package_offer`. Yang dipercaya penandanya, bukan labelnya.
 *
 * @return array{post_kind: string, flyer_text: string}
 */
function visionVerdict(array $out): array
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
        $slides[$n] = [
            'n'        => $n,
            'text'     => trim((string) ($slide['text'] ?? '')),
            'is_offer' => ($slide['has_price'] ?? false)
                && ($slide['has_date'] ?? false)
                && ($slide['has_duration'] ?? false),
        ];
    }
    ksort($slides);
    $slides = array_values($slides);

    $adaPenawaran = array_filter($slides, fn ($s) => $s['is_offer']) !== [];

    return [
        'post_kind' => ($kind === 'package_offer' && !$adaPenawaran) ? 'promo_generic' : ($kind ?: 'other'),
        'slides'    => $slides,
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
    $raw  = trim((string) ($raw ?? $default), " \t\n\r\"'");
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
 * @param string[] $models
 */
function llmPostAny(array $models, array $payload): string
{
    static $giliran = 0;

    $geser = $giliran++ % count($models);
    $urut  = array_merge(array_slice($models, $geser), array_slice($models, 0, $geser));

    foreach ($urut as $i => $model) {
        try {
            return llmPost(routerUrl(), need('AI_API_KEY'), ['model' => $model] + $payload);
        } catch (RuntimeException $e) {
            if ($i === count($urut) - 1) {
                throw $e;
            }
            out("    $model gagal: " . substr($e->getMessage(), 0, 120) . " — coba {$urut[$i + 1]}");
        }
    }

    throw new RuntimeException('Daftar model kosong');
}

/**
 * Penyusun: teks (caption + transkrip flyer) -> JSON. Tidak pernah menerima gambar.
 * Ganti provider = ganti AI_API_URL + AI_API_KEY + EXTRACT_MODEL.
 */
function callExtractor(array $models, string $caption, string $flyerText): array
{
    $prompt = "Caption postingan:\n\n" . ($caption === '' ? '(kosong)' : $caption);
    if ($flyerText !== '') {
        $prompt .= "\n\nTeks yang terbaca di flyer:\n\n$flyerText";
    }

    // Router mengabaikan `json_schema` (diuji 2026-07-29: prompt prosa tetap dibalas
    // prosa), jadi schema-nya dititipkan di prompt lalu hasilnya divalidasi sendiri.
    $payload = [
        'messages' => [
            [
                'role'    => 'system',
                'content' => SYSTEM_PROMPT . "\n\nBalas HANYA satu objek JSON yang patuh pada JSON Schema ini:\n"
                    . json_encode(extractionSchema(), JSON_UNESCAPED_SLASHES),
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
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_POST           => true,
            // Router suka memutus stream h2 di tengah balasan panjang
            // ("INTERNAL_ERROR (err 2)"). HTTP/1.1 tidak punya multiplexing yang
            // bisa direset sepihak begitu; satu koneksi satu request.
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        // Timeout/koneksi putus di tengah batch panjang: coba lagi, jangan buang 100 post sebelumnya.
        // Jeda naik 1/2/4s — ulang seketika biasanya kena kondisi yang sama.
        if ($body === false) {
            if ($try < 3) {
                $jeda = 1 << $try;
                out("    $err — ulangi dalam {$jeda}s");
                sleep($jeda);
                continue;
            }
            throw new RuntimeException("curl gagal: $err");
        }
        if ($code === 429 && $try < 5) {
            preg_match('/retry in ([\d.]+)/i', $body, $m);
            $wait = (int) ceil((float) ($m[1] ?? 30)) + 1;
            out("    429 rate limit, tunggu {$wait}s...");
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
            throw new RuntimeException("LLM HTTP $code: " . ($json['error']['message'] ?? substr($body, 0, 500)));
        }

        $teks = llmContent((string) $body);
        // Balasan kosong bukan "tidak ada data": router pernah balas 200 dengan galat
        // di badan, dan itu harus berisik, bukan jadi paket tanpa field.
        if (trim($teks) === '') {
            throw new RuntimeException('LLM balas kosong: ' . substr((string) $body, 0, 300));
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
        if ($baris === '' || $baris === '[DONE]' || !is_array($j = json_decode($baris, true))) {
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
        'duration_days'  => 9,
        'departure_city' => 'Jakarta',
        'price_tiers'    => [['occupancy' => 'quad', 'amount' => 25900000, 'currency' => 'IDR', 'is_starting_from' => false]],
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
    assert(!in_array('duration_days', missingFields(['duration_days' => 0] + $full), true));

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
    assert(!in_array('guide_name', REQUIRED_FIELDS, true), 'guide_name jangan masuk gate');
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
    assert(visionVerdict(['post_kind' => 'education'])['post_kind'] === 'education', 'label non-paket dibiarkan');

    // Carousel: tiap gambar penawaran berdiri sendiri, gambar dakwah tidak ikut.
    $campur = visionVerdict(['post_kind' => 'package_offer', 'slides' => [
        ['n' => 2, 'has_price' => true, 'has_date' => true, 'has_duration' => true, 'text' => 'syawal'],
        ['n' => 1, 'has_price' => true, 'has_date' => true, 'has_duration' => true, 'text' => 'ramadhan'],
        ['n' => 3, 'has_price' => false, 'has_date' => false, 'has_duration' => false, 'text' => 'dakwah'],
    ]]);
    assert(array_column($campur['slides'], 'n') === [1, 2, 3], 'slide wajib urut nomor gambar');
    assert(array_column($campur['slides'], 'is_offer') === [true, true, false], 'penawaran dinilai per gambar');
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
        . "data: {\"choices\":[{\"delta\":{\"content\":\"{\\\"a\\\":\"}}]}\n\n"
        . "data: {\"choices\":[{\"delta\":{\"content\":\"1}\"}}]}\n\n"
        . "data: [DONE]\n"
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
        'auth'     => cmdAuth($argv),
        'fetch'    => cmdFetch($argv),
        'fetchall' => cmdFetchAll($argv),
        'seed'     => cmdSeed($argv),
        'extract'  => cmdExtract($argv),
        'selftest' => cmdSelftest(),
        default    => out(
            "php probe.php auth <short_lived_user_token>\n" .
            "php probe.php fetch <username> [--limit=50]\n" .
            "php probe.php fetchall [accounts.txt] [--limit=50] [--sleep=3] [--retry]\n" .
            "php probe.php seed <dir>\n" .
            "php probe.php extract [--limit=200] [--force]\n" .
            "php probe.php selftest"
        ),
    };
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
