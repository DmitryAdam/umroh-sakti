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

**Post yang sudah dibuang jangan di-scrap lagi.** Tabel `excluded_posts` (media_id,
reason) diisi saat post ditolak: `bukan_paket`, `haji`, `sebelum_ambang`, `manual` (tombol ×).
`probe.php` membacanya langsung lewat PDO — `fetch` tidak men-download ulang dan
`extract` tidak membayar model dua kali. Kecuali: tombol × pada satu slide
carousel yang slide lainnya masih jadi paket cuma menghapus barisnya — post-nya
tidak dikecualikan dan rawnya tidak dihapus, karena folder raw itu dipakai
bersama dan sibling-nya akan kehilangan flyer. Konsekuensinya `--limit` menghitung post
*baru*: 9 post dengan 2 dikecualikan = ambil 7 sisanya, bukan 9.

**Post yang ditolak filenya dihapus, tidak diarsipkan.** Yang menjaganya tidak
di-scrap lagi itu baris `excluded_posts`, bukan filenya — jadi menyimpan raw-nya
"buat jaga-jaga" cuma numpuk byte. Terukur 2026-07-30: `storage/trash` 1,9 GB
untuk 1019 folder yang tidak pernah dibaca kode mana pun, sementara `storage/raw`
yang benar-benar dipakai cuma 387 MB. Folder trash-nya juga menyesatkan — ia
selamat dari `migrate:fresh` sementara `excluded_posts` tidak, jadi hitungannya
melar (1019 folder vs 798 baris). `Package::deleteSources()` dan
`ImportExtractedPackages::prune()` sama-sama menghapus, dan corong pipeline
menghitung `excluded_posts`, bukan folder.

Konsekuensinya: audit visual "kenapa post ini ditolak" hilang. Yang tersisa
`reason` di `excluded_posts` + `storage/feedback.jsonl`. Kalau flyernya perlu
dilihat lagi, fetch ulang — dan itu berarti hapus dulu barisnya di
`excluded_posts`.

**Jangan re-host gambar ke CDN dan jangan hotlink `scontent`.** Dua-duanya sudah
dipertimbangkan sebagai jalan keluar dari beratnya `storage/raw`, dua-duanya
salah: hotlink signed URL mati dalam hitungan hari (lihat aturan `media_url` di
bawah), dan menaruh flyer full di CDN publik itu persis re-host yang dilarang —
byte-nya tetap dibayar, cuma pindah tagihan.

**Keberangkatan sebelum `config('umroh.min_departure')` tidak diambil** (default
`2026-08-01`, ubah lewat `UMROH_MIN_DEPARTURE`). Post-nya sekalian dikecualikan.

**Tanggal dan harga itu syarat masuk DB** (`belumLengkap()`, reason `tanpa_tanggal`
/ `tanpa_harga`). Tanpa salah satunya paket tidak bisa dicari, diurut, atau
dibandingkan — itu seluruh gunanya portal ini. Tanggal minimal **bulan** (`Y-m`,
lihat aturan tanggal di bawah); harga minimal satu tier > 0.

Bedanya dengan penolakan yang lain: yang ini **tidak** mengecualikan postnya dan
tidak menghapus rawnya. Harga yang gagal terbaca itu kegagalan model,
bukan vonis atas postnya — kalau ikut masuk `excluded_posts`, flyer yang cuma
salah baca hilang selamanya begitu prompt-nya membaik. Filenya dibiarkan di
`storage/extracted`; import berikutnya mencobanya lagi, gratis. Konsekuensinya
angka `hasil_ekstraksi` di corong memang boleh lebih besar dari `paket`.

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

**Haji khusus bukan umroh — ditolak di import** (`isHaji()`, reason `haji`).
Penanda travel **bukan** penandanya: "UMROH & HAJI PLUS" dan "UMRAH & HAJI KHUSUS"
itu kop surat, paketnya umroh beneran — 34 dari 35 baris yang memuat frasa itu
ternyata umroh. Yang dipakai cuma istilah **produk** haji yang tidak pernah nyangkut
di nama PT (`visa haji`, `nomor porsi`, `porsi haji`, `maktab`, `haji furoda`,
`badal haji`), dan itu pun masih harus ditemani satu sinyal ukuran: durasi ≥ 18 hari
atau harga ≥ 100 juta. Terukur atas 279 hasil ekstraksi: kena 3, ketiganya haji,
nol salah tangkap.

