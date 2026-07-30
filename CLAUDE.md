# umroh-sakti

Portal agregator paket umroh. Menarik postingan travel dari Instagram, mengekstrak
flyer + caption jadi data terstruktur, lalu menyajikannya untuk dicari dan dibandingkan.

## Stack

Laravel 12 + SQLite · Anthropic SDK (ekstraksi). Tanpa Filament, tanpa login —
review & pratinjau jalan di halaman `/` (dikunci ke env local).
Redis/Horizon, R2, dan Meilisearch ada di rencana tapi **belum dipasang** — jangan
asumsikan tersedia.

## Aturan yang mengikat

**Gate akurasi sudah lewat; `probe.php score` dibuang.** Ukuran akurasinya sekarang
melekat di data: `_missing` (field wajib yang kosong, lihat `REQUIRED_FIELDS`) dan
`_needs_review` per hasil ekstraksi, plus koreksi manusia dari halaman review yang
dipanen ke `storage/feedback.jsonl`. Itu bahan perbaikan prompt — bukan angka
sekali jalan.

**Post yang sudah dibuang jangan di-scrap lagi.** Tabel `banned_posts` (media_id,
reason) diisi saat post ditolak: `bukan_paket`, `sebelum_ambang`, `manual` (tombol ×).
`probe.php` membacanya langsung lewat PDO — `fetch` tidak men-download ulang dan
`extract` tidak membayar model dua kali. Kecuali: tombol × pada satu slide
carousel yang slide lainnya masih jadi paket cuma menghapus barisnya — post-nya
tidak dibanned dan rawnya tidak dipindah ke trash, karena folder raw itu dipakai
bersama dan sibling-nya akan kehilangan flyer. Konsekuensinya `--limit` menghitung post
*baru*: 9 post dengan 2 dibanned = ambil 7 sisanya, bukan 9.

**Keberangkatan sebelum `config('umroh.min_departure')` tidak diambil** (default
`2026-08-01`, ubah lewat `UMROH_MIN_DEPARTURE`). Post-nya sekalian dibanned.
Tanggal kosong tetap lolos — belum bisa dinilai, biar manusia yang lihat.

**Jangan auto-publish harga.** Harga dengan confidence < 0.8 atau field wajib
kosong wajib masuk review queue. Tidak ada jalur yang melewati review manusia.

**Harga USD dikonversi ke IDR saat import, bukan saat ekstraksi.** Sebagian
travel memasang "USD 3.300" di flyer. Ekstraksi menulisnya apa adanya
(`amount` 3300, `currency` USD); `ImportExtractedPackages::toIdr()` mengalikan
`config('umroh.usd_rate')` (`UMROH_USD_RATE`, default 16500) supaya kolom harga
selalu satu satuan — kalau tidak, angkanya tampil "0,0 jt", selalu jadi paket
termurah saat diurutkan, dan selalu memicu warning BPIU. Angka asli tetap di
`raw_extraction`; UI menandai barisnya "konversi dari USD".

**Satu paket = satu baris.** Tier harga tetap empat kolom di `packages`:
`price_quad`/`price_triple`/`price_double`/`price_single` (`Package::PRICE_COLUMNS`) —
jangan gabungkan jadi satu angka, jangan bikin tabel tier lagi.

**Dua tabel saja: `source_accounts` + `packages`.** Semua komponen paket ikut di
baris paket — hotel (`hotel_makkah`/`hotel_madinah` apa adanya dari flyer +
`nights_*`), fasilitas (kolom JSON), dan akun yang repost (kolom JSON `reposts`).
Jangan bikin tabel master hotel lagi dan jangan bikin tabel anak. Data PPIU
tidak disimpan di paket — izinnya menyusul di `source_accounts`.

**Jangan scraping unofficial.** Tidak ada headless browser, tidak ada library
pihak ketiga. Hanya Instagram Graph API resmi.

**Jangan simpan `media_url`.** Itu signed CDN URL yang expire dalam hitungan hari.
Download saat ingest.

**Jangan re-host flyer.** Gambar full hanya untuk ekstraksi & audit internal.
Yang tampil ke publik: data hasil ekstraksi + thumbnail kecil + link ke post asli.