**Tanggal: `Y-m-d` apa adanya, `Y-m` jadi tanggal 1, sisanya kosong**
(`Package::tanggal()`). Flyer haji sering menulis tahun saja ("Berangkat Tahun
2027") dan model menyalinnya apa adanya; cast `date` Eloquent membaca "2027"
sebagai unix timestamp — 2027 detik — jadi barisnya masuk bertanggal
`1970-01-01 07:33`, terurut paling awal dan lolos semua filter rentang.

Bulan tanpa tanggal ("Maret 2027") sengaja **tidak** dibuang — 19 dari 269 baris
begitu, dan tanggal 1 masih bisa diurut & difilter rentang. Yang membedakannya
`date_certainty` = `month`, dipaksa oleh `Package::kepastian()` apa pun kata
model: angka tanggalnya hasil normalisasi kita, jangan pernah dilabeli `exact`.

**Kota berangkat kosong = Jakarta.** Flyer paling sering tidak menulisnya karena
CGK dianggap sudah tahu. Diisi saat import, bukan saat ekstraksi — angka aslinya
(kosong) tetap di `raw_extraction`, dan cek struktural `isPackageOffer()` menilai
data mentah, jadi default ini tidak pernah menjadi sinyal "ini paket".

**Tanggal pulang dihitung, tidak disimpan.** `Package::returnDate()`/`dateLabel()`:
"9 hari" itu inklusif, berangkat 15 Agustus = pulang 23 Agustus (`+durasi-1`).
Kartu dan detail menampilkan rentangnya supaya orang tidak menghitung sendiri.

**Slide yang ditolak tidak menyeret postnya kalau slide lain jadi paket.**
Penolakan (`buang()`) dieksekusi **setelah** seluruh file diimpor, bukan di tengah
loop — urutan file tidak dijamin, slide yang laku bisa diproses belakangan. Folder
raw itu dipakai bersama satu carousel: kalau ikut dihapus, `Package::flyers()`
yang glob ke `storage/raw` balik kosong dan paket saudaranya tampil **tanpa gambar**
(pernah kejadian, 6 post / 14 paket). Mengecualikan `media_id`-nya juga salah — itu
memblokir fetch + extract untuk slide yang justru laku. Aturan yang sama sudah lama
berlaku untuk tombol × (`PackageSearchController::destroy`).

**Mayoritas postingan travel bukan penawaran paket.** Daftar hotel, manasik,
testimoni, ucapan hari besar. Model penyusun wajib memutuskan `post_kind` dulu;
kalau bukan `package_offer`, semua field data dikosongkan. `packages:import`
menyaring dua lapis: label itu, plus cek struktural — harus ada sinyal
keberangkatan (tanggal / durasi / harga > 0) DAN minimal satu sinyal lain.
Nama hotel saja tidak cukup.

**Aturan tanggal dijaga di setter model, bukan cuma di import.** `Package::tanggal()`
dipanggil dari `setDepartureDateAttribute()`, jadi tinker, kode baru, dan worker yang
masih memegang kode lama kena aturan yang sama. Perlu, karena artefak 1970 pernah
masuk **setelah** `tanggal()` diperbaiki: workernya belum di-restart.

**Deduplikasi per paket, bukan per akun.** Satu paket PPIU diposting ulang oleh
puluhan agen. Dedup key: `(departure_date, hotel_makkah, hotel_madinah, airline)`
— tanpa penanda travel, jadi dua travel dengan tanggal + hotel + maskapai identik
akan menyatu; tambahkan penandanya begitu izin melekat ke `source_accounts`.

Tiga field terakhir dilewatkan `Package::fold()` dulu: tanda baca, spasi, kata
sandang "al", dan huruf **h** dibuang, lalu tokennya diurut. Transliterasi flyer
tidak konsisten dan pemisah daftar juga tidak — akun yang sama pernah memposting
paket yang sama dua kali sebagai "Qashr Al Anshar" + "Saudia, Garuda Indonesia"
lalu "Qasr Al Anshar" + "Saudia / Garuda Indonesia", dan tanpa fold itu jadi dua
baris (#76 dan #89). Konsekuensinya kunci dedup **berubah** kalau fold-nya diubah:
hitung ulang `dedup_key` seluruh baris lama, kalau tidak baris baru tidak ketemu
pasangannya dan dupe-nya lahir lagi.
Post asal ekstraksi ada di kolom `media_id`/`source_account` paket
(sekaligus penunjuk folder flyernya); akun yang memposting ulang masuk kolom
JSON `reposts` — audit saja, idempoten per `media_id`.

Yang jadi **identitas baris** tetap `(media_id, flyer_index)`, bukan `dedup_key`.
`importOne()` mengecek pasangan itu dulu dan melewatinya kalau sudah ada:
`dedup_key` ikut berubah begitu ekstraksi ulang membaca "Saudia" jadi "Saudia
Airlines", jadi cari lewat `dedup_key` saja = baris lama tidak ketemu, `create()`
menabrak UNIQUE, dan exception-nya membatalkan **sisa backlog** — bukan cuma file
itu. Barisnya sengaja tidak ditimpa: `status` di situ hasil review manusia.

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
app.

**Rate limit per app, jadi banyak app = banyak kuota.** `IG_ACCESS_TOKEN` (dan
kalau perlu `IG_USER_ID`) boleh berisi daftar JSON berkutip, sepasang per Meta App
— `envList()` yang membacanya, sama seperti `EXTRACT_MODEL`/`VISION_MODEL`.
Satu Page yang di-link ke beberapa app cukup satu `IG_USER_ID`; `igPair()`
memakainya ulang untuk semua token, dan menolak jumlah yang tidak sepadan (salah
pasang cuma kelihatan sebagai `#190`, bukan sebagai salah konfigurasi). `fetch`
memilih app dari `crc32(username)` — satu proses = satu akun, jadi giliran tidak
bisa dititip ke variabel static seperti `llmPostAny()` — lalu pindah app kalau
kena `#4`, mengulang halaman yang sama. Semua app habis = galat dilempar, dan
`FetchAccount` yang menunggu 5 menit.

**Yang mengikat itu `total_time`, bukan jumlah request.** `graphGet()` mencatat
header `x-app-usage` ke jejak pipeline; isinya tiga persen. Terukur 2026-07-30:
app kena `#4` saat `call_count` baru 25% tapi `total_time` sudah 136%. Jendelanya
bergulir 1 jam. Konsekuensinya jeda **tidak** menurunkan konsumsi — cuma
meratakan; yang menurunkan cuma request yang lebih murah (field lebih sedikit,
ekspansi `children{}` lebih ringan). Kuotanya benar-benar terpisah per app
walau Page-nya sama — terbukti tiga nilai berbeda di request berturut-turut,
jadi nambah app memang nambah kuota. `x-business-use-case-usage` tidak pernah
dikirim di jalur ini.

Setiap request ke `graph.facebook.com` didahului jeda `IG_FETCH_SLEEP` detik
(default 3, boleh pecahan, override `--sleep=`). Jedanya di loop `cmdFetch()` —
satu-satunya tempat yang memanggil Graph di jalur fetch — jadi berlaku untuk
tombol `/`, `packages:crawl`, `FetchAccount`, maupun `fetchall`. Karena antrian
`ig` cuma satu worker dan satu proses = satu akun, jeda itu sekaligus jarak
antar-halaman **dan** antar-akun. Download flyer tidak dijeda: itu ke CDN
`scontent`, bukan Graph, kuotanya beda.

`META_APP_ID`/`META_APP_SECRET` **cuma dipakai `probe.php auth`** untuk menukar
token, satu app per jalan — bukan oleh `fetch`. Keduanya juga boleh daftar JSON
berkutip, urutannya sama dengan `IG_ACCESS_TOKEN`, dan `auth <short_token> --app=N`
memilih slotnya (default 0); `metaApp()` menolak jumlah id vs secret yang tidak
sepadan. Nambah app: tambahkan id + secret-nya ke dua daftar itu, jalankan `auth
--app=<slot baru>`, terus token hasilnya ditaruh di slot yang sama di
`IG_ACCESS_TOKEN`.

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

**Tanggal posting ikut dikirim ke penyusun** (`callExtractor($…, $postedAt)`).
Flyer sering menulis "14 Maret" tanpa tahun; tanpa jangkar itu model memakai tahun
berjalan dan paket 2027 masuk sebagai 2026 — lolos ambang keberangkatan. Aturannya
di prompt: ambil kejadian pertama SETELAH tanggal posting, tidak ada tanggal
posting berarti `null`.

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
php artisan test                   # 47 test: import+dedup+ambang, filter publik, regulasi, halaman akun
php artisan packages:import        # storage/extracted/*.json -> database
php artisan migrate:fresh --seed
```

**Tiga antrian, tidak tunggu-tungguan.** `queue:work` ditimpa oleh
`App\Console\Commands\QueueWork` yang **meng-extend** `WorkCommand` bawaan:
tanpa `--queue` dia jadi induk dan spawn satu proses anak per antrian; dengan
`--queue` dia jalan sebagai worker biasa (`parent::handle()`). Anak selalu
dipanggil dengan `--queue=`, jadi tidak mungkin spawn beranak-pinak. Semua opsi
bawaan (`--tries`, `--memory`, `--max-time`) tetap berlaku.

**Induk menunggu lewat `running()`, jangan `wait()`.** `InvokedProcessPool::wait()`
itu `->map->wait()`: induk mengantre di anak pertama (`ig`) yang tidak pernah selesai
dan tidak pernah membaca pipe anak lain. Begitu buffer stdout 64 KB penuh, `ai`/`db`
kebentur `write()` — antrian kelihatan mati padahal jobnya tersedia dan prosesnya
hidup di `ps` (terukur: `db` beku 45 menit, 0% CPU, stack-nya berhenti di `write`).
`running()` memaksa `readPipes()` di semua anak, jadi pipe-nya ikut terkuras.
Anak yatim dari induk yang di-kill kena hal sama — induknya tidak ada yang menguras,
jadi `pkill -9 -f "artisan queue:work"` dulu sebelum start ulang, jangan cuma induknya.

**Anak yang keluar dinyalakan lagi** (`spawn()` dipanggil ulang di loop `handle()`).
`--max-time=3600` maunya restart berkala, tapi `Process::pool` tidak pernah
menyalakan ulang: sejam sekali seluruh worker mati diam-diam. Yang lebih berbahaya,
sampai mati itu mereka menahan **kode lama di memori** — worker yang start sebelum
sebuah bug diperbaiki tetap menjalankan versi bugnya sampai di-restart. Pernah
kejadian dua kali di hari yang sama: `ImportPackages` dari antrian mem-prune pakai
aturan yang sudah diperbaiki 20 menit sebelumnya. **Sesudah mengubah kode job atau
command, restart workernya** — CLI (`php artisan packages:import`) memakai kode baru,
worker tidak.

**SQLite-nya `IMMEDIATE`, bukan `DEFERRED`** (`config/database.php`). Transaksi
deferred mulai sebagai pembaca lalu naik jadi penulis; upgrade itu balas
`SQLITE_BUSY` seketika (BUSY_SNAPSHOT) dan **tidak** menghormati `busy_timeout`,
jadi `database is locked` datang dalam 2 ms padahal timeout-nya 10 detik.

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

Urutan & filter di `/` semuanya query string, satu form GET tanpa JS framework.
`?sort=` (whitelist `PackageSearchController::SORTS` — `date`/`date_desc`/`price`/
`price_desc`, nilai asing balik ke `date`). Urut harga memakai tier terisi yang
**termurah**, dan paket tanpa harga sama sekali selalu di bawah — di kedua arah.

Filternya: `?from=`/`?to=` (inklusif, boleh salah satu saja), `duration_min`/
`duration_max`, `min_price`/`max_price` (rupiah, kena ke tier mana saja),
`hotel` (LIKE ke dua kolom hotel), `q` (LIKE ke pembimbing/hotel/kota/maskapai),
plus facet pilihan-tertutup di
`PackageSearchController::FACETS` (`city`, `airline`, `akun`, `extension`,
`certainty`, `status`).

**Pilihan facet ditarik dari data, jangan ditulis manual.** Nama maskapai di flyer
punya belasan ejaan ("Saudia", "Saudia Airlines", "Saudi Airlines"), jadi daftar
tetap pasti kelewat dan pasti memuat opsi yang hasilnya nol. `facets()` group-by
tiap kolom + jumlahnya, terbanyak dulu. Dihitung dari himpunan **dasar** (cuma
scope status), bukan dari hasil yang sudah difilter: kalau ikut menyempit, memilih
satu kota membuang kota lain dari daftar dan pilihannya tidak bisa diganti tanpa
reset. Select dengan < 2 pilihan tidak dirender (`status` saat publik).

Klik judul kartu membuka **lightbox** `<dialog>` yang mengambil `/paket/{id}` lewat
fetch; `show()` membalas `partials.detail` (potongan yang sama, tanpa layout) kalau
`$request->ajax()`. `href`-nya tetap URL asli, jadi klik-tengah/tanpa JS tetap dapat
halaman penuh.

Panel "catatan & jejak" per kartu (form `review_verdict` + `review_note`) sementara
dilepas dari UI. Endpoint `POST /paket/{id}/feedback` dan kolomnya masih ada.

Daftar akun sumber di `/akun` (link dari panel pipeline): masukin username/URL/@handle
satu per baris (parsernya `SourceAccount::usernameOf()`, dipakai juga oleh
`packages:crawl`), lihat status + `last_fetched_at` + jumlah post/paket/dikecualikan per akun,
plus tombol `scrap` per akun dan `Scrap semua` (lewat `packages:crawl --limit=9`, sama
seperti tombol pipeline di `/`, cuma tanpa seed `accounts.txt`). Akun yang ditambah dari
sini langsung `approved` — operator lokal memang si pemberi approval.

Urutannya: yang **gagal** paling atas, lalu yang belum pernah di-scrap, sisanya menurut
`last_fetched_at`. Alasan gagal ada di kolom `source_accounts.last_error` — diisi saat
fetch gagal, dikosongkan saat berhasil, jadi isinya status percobaan terakhir dan bukan
riwayat. `last_fetched_at` tetap penanda terakhir **berhasil**, jadi satu baris bisa
menampilkan dua-duanya sekaligus.

Yang perlu diingat soal `$this->fail()`: dia menandai job gagal tapi **tidak
menghentikan `handle()`**. Tanpa `return` sesudahnya, akun yang gagal ikut distempel
`last_fetched_at` dan kelihatan baru saja berhasil di-scrap — itu bug yang pernah
kejadian, dijaga oleh test `fetch_gagal_tidak_dianggap_berhasil`.

`last_error` diisi di **satu tempat saja: hook `FetchAccount::failed()`**, bukan di
cabang if `handle()`. Alasannya kegagalan yang paling sering justru tidak lewat cabang
itu — `database is locked` dari SQLite, timeout Process, exception liar — dan 19
kegagalan pertama semuanya jenis itu, tanpa jejak apa pun di halaman akun.

Retry-nya beda per penyebab: **semua** app kena rate limit → `release(300)`, diulang
tiap 5 menit sampai `retryUntil` 2 jam habis. Username salah / token mati / response
ditolak → `fail()` sekali, masuk `failed_jobs`, **tidak** diulang sendiri; jalankan
`php artisan queue:retry all` atau tekan `scrap` lagi.

Review, pratinjau, dan tombol pipeline semuanya di `/` — `abort_unless(isLocal())`,
jadi tidak pernah kebuka di produksi. Tidak ada login dan tidak ada tabel `users`.
Manajemen datanya langsung ke SQLite (`database/database.sqlite`) atau `artisan tinker`.

`/pipeline/status` mengembalikan corong progres, `sekarang` (baris terakhir per
antrian), dan `log` (80 baris terakhir). Corongnya: `akun`/`terfetch`/`akun_gagal`
→ `post_diunduh` → `post_menunggu` (+`antri_ai`) → `post_dibaca`
(+`hasil_ekstraksi`) → `post_dikecualikan` (+`alasan` per reason) → `paket`
(+`draft`/`review`/`published`), plus `antri_ig|ai|db` dan `gagal`
(`failed_jobs`). Semuanya dihitung ulang dari raw/extracted/DB tiap polling —
tidak ada tabel progres yang perlu dijaga sinkron.

Dua satuan yang **tidak boleh dicampur**: `post_*` menghitung postingan IG,
`paket`/`hasil_ekstraksi` menghitung gambar penawaran. Satu carousel = satu post
tapi bisa jadi beberapa paket, jadi angka yang naik di tengah corong itu wajar,
bukan tanda dobel. `post_diunduh` = raw + `excluded_posts`: tanpa suku kedua,
angkanya turun tiap import (`--prune` menghapus filenya) dan post kelihatan
seperti hilang. Sumbernya satu file: `storage/pipeline.jsonl`,
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
   +-- ig  FetchAccount    probe.php fetch    (lewati yang sudah dikecualikan)
   +-- ai  ExtractPost     probe.php extract  (vision -> penyusun per gambar)
   +-- db  ExtractPending  pindai raw -> antrikan ke ai
           ImportPackages  packages:import --prune -> DB + excluded_posts
                    |
          / (review + pratinjau lokal)  ->  status=published  ->  / (publik)
```

Pemicunya: tombol di `/` (atau `php artisan packages:crawl accounts.txt`) yang cuma
mengantrikan job. Tidak ada langkah manual di antara fetch, extract, dan import —
`ig` menyelesaikan satu akun, `db` langsung memindainya ke `ai`, dan `ai` tidak
menunggu akun berikutnya.

Paket masuk sebagai `review` atau `draft`, tidak pernah langsung `published`.
Publish = ubah `status` jadi `published` langsung di DB, setelah datanya dilihat.