**Satu gambar carousel = satu paket.** Satu carousel sering memuat beberapa paket
berbeda (gambar 1 Ramadhan, gambar 2 Syawal). Vision menilai penanda
harga/tanggal/durasi **per gambar**, dan tiap gambar penawaran disusun sendiri jadi
barisnya sendiri: `storage/extracted/{media_id}-{n}.json`, kolom `flyer_index`,
unique `(media_id, flyer_index)`. Gambar yang bukan penawaran tidak pernah dikirim
ke penyusun. Konsekuensinya: paket yang informasinya TERSEBAR di beberapa gambar
(harga di slide 1, tanggal di slide 2) ditolak di gerbang — penandanya dinilai per
gambar, bukan digabung.

**Mayoritas postingan travel bukan penawaran paket.** Daftar hotel, manasik,
testimoni, ucapan hari besar. Model penyusun wajib memutuskan `post_kind` dulu;
kalau bukan `package_offer`, semua field data dikosongkan. `packages:import`
menyaring dua lapis: label itu, plus cek struktural — harus ada sinyal
keberangkatan (tanggal / durasi / harga > 0) DAN minimal satu sinyal lain.
Nama hotel saja tidak cukup.

**Deduplikasi per paket, bukan per akun.** Satu paket PPIU diposting ulang oleh
puluhan agen. Dedup key: `(departure_date, hotel_makkah, hotel_madinah, airline)`
— tanpa penanda travel, jadi dua travel dengan tanggal + hotel + maskapai identik
akan menyatu; tambahkan penandanya begitu izin melekat ke `source_accounts`.
Post asal ekstraksi ada di kolom `media_id`/`source_account` paket
(sekaligus penunjuk folder flyernya); akun yang memposting ulang masuk kolom
JSON `reposts` — audit saja, idempoten per `media_id`.

## probe.php

Tool berdiri sendiri untuk langkah 1-3. Tidak bergantung pada Laravel.

```bash
php probe.php auth <short_lived_token>   # tukar jadi long-lived Page Token
php probe.php fetch <username> --limit=50
php probe.php seed <dir>                 # ingest caption manual (.txt + .jpg)
php probe.php extract                    # caption-first, vision kalau perlu
php -d zend.assertions=1 probe.php selftest
```

Definisi "berhasil dinormalisasi" ada di konstanta `REQUIRED_FIELDS`.
Ambang review harga ada di `PRICE_CONFIDENCE_FLOOR`.

## Instagram Graph API

Pakai **Instagram API with Facebook Login** (`graph.facebook.com`, token `EAA…`).
Business Login for Instagram (`graph.instagram.com`, token `IGAA…`) **tidak punya**
endpoint `business_discovery` — sudah diuji, gagal.

Lima scope wajib. Yang paling sering kelewat: **`instagram_manage_insights`** —
tanpa itu error `#10 Application does not have permission`.

```
instagram_basic  instagram_manage_insights  pages_show_list
pages_read_engagement  business_management
```

Kode error yang sudah dipetakan di `graphGet()`: `#10` scope kurang, `#110`
username salah atau bukan akun Professional, `#190` token mati, `#4/17/32` rate
limit tingkat app.

Batasan: akun target wajib Professional (personal tidak terbaca sama sekali),
tidak ada akses Story, media ID tidak bisa di-GET terpisah, rate limit berbasis
app sehingga semua request menumpuk di satu token.

## Media

Hanya `IMAGE` yang di-download. `VIDEO`/`REELS` dilewat — `thumbnail_url`
tersedia tapi hanya satu frame yang belum tentu memuat flyer, rawan menghasilkan
ekstraksi ngawur. Di carousel campuran, child video dibuang dan child gambar
tetap diambil. Carousel yang isinya video semua tidak menghasilkan gambar.

Carousel dikirim ke vision sebagai **satu unit** dalam satu call (hemat), tapi
hasilnya **per gambar**: `slides[{n, text, has_price, has_date, has_duration}]`.
Penyusun dipanggil sekali per gambar penawaran, jadi satu carousel bisa
menghasilkan beberapa paket. Panjang teks bukan penentu — slide dakwah sering
lebih panjang dari flyernya.

## Ekstraksi

Caption dulu — 60-70% info ada di sana dan jauh lebih murah. Vision hanya
dipanggil kalau field wajib masih kosong setelah pass caption. Gambar yang
hash-nya sudah pernah diproses dilewat (flyer rebranding dari puluhan akun agen).

**Caption ikut dikirim ke vision**, bukan cuma ke penyusun: dipakai membaca yang
buram/disingkat di flyer. Tapi cuma konteks — caption tidak boleh disalin ke
`slides[].text` dan tidak menegakkan `has_price/has_date/has_duration`. Jadwal
yang cuma ada di caption (munatour memuat enam tanggal di caption, satu per
gambar di flyer) tetap tidak lolos gerbang untuk gambar yang tidak memuatnya.

Semua panggilan model lewat **9router** (`AI_API_URL`, OpenAI-compatible): satu URL
+ satu `AI_API_KEY`, yang beda cuma `EXTRACT_MODEL` (penyusun) dan `VISION_MODEL`
(mata). Nama model wajib berprefix provider — nama polos diarahkan ke `openai`.
Keduanya boleh berisi **daftar JSON berkutip** (`'["ds/x","openrouter/y"]'`):
`llmPostAny()` memakainya bergiliran (round-robin, kuota free tier kebagi) dan
melewati model yang galat ke berikutnya; kalau semuanya mati galat terakhir dilempar.
`llmPost()`/`llmContent()` satu-satunya titik sentuh HTTP; ganti provider = ubah
env, bukan kode. Schema (`extractionSchema()`) dan prompt tidak ikut berubah.

Tiga kejanggalan router yang sudah ditangani `llmContent()`: balasan bisa SSE walau
`stream` tidak diset, `data: [DONE]` dilem ke ekor JSON **tanpa newline**, dan
sebagian provider membungkus hasil di `{"data":{"choices":…}}`. `response_format:
json_schema` diabaikan router — makanya schema dititipkan di prompt. Model tanpa
vision: gambarnya dibuang diam-diam dan tetap balas HTTP 200 (pernah mengarang
harga) — uji model vision baru dengan flyer yang jawabannya sudah diketahui.

Raw (gambar + caption + JSON mentah) disimpan terpisah dari hasil ekstraksi,
supaya bisa re-extract saat prompt membaik tanpa crawl ulang.

## Regulasi

Tidak ada data PPIU di paket dan tidak ada peringatan izin — izin travel nanti
melekat ke `source_accounts` (satu akun IG = satu travel), bukan ke tiap baris
paket. Paket di bawah BPIU Referensi Kemenag memicu warning
otomatis — **jangan disembunyikan**, angkanya disimpan sebagai config yang bisa
diubah. Setiap listing menampilkan "data per {tanggal}, konfirmasi ke travel"
plus permalink sumber.

## Di luar scope

Story, hashtag search, akun personal, booking, pembayaran. Submit username dari
user masuk antrian approval — tidak ada fitur "masukin username apa saja langsung
crawl".

## Perintah Laravel

```bash
php artisan serve                  # http://localhost:8000
php artisan queue:work             # SEMUA antrian sekaligus, paralel (Ctrl+C berhenti)
php artisan test                   # 32 test: import+dedup+ambang, filter publik, regulasi, halaman akun
php artisan packages:import        # storage/extracted/*.json -> database
php artisan migrate:fresh --seed
```

**Tiga antrian, tidak tunggu-tungguan.** `queue:work` ditimpa oleh
`App\Console\Commands\QueueWork` yang **meng-extend** `WorkCommand` bawaan:
tanpa `--queue` dia jadi induk dan spawn satu proses anak per antrian; dengan
`--queue` dia jalan sebagai worker biasa (`parent::handle()`). Anak selalu
dipanggil dengan `--queue=`, jadi tidak mungkin spawn beranak-pinak. Semua opsi
bawaan (`--tries`, `--memory`, `--max-time`) tetap berlaku.

| antrian | worker | job | kenapa |
|---|---|---|---|
| `ig` | 1 | `FetchAccount` | rate limit Graph API tingkat app, paralel = kena `#4` |
| `ai` | N (`--ai=3`) | `ExtractPost` | bagian paling lama, ini yang dibikin paralel |
| `db` | 1 | `ExtractPending`, `ImportPackages` | cepat, dan SQLite satu penulis |

Akun baru yang masuk ke `ig` tidak menghentikan konversi di `ai`; fetch yang kena
rate limit tidak menahan import.

Worker yang di-kill di tengah job meninggalkan lock `unique` di tabel `cache_locks`
sampai `uniqueFor` habis (120s) — job berikutnya kelihatan hilang diam-diam.
`DB::table('cache_locks')->delete()` kalau perlu buru-buru. Catatan: `queue:clear`
cuma membersihkan antrian `default`, sebutkan `--queue=ig|ai|db`.

Urutan & rentang tanggal di `/` lewat query string: `?sort=` (whitelist
`PackageSearchController::SORTS` — `date`/`date_desc`/`price`/`price_desc`, nilai
asing balik ke `date`) dan `?from=`/`?to=` (inklusif, boleh salah satu saja).
Urut harga memakai tier terisi yang **termurah**, dan paket tanpa harga sama sekali
selalu di bawah — di kedua arah.

Daftar akun sumber di `/akun` (link dari panel pipeline): masukin username/URL/@handle
satu per baris (parsernya `SourceAccount::usernameOf()`, dipakai juga oleh
`packages:crawl`), lihat status + `last_fetched_at` + jumlah post/paket/banned per akun,
plus tombol `scrap` per akun dan `Scrap semua` (lewat `packages:crawl --limit=9`, sama
seperti tombol pipeline di `/`, cuma tanpa seed `accounts.txt`). Akun yang belum pernah
di-scrap naik ke atas. Akun yang ditambah dari sini langsung `approved` — operator lokal
memang si pemberi approval.

Review, pratinjau, dan tombol pipeline semuanya di `/` — `abort_unless(isLocal())`,
jadi tidak pernah kebuka di produksi. Tidak ada login dan tidak ada tabel `users`.
Manajemen datanya langsung ke SQLite (`database/database.sqlite`) atau `artisan tinker`.

`/pipeline/status` mengembalikan angka progres, `sekarang` (baris terakhir per
antrian), dan `log` (80 baris terakhir). Sumbernya satu file: `storage/pipeline.jsonl`,
ditulis `App\Support\PipelineLog`. Isinya stdout `probe.php` apa adanya — request ke
`graph.facebook.com`, tiap gambar yang di-download beserta nama file & ukurannya,
request ke model vision & penyusun beserta host + HTTP code + durasi, dan nama file
hasil ekstraksi. Panel di `/` menampilkannya di `<details> jejak detail`.

Catatan: stdout `queue:work` sendiri block-buffered saat dipipe, jadi baris
"Processing:" bisa muncul terlambat. Baris detail tidak terpengaruh — job menulis
langsung ke file, bukan lewat stdout worker.

Redis/Horizon belum dipasang; queue pakai driver `database` (jalankan
`php artisan queue:work`).

## Alur kerja

```
php artisan queue:work          satu perintah, tiga antrian jalan paralel
   |
   +-- ig  FetchAccount    probe.php fetch    (lewati yang sudah dibanned)
   +-- ai  ExtractPost     probe.php extract  (vision -> penyusun per gambar)
   +-- db  ExtractPending  pindai raw -> antrikan ke ai
           ImportPackages  packages:import --prune -> DB + banned_posts
                    |
          / (review + pratinjau lokal)  ->  status=published  ->  / (publik)
```

Pemicunya: tombol di `/` (atau `php artisan packages:crawl accounts.txt`) yang cuma
mengantrikan job. Tidak ada langkah manual di antara fetch, extract, dan import —
`ig` menyelesaikan satu akun, `db` langsung memindainya ke `ai`, dan `ai` tidak
menunggu akun berikutnya.

Paket masuk sebagai `review` atau `draft`, tidak pernah langsung `published`.
Publish = ubah `status` jadi `published` langsung di DB, setelah datanya dilihat.
