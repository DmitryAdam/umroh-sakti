# umroh-sakti

Portal agregator paket umroh. Menarik postingan travel dari Instagram, mengekstrak
flyer + caption jadi data terstruktur, lalu menyajikannya untuk dicari dan dibandingkan.

## Stack

Laravel 12 + SQLite · Anthropic SDK (ekstraksi). Tanpa Filament. Login operator
bawaan Laravel (tabel `users`, tanpa starter kit) — review & pratinjau jalan di
halaman `/` untuk yang sudah masuk.
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

**Kecuali `bukan_paket`: filenya ditahan sampai manusia yang memvonis.** Itu satu-satunya
reason yang murni tebakan mesin (gerbang vision + saringan struktural) dan yang paling
sering salah — sekali rawnya dibuang, flyer yang cuma kelewat prompt hilang selamanya.
`ImportExtractedPackages::buang()` tetap menulis barisnya `excluded_posts` (fetch &
extract tidak mengulanginya, jadi tidak ada yang dibayar), cuma `prune()`-nya dilewat.
Penghapusannya pindah ke tombol **blokir** di halaman post
(`POST /posts/bulk` dengan `action=block`): reason jadi `manual`,
raw + hasil ekstraksi + baris paketnya dibuang. Barisnya `excluded_posts` sengaja
tetap tinggal — itu yang menahan fetch. Konsekuensinya `storage/raw` tumbuh sampai
ada yang menyapunya; kalau menumpuk, blokir massal di tab **ditolak**.

Konsekuensinya: audit visual "kenapa post ini ditolak" hilang untuk reason yang lain.
Yang tersisa `reason` di `excluded_posts` + `storage/feedback.jsonl`. Kalau flyernya perlu
dilihat lagi, fetch ulang — dan itu berarti hapus dulu barisnya di
`excluded_posts`. Itu yang dikerjakan tombol **paksa** di `/accounts`
(`POST /accounts/{account}/fetch` dengan `force=1`, atau `action=force` di
`POST /accounts/bulk` untuk baris yang dicentang): hapus baris `excluded_posts` akun
itu, lalu fetch biasa — sisanya jalan sendiri. Tidak ada file yang perlu disentuh
(post yang ditolak rawnya memang sudah dihapus), tapi `--limit` sekarang ikut
menghitung post-post itu, jadi backlog panjang perlu beberapa kali tekan.

**Jangan re-host gambar ke CDN dan jangan hotlink `scontent`.** Dua-duanya sudah
dipertimbangkan sebagai jalan keluar dari beratnya `storage/raw`, dua-duanya
salah: hotlink signed URL mati dalam hitungan hari (lihat aturan `media_url` di
bawah), dan menaruh flyer full di CDN publik itu persis re-host yang dilarang —
byte-nya tetap dibayar, cuma pindah tagihan.

**Keberangkatan sebelum `config('umroh.min_departure')` tidak diambil** (default
`2026-08-01`, ubah lewat `UMROH_MIN_DEPARTURE`). Post-nya sekalian dikecualikan.

**Flyer juga syarat masuk DB** (`belumLengkap()`, reason `tanpa_gambar`). Hasil
ekstraksi tanpa `_useful_images` lahir sebagai baris ber-`flyer_index` null:
kartunya tampil tanpa gambar (raw-nya kadung dihapus prune) atau malah memajang
seluruh isi carousel, dan vision tidak pernah memvonis "ini penawaran" karena tidak
ada yang dilihat. Asalnya dua: semua gambar postnya kena dedup hash di
`claimImages()` (flyer rebranding — jadi paketnya memang sudah ada dari post lain),
atau jalur `probe.php seed`. Perlakuannya sama dengan tanpa harga: postnya **tidak**
dikecualikan, filenya ditinggal di `storage/extracted`. Terukur 2026-07-31: 7 baris
begitu, 5 di antaranya benar-benar tampil tanpa gambar.

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

**`storage/raw` itu tempat singgah, bukan gudang.** Semua gambar hasil download
masuk raw dulu; begitu satu gambar divonis penawaran dan barisnya dibuat,
`Package::promoteFlyer()` memindahkannya ke disk **`flyers`**
(`{media_id}/{flyer_index}.jpg`) lalu **menghapus jpg rawnya** — kalau raw-nya
ditinggal, "pindah ke s3" cuma penggandaan byte. `post.json` sengaja ditinggal:
itu yang bikin fetch berikutnya melewati post ini. Slide lain dari carousel yang
sama tetap di raw sampai prune.

Disknya `env('FLYER_DISK')` (`config/filesystems.php`), default `local` =
`storage/flyers`; produksi tinggal `FLYER_DISK=s3` dengan `AWS_*` yang sama.
Bacanya lewat `FlyerThumbController`: thumbnail selalu di-cache lokal di
`storage/app/thumbs` — kalau tidak, tiap kartu jadi satu GET ke s3. `flyers()`
tidak mengecek keberadaan file (di s3 itu satu HEAD per kartu); baris lama tanpa
`flyer_index` tetap dilayani dari raw.

**Jangan re-host flyer.** Gambar full hanya untuk ekstraksi & audit internal.
Yang tampil ke publik: data hasil ekstraksi + thumbnail kecil + link ke post asli.

**Satu gambar carousel = satu penyusunan.** Satu carousel sering memuat beberapa paket
berbeda (gambar 1 Ramadhan, gambar 2 Syawal). Vision menilai penanda
harga/tanggal/durasi **per gambar**, dan tiap gambar penawaran disusun sendiri jadi
filenya sendiri: `storage/extracted/{media_id}-{n}.json`, kolom `flyer_index`.
Gambar yang bukan penawaran tidak pernah dikirim
ke penyusun. Konsekuensinya: paket yang informasinya TERSEBAR di beberapa gambar
(harga di slide 1, tanggal di slide 2) ditolak di gerbang — penandanya dinilai per
gambar, bukan digabung.

**Satu gambar boleh menjual banyak keberangkatan** (`departures[]`, kolom
`offer_index`, unique `(media_id, flyer_index, offer_index)`). Flyer jadwal —
tabel tanggal, "Edisi Agustus/September/Oktober", beberapa program dengan harga
sendiri — itu bentuk paling umum di akun travel, dan sampai 2026-07-31 promptnya
malah menyuruh "ambil yang paling menonjol, sisanya diabaikan": satu gambar berisi
17 tanggal masuk sebagai 1 baris. Terukur di sampel: #232 → 17 baris jadwal,
#273 → 6, #449 → 8.

Bentuknya sengaja **bukan** tabel anak: penyusun mengisi field tingkat atas seperti
biasa (keberangkatan yang paling menonjol) **dan** mendaftar semuanya di
`departures[]` termasuk yang tingkat atas itu. Jadi hasil ekstraksi lama tanpa
`departures` tetap jalan apa adanya, dan semua saringan yang sudah ada
(`_missing`, `isPackageOffer()`, `isHaji()`) tetap menilai satu objek.
`ImportExtractedPackages::offers()` yang meratakannya jadi N baris; field yang null
di satu keberangkatan **diwarisi** dari tingkat atas (PPIU, kota, pembimbing,
fasilitas tidak pernah ditulis ulang per baris), `extension` `unknown` = mewarisi
sedangkan `none` = jawaban. Tabel `departures` sendiri dibuang dari
`raw_extraction` tiap baris — isinya sama untuk semua baris dari gambar itu.

Saringan yang jadi **per keberangkatan**: `sebelum_ambang` dan `belumLengkap()`.
Postnya cuma dikecualikan kalau SEMUA keberangkatannya lewat ambang — flyer jadwal
yang separuh barisnya kedaluwarsa tetap dipakai. Yang tetap per gambar: `bukan_paket`
dan `haji`. Batasnya `MAX_DEPARTURES` = 40.

**Baris "SOLD OUT" disaring di prompt, bukan di kode.** Flyer jadwal sering menandai
baris yang sudah habis dengan stempel miring di atas satu baris tabel (juga "HABIS",
"FULL BOOKED", "CLOSED", "WAITING LIST", harga dicoret). Tidak ada kolom
`sold_out` — penyusun langsung tidak memasukkannya ke `departures`, dan baris begitu
juga tidak boleh jadi field tingkat atas walau paling atas / paling besar. Supaya
stempelnya sampai ke penyusun, `TRANSCRIBE_PROMPT` menyuruh vision menyalinnya di
baris yang dikenainya (`[SOLD OUT]`, `[CORET]`), bukan dikumpulkan di akhir teks.
Semua baris habis = `departures` [], harga & tanggal null → ditolak `belumLengkap()`,
postnya tidak dikecualikan.

**`--only` masuk ke post yang sudah punya hasil, tapi tidak menulis ulang slidenya.**
Dulu `--only` mengimplikasikan `--force`; bedanya baru kelihatan saat gagal.
`ExtractPost` berbatas 570 detik, dan satu carousel belasan slide bisa menembusnya —
dengan force, retry-nya mulai dari nol, menembus batas yang sama, dan begitu terus
sampai `tries` habis (terukur di produksi: `--only=18337410223216038` timeout 570s).
Sekarang slide yang sudah punya file dilewat, jadi retry melanjutkan sisanya.
Vision tetap dibayar lagi (transkripnya tidak disimpan); yang diselamatkan bagian
paling lama — satu panggilan penyusun per slide. Tombol "baca ulang" tetap menulis
ulang semuanya karena `bacaUlang()` menghapus filenya dulu; dari CLI pakai `--force`.

Konsekuensinya hasil ekstraksi lama tidak otomatis memanen jadwalnya: filenya sudah
ada, jadi `extract` melewatinya. Perlu `--force`/`--only` (bayar model lagi) atau
tombol baca ulang per kartu.

**Wisata halal bukan umroh — ditolak di import** (`bukanUmroh()`, reason `bukan_umroh`).
Travel umroh juga menjual tur ke Korea, Jepang, China, Hongkong, Uzbekistan, Eropa,
New Zealand, dan flyernya lolos semua saringan lain karena bentuknya memang paket:
tanggal, durasi, harga, maskapai. Nama travel **bukan** penandanya — "Ramah Umroh &
Halal Tour" dan "ABNA TOUR — The Ultimate Hajj & Umrah Experience" itu kop surat yang
memuat kata umroh dan dipasang di flyer yang menjual Seoul, jadi kata `umroh`/`umrah`
sendiri tidak dihitung jejak tanah suci.

Dua sinyal, sama bentuknya dengan `isHaji()`: ada tujuan yang tidak pernah jadi rute
umroh **DAN** nol jejak tanah suci (`makkah`, `madinah`, `nabawi`, `haram`, `thawaf`,
`raudhah`, …) di teks slide itu. Sinyal kedua yang menahan salah tangkap: transit
atau extension ke negara mana pun tetap umroh selama tanah sucinya ikut dijual.

Yang **tidak boleh** masuk daftar tujuan: apa pun yang bisa jadi extension umroh —
Turki, Dubai, Kairo, Aqsa, Jordan, Petra, Andalusia, Taj Mahal. `petra` sempat dicoba
dan langsung salah tangkap: "PERJALANAN UMRAH ISTIMEWA — AQSHA JORDAN PETRA, UMRAH
PLUS AQSHA, 15 hari" tidak menyebut Makkah maupun Madinah sekalipun. Kota domestik
(Bali, Lombok) juga tidak — itu kota keberangkatan, bukan tujuan.

Terukur 2026-08-01 atas 604 baris: kena 9 (Korea, China, Seoul, Hongkong, New
Zealand, Uzbekistan), kesembilannya tur, nol salah tangkap. Varian yang menghitung
kata `umroh` sebagai jejak kena 0 — kop suratnya menutupi semuanya; varian yang cuma
menuntut jejak tanah suci (tanpa daftar tujuan) kena 39, dan ~30 di antaranya umroh
beneran yang kebetulan tidak mengeja nama kotanya. Daftarnya memang perlu ditambah
sesekali; yang tidak boleh berubah itu bentuk dua sinyalnya.

`TRANSCRIBE_PROMPT` juga menyuruh vision menolaknya (`other`) — itu lebih murah
karena penyusun tidak dipanggil, tapi bukan pengganti saringan import: promptnya
tidak deterministik dan hasil ekstraksi lama tidak ikut berubah.

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

Yang jadi **identitas baris** tetap `(media_id, flyer_index, offer_index)`, bukan `dedup_key`.
`importOne()` mengecek ketiganya dulu dan melewatinya kalau sudah ada:
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

**`Invalid user id` itu vonis atas slot app-nya, bukan atas username targetnya.**
Node di path request itu `IG_USER_ID`; pesan ini artinya token di slot itu tidak
bisa melihatnya (Page-nya tidak di-link ke app itu, atau urutan `IG_USER_ID` vs
`IG_ACCESS_TOKEN` tidak sepadan). Karena slotnya dipilih `crc32(username)`,
gejalanya menyesatkan: **sebagian akun gagal selamanya, sisanya jalan** — kelihatan
seperti akun yang bermasalah, padahal konfigurasi. `cmdFetch()` karena itu ikut
pindah app untuk pesan ini, sama seperti `#4` — kegagalannya bisa diselamatkan app
lain, dan kalau semua slot menjawab sama barulah itu benar-benar akunnya.

Kalau semua slot menjawab sama, penyebabnya akun target: **personal (bukan
Business/Creator) atau username salah ketik**. Meta memakai string yang sama untuk
dua sebab yang berbeda — yang membedakan cuma `code`: `110`/subcode `2207013` =
akun targetnya, sisanya slot. Terukur 2026-08-02: `umroh.bareng.id` (6.672
pengikut, 271 post) dan `amanahumroh_id` (2.676 pengikut) dua-duanya hidup dan
publik di instagram.com, dijawab `110` oleh ketiga slot, sementara
`sunnatravel.id` + `umitourtravel_id` (titik & underscore yang sama) jalan normal.
Status Professional cuma bisa diubah pemilik akunnya; tidak ada scope atau app
review yang membukanya.

Karena itu `FetchAccount` **memblokir akunnya** saat `Invalid user id` lolos sampai
ke sini — putaran scrap berikutnya kegagalannya sudah pasti. Rotasi app di
`cmdFetch()` yang bikin blokir itu aman: kalau sebabnya slot salah pasang,
app lain menyelamatkannya dan blokirnya tidak pernah kejadian. Postnya tetap bisa
masuk lewat `/suggestions` atau chrome extension — dua-duanya nol request ke Graph,
jadi status akun tidak berpengaruh.

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

**Satu unit itu ada batasnya: `VISION_CHUNK` gambar per call** (default 5,
`readFlyer()` memotong, `lihatFlyer()` yang memanggil). Call yang kegedean bukan
cuma lambat, tapi **gagal seluruhnya**: terukur 2026-07-31, carousel 4 gambar
(1,9 MB base64) balas HTTP 200 dalam 9,0 detik, sementara carousel 14 gambar
(5,4 MB) enam kali berturut-turut kena `Operation timed out ... 0 bytes received`,
dan sekali yang lolos malah balas 89,5 KB yang di-parse jadi 0 slide (kepotong) —
jadi postnya tidak pernah divonis dan tidak pernah jadi paket. Dipotong jadi 3
call: 9,6s + 9,7s + 4,6s, 12 slide terbaca, 11 penawaran.

Uplink bukan penyebabnya (terukur 3,8 MB/s ke router — 5,4 MB itu ~1,4 detik) dan
mengecilkan gambarnya juga bukan jalan keluar: flyer IG sudah 1024x1280–1080x1350,
jadi downscale ke ambang mana pun yang teksnya masih terbaca = no-op. Yang turun
kalau dipotong itu waktu mikir model **dan** panjang balasan.

Nomor slide dari model itu nomor gambar di potongan yang **dia** terima (1..k);
`visionVerdict($out, $offset)` yang mengembalikannya ke nomor gambar di post.
Salah di situ = `flyer_index` meleset dan kartunya memajang gambar tetangganya.
Satu potongan yang tidak terbaca = **seluruh** postnya balik `slides` kosong,
aturan yang sama dengan balasan rusak di call tunggal — menyimpan yang separuh
justru mengunci: filenya kadung ada, jadi extract berikutnya melewatinya.

## Ekstraksi

Caption dulu — 60-70% info ada di sana dan jauh lebih murah. Vision hanya
dipanggil kalau field wajib masih kosong setelah pass caption. Gambar yang
hash-nya sudah pernah diproses dilewat (flyer rebranding dari puluhan akun agen).

**Pra-gerbang caption cuma boleh menolak, tidak pernah meloloskan.** Sebelum vision
dipanggil, satu call teks (`readCaption()`, model penyusun) menilai captionnya saja:
dokumentasi keberangkatan, manasik, pengumuman bagasi, ucapan hari besar berhenti di
situ dan gambarnya tidak pernah dikirim ke model termahal di pipeline. Vonis "ini
penawaran" tetap milik vision — caption travel sering cuma emoji + "chat admin"
padahal flyernya paket lengkap, jadi ragu = lanjut. Jalan cuma kalau caption
≥ `CAPTION_GATE_MIN` (200 char): di bawah itu tidak ada bukti yang cukup untuk
menolak apa pun. `promo_generic` sengaja bukan kategori yang sah di sini — itu vonis
"detailnya kurang" yang cuma bisa dijatuhkan setelah flyernya dilihat.

Fail-open di semua cabang (`captionGate()`): JSON rusak, kategori asing, `tolak`
tanpa kategori, semua model mati — semuanya jadi "lanjut ke vision", aturan yang
sama dengan balasan vision yang tidak terbaca. Yang ditolak ditulis
`_rejected_by: caption` dan lewat jalur penolakan yang sama dengan vision: import
mengecualikan postnya (`bukan_paket`) dan **rawnya dihapus** — salah vonis berarti
fetch ulang lewat tombol paksa. Terukur 2026-07-31 atas 15 caption asli: 2 ditolak
(dua-duanya benar), 13 lanjut, nol flyer paket kejaring — termasuk lima caption
promo yang diawali "Alhamdulillah"/"SOLD OUT" yang paling mirip dokumentasi.

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
(mata).

**Isinya nama combo, bukan nama model: `logic-model` (penyusun) dan `image-model`
(mata).** Nama modelnya sendiri dirakit di dashboard router, jadi ganti/tambah model
= ubah anggota combo, bukan ubah `.env` di tiap mesin — dan giliran + failover-nya
dikerjakan router, bukan `llmPostAny()`. Nama yang bicara peran juga menghindari
ambiguitas yang sudah kejadian: `.env` tertulis `gemini-flash`, yang menjawab
`GLM-4.6V-Flash`, dan tidak ada satu pun baris log yang kelihatan salah.

Kalau menyebut model langsung, dua aturan lama tetap berlaku: namanya wajib berprefix
provider (nama polos diarahkan ke `openai`), dan isinya wajib **daftar JSON berkutip**
berisi lebih dari satu model (`'["ds/x","openrouter/y"]'`) — `llmPostAny()` memakainya
bergiliran dan melewati yang galat, tapi **routernya menggantung, bukan menolak**.
Terukur 2026-07-31 atas satu post 12 slide: `deepseek-flash` dan `ds/deepseek-v4-flash`
menjawab 0 byte selama 60 detik di sekitar separuh call (lalu 21–51 detik saat
berhasil). Dengan satu model, dua timeout `llmPost()` = exception = seluruh postnya
hilang; dengan tiga, post yang sama menulis 11 hasil. Peringatan `selftest` karena itu
cuma menyala untuk nama ber-`/`; nama combo dilewat.

**Semua anggota combo `image-model` wajib bisa vision, dan itu tidak bisa
diasumsikan.** Terukur 2026-08-02 dengan flyer 222 KB base64 yang jawabannya sudah
diketahui: `glm-cn/GLM-4.6V-Flash`, `glm-cn/GLM-4.6V-FlashX`, `glm-cn/GLM-4.7-Flash`
sama-sama balas `prompt_tokens` **830** — gambarnya dibuang router sebelum sampai ke
model, walau nama modelnya jelas-jelas varian vision (`V`) yang di Zhipu memang
multimodal. Satu anggota buta di combo = tiap request jadi lotere, dan yang kena slot
itu balas **HTTP 200 dengan `slides` kosong**: bukan galat, jadi `llmPostAny()` tidak
pindah model dan postnya hilang senyap (lihat "balasan vision yang tidak terbaca
bukan vonis"). Menguji anggota barunya: kirim flyer yang jawabannya sudah diketahui,
lalu **baca `prompt_tokens`** — satu flyer 1080×1350 itu ribuan token, angka ratusan
berarti yang sampai cuma teksnya.

Kalau butuh cadangan di luar router: `glm-4.6v-flash` (gratis) dan `glm-4.6v-flashx`
lewat endpoint Z.ai langsung (`https://api.z.ai/api/paas/v4/chat/completions`, key
sendiri) benar-benar melihat pixel — `prompt_tokens` 1910 untuk flyer yang sama.
Terukur 2026-08-02 atas 8 flyer yang harga/tanggal/durasinya sudah ada di DB,
`TRANSCRIBE_PROMPT` apa adanya: **`glm-4.6v-flashx` 8/8 lolos gerbang dan ketiga
angkanya benar semua, nol galat, rata 15,7 detik**. Yang dipakai `flashx`, bukan
`flash`: yang gratis kena `1302 Rate limit` / `1305 overloaded` beruntun — 2 dari 8
gagal total, rata 104,6 detik. Itu **URL kedua**, jadi bukan cuma ganti
`VISION_MODEL`: `llmPost()` sudah menerima url+key sebagai parameter, tapi
`lihatFlyer()` masih memanggil `routerUrl()`.

**Satu slide yang gagal jangan menjatuhkan slide lain.** Panggilan penyusun per
slide dibungkus try/catch di `cmdExtract()`. Tanpa itu exception di slide ke-3
membuang delapan sisanya — dan file slide 1-2 yang kadung ditulis bikin extract
berikutnya melewati postnya, jadi kehilangannya permanen. Yang gagal diambil lagi
lewat tombol **baca ulang** per kartu (`--force`).

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
php artisan test                   # 131 test: import+dedup+ambang, filter publik, regulasi, halaman akun, post manual, login SSO, peran, penangguhan
php artisan packages:import        # storage/extracted/*.json -> database
php artisan migrate:fresh --seed
php artisan view:clear && npm run build   # WAJIB: CSS-nya lewat Vite, bukan CDN lagi
```

**Tampilannya pakai token shadcn/ui, bukan React.** `resources/css/app.css` memuat
token shadcn base "neutral" (`--background`, `--primary`, `--border`, …) yang
dipetakan jadi utility Tailwind lewat `@theme inline`; resep kelas komponennya
disalin ke Blade anonymous component di `resources/views/components/ui/`
(`card`, `button`, `input`, `select`, `badge`, `field`). Tidak ada React/Inertia —
aturan "satu form GET tanpa JS framework" tetap berlaku, jadi `npx shadcn add`
tidak dipakai; komponen baru = tambah satu file Blade dengan kelas dari
ui.shadcn.com. Ganti tema = ganti nilai di `:root`, bukan kelas per elemen.
CDN `cdn.tailwindcss.com` dibuang: token itu tidak terbaca dari CDN, jadi
tanpa `npm run build` halamannya tampil tanpa gaya.

**`view:clear` dulu sebelum build.** `resources/css/app.css` memuat
`@source '../../storage/framework/views/*.php'` — itu perlu (kelas yang cuma muncul
sesudah Blade meng-compile komponen), tapi cache view yang basi ikut dipindai. Terukur
2026-08-02: sesudah `php artisan test` (yang meng-compile view error + paginator bawaan)
bundelnya 56,8 KB; dengan cache bersih 39,6 KB untuk halaman yang sama.

**Yang dipakai lebih dari satu halaman jadi partial, bukan disalin.** Sudah pernah
menyimpang: kotak `session('status')` punya tiga warna berbeda, dan blok JS centang →
bar aksi ada dua salinan yang cuma beda kata "akun"/"post". Sekarang:
`partials/flash` (status + galat validasi, lewat `x-ui.alert`),
`partials/post-status` (badge status satu post: ditolak/menunggu admin/N paket/dibaca AI
— kolom status `/posts` dan daftar kiriman `/suggestions`),
`partials/bulk-select` (`$satuan`, `$catatan`), dan `partials/status-patch` (select
status → PATCH tanpa reload, dipakai kartu `/` dan kolom status `/posts`).

`bulk-select` dibungkus IIFE dan mendelegasikan ke `document`: halaman akun punya
`<script>` lain di file yang sama (`const pilih` kembar = SyntaxError yang mematikan
dua-duanya) **dan** tabel kedua untuk usulan yang menunggu, jadi
`querySelector('table')` belum tentu tabel yang punya checkbox.

**Halamannya tidak boleh bisa digeser ke samping di HP.** Satu elemen yang melar
(tabel `whitespace-nowrap`, URL panjang di caption IG) melebarkan SELURUH halaman, dan
header + footer yang `inset-x-0` cuma selebar viewport — jadi gejalanya "sisi kanan
kepotong" di tiap halaman, bukan di elemen yang salah. Dua aturannya: tabel selalu
dibungkus `overflow-x-auto` sendiri, teks bebas dari luar (caption, nama hotel apa
adanya) selalu `truncate` atau `break-words`. `html { overflow-x: clip }` di
`app.css` cuma jaring — `clip`, bukan `hidden`, karena `hidden` memindahkan scrollport
dan mematikan `position: sticky` headernya.

**Teks yang dibaca pengusul ditulis untuk orang awam, bukan untuk yang paham
pipeline-nya.** `/suggestions` sempat menerangkan `--limit`, kuota Graph, gerbang
vision, dan status `draft`/`review` di caption tiap field — semuanya benar dan tidak
satu pun menjawab "saya harus isi apa". Aturannya: satu kalimat per field, kata kerja
yang bisa dikerjakan, istilah internal cuma boleh muncul kalau yang mengisi memang
harus tahu (tanggal posting: perlu, karena itu jangkar tahun dan salah isi menggeser
paketnya setahun). Halaman kerja admin (`/accounts`, `/posts`) tidak kena aturan ini —
yang membacanya di situ memang perlu istilahnya.

Komponen void (`x-ui.input`) **wajib ditutup `/>`**. Tanpa itu Blade menganggapnya
tag yang dibuka, menelan sisa halaman jadi slotnya, dan tumbang sebagai
`ParseError: expecting endif` di baris terakhir — jauh dari tag yang sebenarnya
salah. Route yang cuma diuji sebagai tamu tidak menangkapnya: view-nya tidak pernah
di-compile, jadi tiap halaman baru butuh satu test yang benar-benar `assertOk()`.

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
| `ai` | 1 (`--ai=N`) | `ExtractPost` | bagian paling lama, tapi call vision paralel ke router saling menggantung — serial dulu |
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

**Keberangkatan yang sudah lewat selalu di dasar daftar, di urutan mana pun.** Kunci
urut pertama sebelum semua yang lain (`departure_date < hari ini`), jadi berlaku juga
untuk urut harga — tanpa itu paket yang berangkat bulan lalu jadi "termurah" dan
menyampahi puncak daftar. Barisnya **tidak** dibuang: masih ketemu lewat `?from=`/`?to=`
dan link permanennya tetap hidup; yang berangkat kemarin cuma turun, tidak hilang.
`is not null and` di kunci itu perlu — tanpanya baris tanpa tanggal balik NULL dan NULL
urut paling depan di ASC, persis kebalikan dari yang dimau.

Konsekuensinya test yang menuliskan tanggal tetap ("2026-05-10") basi sendiri begitu
tanggalnya terlewati: urutan yang diharapkan berubah tanpa ada kode yang berubah.
Tanggal di test urutan ditulis relatif (`now()->addMonths(3)`).

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

**Tampilan kartu/daftar + jumlah kolom itu preferensi, bukan filter** — tidak ikut
query string (link yang dibagikan tidak boleh memaksa tata letak orang lain),
disimpan di `localStorage`. Defaultnya ikut lebar layar: **daftar di bawah 40rem**,
kartu di atasnya. Di HP gridnya toh cuma satu kolom, jadi satu kartu memakan hampir
satu layar penuh untuk satu paket. Cuma default — pilihan yang pernah ditekan tetap
menang di lebar berapa pun.

Lebar minimum track daftar ditulis `minmax(min(24rem, 100%), 1fr)`. `24rem` telanjang
lebih lebar dari layar HP (343px isi), dan minmax menolak track yang lebih kecil dari
min-nya — gridnya jadi lebih lebar dari halamannya dan sisi kanan kartunya kepotong.
Kolom flyernya sendiri turun 8,5rem → 5,5rem di bawah 40rem, kalau tidak teksnya cuma
kebagian ~13rem dan hotel/maskapai kena `truncate` sebelum kata kedua.

Klik judul kartu membuka **lightbox** `<dialog>` yang mengambil `/packages/{id}` lewat
fetch; `show()` membalas `partials.detail` (potongan yang sama, tanpa layout) kalau
`$request->ajax()`. `href`-nya tetap URL asli, jadi klik-tengah/tanpa JS tetap dapat
halaman penuh.

**Tiga tombol aksi per kartu, sebaris di bawah gambar** (pratinjau lokal saja):
baca ulang (`POST /packages/{id}/extract`), segarkan (`POST /packages/{id}/fetch`),
buang (`DELETE /packages/{id}`). Tooltipnya `title` bawaan browser.

**Status publikasi diubah dari kartu**, select tiga pilihan di bar aksi yang sama
(`PATCH /packages/{id}/status`, whitelist `Package::STATUSES`). Manajemen paketnya
tidak punya halaman sendiri: `/` dalam pratinjau sudah daftar paket berfilter, jadi
antrean review = `?status=review` (facet `status` sudah ada, lengkap dengan
jumlahnya). Simpannya lewat fetch tanpa reload — kalau halamannya dimuat ulang,
kartu yang baru dipublish langsung keluar dari filter yang sedang dipakai dan sisa
kartunya bergeser di tengah kerja.

Statusnya tiga: `draft` dan `review` sama-sama belum publik (bedanya cuma asal —
`_needs_review` dari ekstraksi jadi `review`), `published` yang tampil ke
pengunjung. `rejected` di komentar migrasi **tidak dipakai**: paket yang ditolak
dihapus barisnya lewat ×, jadi tidak ada baris untuk ditempeli status itu.

Baca ulang wajib tiga langkah, bukan cuma dispatch `ExtractPost`: flyer
dikembalikan ke `storage/raw` (`Package::restoreFlyer()`, kebalikan
`promoteFlyer()` — extract cuma membaca raw), hasil ekstraksi lama dihapus, dan
**barisnya dihapus** karena `importOne()` tidak pernah menimpa baris yang sudah
ada. Yang dikembalikan **semua** slide se-`media_id`, bukan cuma paket itu: nomor
slide dari vision menunjuk gambar ke-N yang *dikirim*, jadi satu gambar hilang
menggeser penomoran paket sebelahnya. Postnya tidak dikecualikan dan `post.json`
tidak disentuh — ini bukan vonis "bukan paket".

Segarkan (dulu "ambil ulang") = download ulang **plus** baca ulang, bukan download
doang. Menghapus folder raw postnya (kalau tidak, fetch melewatinya: "sudah ada di
storage/raw"), hasil ekstraksinya, dan **semua baris se-`media_id`** lewat
`deleteSources()` + `delete()`, lalu `FetchAccount`. Tiga penghapusan itu wajib:
`ExtractPending` melewati post yang sudah punya file di `storage/extracted`, dan
`importOne()` tidak pernah menimpa baris yang sudah ada — jadi tanpa itu download
ulangnya tidak mengubah apa pun yang tampil. Sisanya jalan sendiri: `FetchAccount`
→ `ExtractPending` → `ExtractPost` → `ImportPackages`.

Se-`media_id`, bukan cuma paket itu, dengan alasan yang sama seperti baca ulang:
nomor slide dari vision menunjuk gambar ke-N yang *dikirim*. Postnya tidak
dikecualikan. Konsekuensinya kalau fetch gagal atau postnya di luar `--limit`,
barisnya hilang untuk sementara sampai fetch berikutnya berhasil.

**Tombol "baca ulang AI" di `/accounts/{id}/posts` TIDAK melewati gerbang vision.**
Yang dilepas cuma bloknya (baris `excluded_posts` + jejak bacaan lama) supaya
postnya boleh dibaca lagi; vonis "ini penawaran umroh atau bukan" tetap milik
vision, karena cuma dia yang melihat pixelnya. Sempat ada `--no-gate` di jalur ini
(`ExtractPost($media, noGate: true)`) — dibuang: tanpa gerbang, post yang
flyernya cuma ucapan hari raya + caption "chat admin" ikut disusun jadi paket,
dan itu persis yang gerbangnya ada untuk mencegah. Salah vonis = perbaiki
promptnya (`TRANSCRIBE_PROMPT`), bukan matikan gerbangnya per post.

Urutannya: baris `excluded_posts` dilepas dulu (itu yang bikin extract melewatinya),
hasil ekstraksi lama dihapus, baris paket se-`media_id` dikembalikan flyernya lalu
dihapus. Yang **tidak** dilakukan: men-scrap ulang akunnya. Raw postnya sudah dihapus
(post ditolak memang filenya dibuang) = tombolnya menolak dengan pesan, bukan
mengantrikan `FetchAccount` — satu klik per post tidak boleh membakar kuota Graph
untuk seluruh akun. Download ulang tombolnya sendiri: scrap paksa di `/accounts`.

Lolos gerbang **tidak** berarti pasti jadi baris: `belumLengkap()` tetap berlaku,
dan post tanpa harga di flyer maupun caption tetap tidak masuk DB (tidak dikecualikan
juga, filenya ditinggal di `storage/extracted`).

**Satu halaman post, dua ruang lingkup: `/posts` (semua akun) dan
`/accounts/{account}/posts`** — dua route, satu `PostController::index(?SourceAccount)`,
satu view `posts.blade.php`. Yang beda cuma himpunannya; tab, tabel, dan aksinya
dipakai bersama. Halaman semua post menambah kolom **akun** (peta `username -> id`
sekali query, bukan satu query per baris) dan memotong 60 baris per halaman
(`LengthAwarePaginator` atas Collection — himpunannya dirakit dari disk + dua tabel,
tidak ada yang bisa di-`LIMIT`); prev/next dirender sendiri karena `links()` bawaan
memakai kelas Tailwind v3 yang tidak ada di build v4. Jejak scrap cuma muncul di
halaman per-akun.

**Aksinya sengaja TIDAK dilingkupi akun**: `POST /posts/bulk` (`action` =
`extract|block|unblock`), `POST /posts/{media}/extract`, `GET /posts/{media}/{i}.jpg`.
`media_id` sudah unik, dan `PostController::akun()` mencari akunnya sendiri (raw dulu,
lalu `excluded_posts`, lalu baris paket) — kalau dilingkupi, tiap aksi butuh dua jalur:
satu untuk halaman akun, satu untuk halaman semua post. `unblock` cuma membuang
barisnya `excluded_posts`; tidak ada file yang disentuh, gambarnya baru ada lagi setelah
scrap berikutnya.

**Tabel, bukan grid kartu.** Yang dibaca operator itu caption + alasan berdampingan, dan
mengoreksi vonis `bukan_paket` berarti menyapu belasan baris sekaligus, bukan satu
tombol per kartu. Captionnya `<details>` yang di-clamp 2 baris — klik untuk penuh, tanpa
JS. Checkbox-nya menunjuk `<form id="bulk">` di luar tabel lewat `form="bulk"`, sama
seperti `/accounts`, karena kolom aksi sudah memuat form per baris. Status publikasi tiap
paket ada di kolom status sebagai select (`PATCH /packages/{id}/status` lewat fetch,
tanpa reload) — aturannya sama dengan bar aksi kartu di `/`. Aksinya memanggil method
privat yang sama dengan tombol per barisnya (`bacaUlang()`, `blokir()`) — tidak ada jalur
kedua yang bisa menyimpang. Post yang rawnya sudah dibuang dilewat diam-diam saat
`extract` dan dihitung di pesannya.

**Kolom gambar mati secara default, dan yang keluar thumbnail.** Dua lapis, karena
satu tidak cukup: `posts.raw` sekarang men-thumbnail (lebar maks 480, q75) lalu
men-cache-nya di `storage/app/thumbs/raw-{media}-{index}.jpg` — aturan yang sama
dengan `FlyerThumbController`, prefiks `raw-` supaya tidak tabrakan dengan cache
flyer yang media+index-nya bisa sama. Sebelumnya jpg RAW apa adanya (~500 KB), dan
60 baris berisi carousel 14 slide berarti puluhan MB untuk petak 40×40.

Lapis keduanya centang **tampilkan gambar** di baris tab: `<img>`-nya dirender
tanpa `src` (cuma `data-src`), dan JS di `posts.blade.php` yang mengisinya kalau
centangnya menyala. `display:none` **bukan** penggantinya — browser tetap mengunduh
gambar yang disembunyikan CSS. Pilihannya di `localStorage`, bukan query string,
aturan yang sama dengan kartu/daftar di `/`. Default mati: yang dibaca operator itu
caption + alasan berdampingan.

Slide pertama saja yang di kolom; sisanya di balik `<details>` ber-`loading="lazy"` —
gambar di dalam elemen tertutup tidak di-fetch sampai dibuka, jadi nol byte walau
centangnya menyala.

**Post yang tidak terjangkau fetch dimasukkan tangan lewat `/suggestions`.**
`business_discovery` mengembalikan media urut **timestamp turun**, bukan urutan grid
instagram.com — pinned post **tidak** diangkat ke atas. Terukur di `mahyaatourtravel`
(841 post): `--limit=9` memberi 9 post beruntun 26–31 Juli, sementara flyer yang di-pin
19 minggu lalu duduk di posisi ke-300-an. Paging mundur sejauh itu membakar `total_time`
untuk ratusan post yang tidak dipakai, jadi jalurnya form: permalink + akun + tanggal
posting + caption + gambar, nol request ke Graph.

Tidak ada API yang bisa dipakai untuk satu post: media ID tidak bisa di-GET terpisah,
`business_discovery` cuma punya `.after()`/`.limit()`, dan `instagram_oembed` butuh app
review lalu tidak mengembalikan caption maupun thumbnail. URL post juga tidak memuat
username — itu cuma ada di HTML halaman, dan membacanya = scraping unofficial.

`media_id`-nya **hasil decode shortcode**, bukan `manual_<slug>`: shortcode itu base64
URL-safe dari pk media (`DV-tyQIkuw5` → `3854719696466275385`), jadi deterministik —
dua contributor yang mengirim post yang sama dapat id yang sama dan kiriman kedua
menimpa. Wajib numerik karena route `flyer`/`posts.raw` dikunci `whereNumber`; id
non-numerik = kartunya tampil tanpa gambar.

Yang ditulis cuma `storage/raw/{user}/{media}/` persis sebentuk `savePost()` di
`probe.php`; sesudah itu **tidak ada jalur khusus**. Gerbang vision tetap menilai
(kiriman manusia bukan vonis "ini paket"), `belumLengkap()` tetap berlaku, dan hasilnya
tetap `draft`/`review` — tidak ada yang bisa langsung publish lewat sini.

**Semua kiriman jadi usulan, termasuk kiriman admin.** Dulu admin punya jalur cepat di
formulir yang sama: akun langsung `approved`, `ExtractPost` langsung dilempar, kiriman
ulang menimpa. Itu tiga cabang `if ($admin)` di satu method untuk satu markup — tiga
kesempatan supaya dua peran diam-diam berperilaku beda atas formulir yang sama, dan
`store()` yang bercabang di tengah penulisan file itu persis tempat bug yang cuma
kelihatan untuk satu peran. Sekarang satu jalur: tulis raw, tandai `_suggested_by`,
selesai. Akunnya juga `pending`.

Approval-nya satu tombol di `/posts` tab **usulan** (dan `/accounts` untuk akunnya).
Untuk admin itu satu klik tambahan, dan itu harga yang murah untuk satu perilaku.
Yang mau akun langsung jalan pakai textarea di `/accounts` — endpoint lain, memang
punya admin.

**Auto-approve itu saklar, bukan jalur kedua.** Tombol di tab usulan
(`POST /posts/auto-approve`, admin saja) menyalakan "usulan baru langsung dibaca AI";
`store()` yang nyala tetap menulis raw + `_suggested_by` seperti biasa lalu memanggil
`bacaUlang()` — method yang sama dengan tombol **setujui & baca**. Jadi tidak ada
cabang yang bisa diam-diam berperilaku beda dari tombolnya, dan gerbang vision +
`belumLengkap()` tetap berlaku: yang dilewati cuma approval manusia.

Nilainya di tabel `cache` (driver `database`), bukan tabel sendiri — satu boolean, dan
`config` tidak bisa diubah dari UI. Konsekuensinya `cache:clear` mematikannya, dan itu
default yang aman. Kalau kelak ada setelan kedua, baru bikin tabel `settings`.

**Hapus ≠ blokir, dan tab usulan cuma punya yang pertama.** `DELETE /posts/{media}`
(`destroy()`, grup `auth`) + `action=delete` di `POST /posts/bulk` membuang raw +
hasil ekstraksi + baris paketnya lewat `hapusJejak()` **tanpa** menulis
`excluded_posts` — jadi postnya boleh dikirim/di-scrap lagi. Itu justru gunanya:
kiriman yang salah permalink/tanggal/akun ditarik lalu dikirim ulang, sementara
blokir adalah vonis "bukan paket" yang sekalian menahan fetch. Tombol **hapus blokir**
di tab usulan dulu tidak pernah mengerjakan apa pun (tidak ada yang diblokir di
situ) — sekarang tab itu menampilkan **hapus**, tab lain tetap **hapus blokir**.

Route-nya `auth`, bukan `can:admin`, karena yang mengusulkan harus bisa menarik
kirimannya sendiri; yang menahannya cek di controller: admin bebas, peran lain cuma
`_created_by` = dirinya **dan** `_suggested_by` masih ada. Sesudah disetujui barisnya
sudah jadi paket yang di-review orang lain, dan pintu ini akan jadi tombol hapus
paket yang terbuka untuk semua yang bisa login. Dijaga
`pengusul_tidak_bisa_menghapus_kiriman_orang_lain`.

**Kiriman ulang ditolak, tidak menimpa.** Menimpa berarti membuang raw + hasil
ekstraksi + baris paket se-`media_id` — itu tombol hapus paket yang sudah di-review,
cuma lewat pintu lain, dan pintu ini terbuka untuk semua yang bisa login. Post yang
sudah ada dibetulkan dari `/posts` (baca ulang / blokir / hapus blokir): di situ yang
menekan sudah melihat barisnya dan aksinya bernama sesuai akibatnya.

Konsekuensinya `hapusJejak()` tidak lagi dipanggil dari `store()` (cuma dari
`blokir()`), dan parameter `overwrite` yang dulu dikirim chrome extension jadi tidak
berguna — dibiarkan inert di extension-nya, aturannya sekarang berlaku tanpa perlu
diminta.

**Tanggal posting wajib diisi dan jangan dikira-kira.** Itu jangkar tahun buat penyusun;
jangkar yang salah menggeser paket 2027 jadi 2026 dan lolos ambang keberangkatan. Upload
png/webp di-encode ulang jadi `{n}.jpg` (seluruh pipeline menamai slide begitu) dan
**diratakan ke putih** dulu — `imagejpeg()` membuang kanal alpha, dan flyer transparan
keluar berlatar hitam sehingga tulisannya tidak terbaca vision.

**Asal post ada di `post.json`, bukan di kolom.** `_manual: true` menandai kiriman
tangan, `_created_by` emailnya — **jejak permanen**, beda dengan `_suggested_by` yang
cuma penanda "belum di-approve" dan dibuang `bacaUlang()`. Tanpa `_created_by`, kiriman
manual tidak bisa dibedakan dari hasil scrap kecuali dari panjang `media_id` (19 digit
hasil decode shortcode vs 17 digit dari Graph), dan itu bukan aturan yang boleh
diandalkan. `/posts` menampilkannya di kolom tanggal: `scrap` atau emailnya. Post manual
lama yang cuma punya `_manual` tampil `manual`.

**Daftar "kiriman saya" disaring dengan `_created_by`, bukan `_suggested_by`.** Yang
kedua dibuang begitu admin menyetujui — kalau itu yang dipakai, kiriman menghilang dari
daftar pengirimnya sendiri justru pada saat statusnya mulai menarik ("sudah disetujui?
jadi paket? ditolak kenapa?"). Statusnya dirender `partials/post-status`, partial yang
sama dengan kolom status `/posts`: satu definisi untuk "ditolak (alasan) / menunggu
admin / N paket / dibaca AI". Yang **tidak** ikut ke partial itu select status paket —
itu aksi admin (`PATCH /packages/{id}/status`), bukan keterangan.

Halamannya di grup `auth` (bukan `can:admin`), dan judulnya "Usulan saya" untuk kedua
peran: satu halaman, satu perilaku, satu markup. Kiriman peran `user` dan kiriman admin
sama-sama menunggu approval — lihat "Usulan tidak menjalankan apa pun" di bawah.

**Balasan vision yang tidak terbaca bukan vonis.** `jsonOf()` balik `[]` diam-diam
kalau JSON-nya rusak/terpotong, dan `visionVerdict([])` membacanya jadi
`post_kind=other` — dulu itu ditulis sebagai hasil ekstraksi, jadi import
mengecualikan postnya **selamanya** dan menghapus rawnya padahal modelnya cuma
gagal menjawab. Terukur 2026-07-31: 46 dari ~200 ekstraksi di `pipeline.jsonl`
tercatat `post_kind=other, 0/0 gambar`, dan 8 dari 18 file gate-rejected yang masih
ada transkripnya kosong. Sekarang `slides` kosong = `cmdExtract()` melewati postnya
tanpa menulis apa pun: rawnya tetap ada, extract berikutnya mencobanya lagi, gratis.
Jumlah slide 0 selalu berarti balasan rusak — vision cuma dipanggil kalau ada gambar
dan promptnya menuntut satu entri per gambar.

Panel "catatan & jejak" per kartu (form `review_verdict` + `review_note`) sementara
dilepas dari UI. Endpoint `POST /packages/{id}/feedback` dan kolomnya masih ada.

**Panel pipeline halamannya sendiri: `/pipeline`** (`Route::view`, tanpa controller —
isinya polling ke `/pipeline/status`). Dulu spanduk di atas tabel `/accounts`, dan di
situ jejaknya wajib dilipat `<details>` supaya daftar akunnya masih kelihatan; dipisah,
jejaknya dirender terbuka terus (`max-h-[60vh]`, tanpa `<details>`). Menunya jadi dua
entri (`akun`, `pipeline`), dan `/accounts` menyisakan satu tautan ke sana.

Daftar akun sumber di `/accounts`: masukin username/URL/@handle
satu per baris (parsernya `SourceAccount::usernameOf()`, dipakai juga oleh
`packages:crawl`), lihat status + `last_fetched_at` + jumlah post/paket/dikecualikan per akun,
plus tombol `scrap` per akun dan `Scrap semua` (lewat `packages:crawl --limit=9`). Akun
yang ditambah dari sini langsung `approved` — operator lokal memang si pemberi approval.

Tabelnya dipotong **50 baris per halaman** (`LengthAwarePaginator` atas Collection —
urutannya dikerjakan di PHP, jadi tidak ada yang bisa di-`LIMIT`; prev/next dirender
sendiri, `links()` bawaan memakai kelas Tailwind v3). Angka dan tombol kelompok di
atasnya dihitung dari `semua`, bukan dari halaman yang tampil: "belum pernah di-scrap"
yang ikut nomor halaman tidak bisa dipakai memutuskan apa pun.

Usulan akun (`pending`) tetap di luar paginasi — satu blok di atas tabel dengan tombol
**setujui semua**, id-nya semua dititipkan ke endpoint bulk yang sama (`action=approve`).
Aman tanpa filter, beda dengan "scrap semua": approval cuma mengubah `status` dan tidak
mengantrikan fetch apa pun.

**Tidak ada tombol "jalankan pipeline".** Yang mengantrikan job cuma `scrap`/`Scrap
semua` di `/accounts` dan `packages:crawl` di CLI; `queue:work` yang mengerjakan ketiga
antrian. Yang ada di panel itu kebalikannya: **batal** — `DELETE
/pipeline/queue/{queue?}` (`PipelineController::clear`). Tanpa `{queue}` semua
antrian; dengan `ig|ai|db` (whitelist `PipelineController::QUEUES`, nilai asing 404)
cuma antrian itu, jadi fetch yang macet bisa dibuang tanpa membunuh ekstraksi yang
sedang jalan. Yang dihapus: `jobs` + `failed_jobs` (difilter per antrian) +
`cache_locks` (selalu seluruhnya — kuncinya tidak menyimpan nama antrian, jadi tidak
bisa dipilih; dibuang karena lock `unique` milik job yang dihapus tidak ada yang
melepas dan job sejenis berikutnya kelihatan hilang diam-diam sampai `uniqueFor` habis).

Sebelahnya **ulangi** — `POST /pipeline/queue/retry/{queue?}`
(`PipelineController::retry`), bungkus tipis `queue:retry`. Kata kerjanya di depan
karena parameter opsional wajib di segmen terakhir. `cache_locks` sengaja **tidak**
disentuh di sini: retry mendorong payloadnya langsung, tidak lewat dispatcher, jadi
lock `unique` tidak dicek ulang. Mayoritas isi `failed_jobs` memang layak diulang —
worker di-Ctrl+C, `database is locked`, model timeout.

**Stop worker ≠ batalkan antrian.** Tombol merah di panel (`POST /pipeline/stop`,
`PipelineController::stop`) menghentikan yang **mengerjakan**, bukan yang dikerjakan:
antriannya utuh dan lanjut sendiri begitu `queue:work` dinyalakan lagi. Jalurnya flag
cache `QueueWork::STOP` yang dibaca loop induk sekali per detik, lalu induknya kirim
SIGTERM ke anaknya sendiri dan berhenti menyalakan ulang. **Bukan `pkill` dari web**:
argv anak (`php artisan queue:work --queue=ig`) identik lintas project, jadi pola apa
pun yang cocok dengan worker kita juga membunuh worker app tetangga di server yang
sama. `queue:restart` bawaan juga tidak bisa dipakai — anak yang keluar dinyalakan
lagi oleh loop induk, jadi yang terjadi cuma restart.

SIGTERM, bukan kill: job yang sedang dipegang diselesaikan dulu (satu carousel ke
vision bisa menit-menitan), jadi panelnya tidak langsung sunyi. Flagnya ber-TTL 5
menit **dan** dibuang induk saat start — kalau tidak, stop yang ditekan saat tidak
ada worker akan mematikan worker berikutnya. Menyalakannya lagi cuma dari terminal;
tidak ada tombol "jalankan".

Panel juga menampilkan **sebab** kegagalan, bukan cuma jumlahnya
(`antrian_per.{q}.pesan_gagal`): baris pertama `exception` kegagalan terakhir per
antrian, diambil lewat `max(id)` — kolom itu stacktrace penuh, narik semua barisnya
berarti ratusan KB tiap polling 2 detik.

**Ctrl+C bukan job gagal.** Sinyal kena satu grup proses, jadi `probe.php` yang
dijalankan `FetchAccount`/`ExtractPost` ikut mati dan Symfony melempar
`ProcessSignaledException` — bukan exit code, jadi `$result->failed()` tidak pernah
kepakai. Dua-duanya menangkapnya lalu `release()`. Kalau dilempar, tiap kali worker
dihentikan manual jobnya mendarat di `failed_jobs`, dan di `FetchAccount` sekalian
menstempel `last_error` sehingga akunnya kelihatan gagal di `/accounts`.

**Reservasi yatim dilepas saat induk `queue:work` start.** Worker yang di-kill di
tengah job meninggalkan `reserved_at` terisi, dan job itu baru diklaim ulang setelah
`DB_QUEUE_RETRY_AFTER` (1200 detik) — jadi sesudah restart antrian kelihatan "3 jalan"
selama 20 menit padahal tidak ada prosesnya. Guard `pgrep` di `QueueWork::handle()`
sudah memastikan tidak ada worker lain hidup, jadi reservasi yang tersisa pasti yatim.
`retry_after`-nya jangan diturunkan: itu jaring untuk job yang memang lama (satu
carousel ke vision + penyusun), dan menurunkannya bikin job yang masih jalan diambil
worker kedua.

**`DB_QUEUE_RETRY_AFTER` dijepit dua arah, 1200 itu titik tengahnya.** Batas
bawahnya timeout job terpanjang (`FetchAccount` = 900s): di bawah itu queue
menganggap job yang masih jalan sudah mati lalu menyerahkannya ke worker lain —
fetch/extract jalan dobel. Batas atasnya angka yang sama dibaca terbalik: ini
**sekaligus** berapa lama job yang workernya mati ikut beku sebelum direbut ulang.
7200 pernah bikin tiga job `ai` nganggur 2 jam padahal `ai` tidak kena rate limit
apa pun.

Job yang **sedang** dikerjakan tetap selesai — barisnya masih ada di `jobs` dengan
`reserved_at` terisi, dihapus lebih awal saja, dan `delete()` worker sesudahnya jadi
no-op. Makanya `antrian_per.{q}` memisah `antri` (`reserved_at is null`) dari `proses`:
"3 antri" yang sebenarnya "2 antri + 1 jalan" bikin panel kelihatan macet padahal
tidak. Bar progress per antrian dihitung **di browser** dari puncak antrian yang pernah
terlihat sejak halaman dibuka (job selesai tidak meninggalkan jejak yang bisa dihitung),
jadi reload = bar mulai lagi dari 0%.

**Profil akun ikut diambil saat fetch, tapi cuma yang memang ada di API.**
`business_discovery` menyediakan `name`, `followers_count`, `follows_count`,
`media_count`, `profile_picture_url` — diminta **hanya di halaman pertama** (nilainya
sama di tiap halaman, dan yang mengikat kuota itu `total_time`). Status **verified**
dan **tanggal bergabung** tidak ada: dijawab `#100 nonexisting field`, jangan dicari lagi.
Sudah dicek dua arah: tabel field IG User di dokumentasi resmi (`alt_text`, `biography`,
`followers_count`, `follows_count`, `has_profile_pic`, `id`, `is_published`,
`legacy_instagram_user_id`, `media_count`, `name`, `profile_picture_url`,
`collaborative_media_search`, `shopping_product_tag_eligibility`, `username`, `website`)
tidak memuatnya, dan tujuh nama kandidat (`is_verified`, `is_verified_user`, `verified`,
`is_verified_account`, `verification_status`, `is_business_account`, `account_type`)
semuanya dijawab `#100`. Blog pihak ketiga yang bilang sebaliknya salah.

`probe.php` menulisnya ke `storage/profiles/{username}.json` + `.jpg`, `FetchAccount`
memindahkannya ke kolom `source_accounts` lewat `SourceAccount::profileFromDisk()` saat
fetch berhasil (file tidak ada = kolomnya tidak disentuh, bukan dikosongkan).

Untuk mengisi/menyegarkan tanpa men-scrap post: `php artisan accounts:profile [--all]`
(defaultnya cuma akun yang `followers_count`-nya masih kosong). Jalurnya
`php probe.php profile <username…>` — `business_discovery` **tanpa** ekspansi
`media{children{}}`, dan ekspansi itulah yang membakar `total_time`; 196 akun terukur
cuma menggerakkan kuota beberapa persen, sementara `fetch` ulang untuk jumlah yang sama
akan menghabiskannya. Satu akun yang gagal tidak menghentikan sisa daftarnya. Foto profilnya **di-download**, bukan disimpan
URL-nya — `profile_picture_url` itu signed CDN `scontent` yang mati dalam hitungan hari,
aturan yang sama dengan `media_url`; dilayani route `/avatar/{username}.jpg` yang juga
dikunci ke env local. Bukan di `storage/raw` karena raw itu per-post dan ikut di-prune.

Angka di baris akun ada **dua satuan**: `pengikut/diikuti/post IG` itu isi akunnya di
Instagram, sedangkan `terunduh/paket/ditolak` cuma yang sudah masuk pipeline kita —
`media_count` 1.445 vs 9 terunduh itu wajar, bukan tanda ada yang hilang.

Daftarnya tabel dengan kolom yang bisa diurut lewat query string (`?sort=&dir=`,
whitelist `AccountController::SORTS` — `account`/`followers`/`following`/`ig_posts`/
`downloaded`/`packages`/`rejected`/`last_fetched`, nilai asing balik ke urutan default).
Urutnya **di PHP**, bukan SQL: tiga kolomnya (`downloaded`/`packages`/`rejected`)
dihitung dari `storage/raw` + tabel lain, jadi tidak ada kolom yang bisa di-`ORDER BY`.

Satu jebakan Blade di `accounts.blade.php`: file ini memakai directive php **sebaris**
(`@php(...)`), dan blok raw dipungut compiler **sebelum** komentar dibuang — jadi
menyebut penutup bloknya di dalam `{{-- --}}` sekalipun akan dipasangkan dengan
directive sebaris di atasnya dan menelan markup di antaranya jadi PHP mentah (HTTP 500).

Urutannya: yang **gagal** paling atas, lalu yang belum pernah di-scrap, sisanya menurut
`last_fetched_at`. Alasan gagal ada di kolom `source_accounts.last_error` — diisi saat
fetch gagal, dikosongkan saat berhasil, jadi isinya status percobaan terakhir dan bukan
riwayat. `last_fetched_at` tetap penanda terakhir **berhasil**, jadi satu baris bisa
menampilkan dua-duanya sekaligus.

**"Gagal" bukan sekadar `last_error` terisi** (`SourceAccount::gagal()`): harus error
DAN belum pernah berhasil di-scrap DAN kosong beneran — nol post terunduh, nol paket,
nol post dikecualikan. Rate limit, `database is locked`, dan timeout menempel di akun
yang datanya sudah ada; terhitung 25 dari 189 akun berstempel "gagal" padahal isinya ada
dan tidak ada yang perlu ditindak. Yang error tapi berisi tetap menampilkan pesannya
(abu-abu, bukan merah) dan tidak naik ke atas.

**Tidak ada tombol "scrap semua".** Tanpa filter, 189 akun masuk antrian `ig` yang cuma
satu worker — kuota Graph (`total_time`) habis di tengah jalan dan sebagian besar akun
cuma di-fetch ulang untuk post yang itu-itu juga. `fetchAll()` menolak request tanpa
satu pun filter, bukan mengartikannya "semua". Yang tersisa tiga tombol, semuanya cuma
opsi `packages:crawl`: **yang gagal** (`--failed`), **yang belum pernah** (`--new`), dan
**> N jam** (`--cooldown=N`, input angkanya di sebelah tombol) — yang terakhir itu untuk
putaran rutin. Dispatch-nya diurut **terlama duluan**: satu worker + kuota yang bisa
habis di tengah jalan berarti urutan menentukan siapa yang kebagian.

`--failed` menyaring `last_fetched_at` null + `last_error` terisi saja — "kosong beneran"
itu hitungan dari disk + tabel lain, tidak ada kolomnya untuk di-`WHERE`. Yang kelebihan
cuma akun yang postnya kadung terunduh lalu fetch-nya putus, dan itu memang yang mau
diulang.

**Bulk scrap/blokir/hapus lewat centang per baris** (`POST /accounts/bulk`,
`AccountController::bulk`, `action` = `crawl|force|block|delete`). Satu endpoint, bukan
empat: yang beda cuma satu cabang di dalam loop, dan tindakannya memanggil method model
yang sama dengan tombol per barisnya (`purge()`, `purgeAndDelete()`, `FetchAccount`) —
tidak ada jalur kedua yang bisa menyimpang. Akun `blocked` dilewat diam-diam saat
`crawl`/`force`.

Checkbox-nya menunjuk ke `<form id="bulk">` **di luar tabel** lewat atribut HTML5
`form="bulk"`. Tabelnya sudah memuat form per baris di kolom terakhir; kalau tabelnya
dibungkus form lagi, form bersarang itu tidak valid dan browser membuang yang di dalam —
tombol scrap/blokir/× per baris mati diam-diam. JS-nya cuma tampilan (bar muncul, angka
terpilih, pilih semua, shift-klik pilih serentetan) + konfirmasi; submitnya form biasa.
Shift-klik memang tidak ada bawaannya — checkbox tidak saling kenal, jadi jangkarnya
(baris yang diklik terakhir) disimpan sendiri dan daftar barisnya dibaca ulang tiap klik
supaya ikut urutan `?sort=` yang sedang tampil.

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

**Gerbangnya login + peran, bukan `app()->isLocal()`.** Dulu semua alat kerja dikunci
`abort_unless(app()->isLocal(), 404)` per-method. Itu cukup selama portalnya cuma
jalan di laptop, tapi begitu di-deploy `/accounts` jadi 404 buat pemiliknya sendiri —
env sebagai kunci berarti pilihannya cuma "mati" atau "terbuka buat semua orang".

Sekarang **tiga lapis** di `routes/web.php`: publik, `auth`, lalu `auth` + `can:admin`.

- publik: `/`, `/packages/{id}` (published saja), `/flyers/{media}/{i}.jpg` (published saja).
- `auth`: `/suggestions` + `POST /accounts` + `POST /posts` + `/posts/{media}/{i}.jpg` —
  usulan, tidak menjalankan apa pun.
- `auth` + `can:admin`: sisanya. `/accounts` + aksinya, `/posts` + aksinya, `/pipeline/*`,
  `/avatar/*`, `/users` + `PATCH /users/{user}`, dan kelima aksi per kartu (`feedback`,
  `destroy`, `status`, `reextract`, `refetch`).

Kuncinya di route, bukan di dalam controller, supaya method baru di controller yang
sudah ada ikut terkunci tanpa perlu ingat menambahkan `abort_unless`. Izinnya cuma satu
gate — `Gate::define('admin')` di `AppServiceProvider`, dipakai juga `@can('admin')` di
`layout.blade.php` untuk memilih menunya. Menu bukan kunci: yang menolak tetap route.

**Dua peran, kolom `users.role`: `admin` dan `user`.** Defaultnya `user` — dan sejak
pendaftaran terbuka lewat Google, itu bukan cuma jaring pengaman: **siapa pun yang masuk
lewat SSO selalu lahir sebagai `user`**. Tidak ada peran ketiga dan tidak ada tabel izin.

**Naik jadi `admin` cuma lewat SQLite/tinker — sengaja tidak ada tombolnya.**
`/users` cuma bisa menangguhkan. Alasannya pendaftarannya terbuka: halaman itu akan
panjang dan penuh nama yang tidak dikenal, dan satu klik salah di baris yang salah
berarti menyerahkan kuota Graph + tagihan model ke orang asing. Menaikkan seseorang
layak dibayar dengan membuka terminal:
`User::where('email',…)->update(['role' => 'admin'])`.

Menghapus baris pengguna juga tidak disediakan: orangnya tinggal login lagi lewat Google
dan barisnya lahir kembali. Yang benar-benar menahan itu `suspended_at`.

Pratinjau di `/` (paket non-published + tombol aksi per kartu) menyala untuk **admin**;
`?all=0` mematikannya buat melihat persis seperti pengunjung, saklarnya di menu.
Peran `user` melihat portalnya persis seperti tamu — `show()` dan `FlyerThumbController`
ikut menyaring `isAdmin()`, jadi tidak ada jalur detail yang membocorkan paket yang
belum lolos review.

**Usulan tidak menjalankan apa pun — untuk semua peran.** `/suggestions` itu satu
halaman, satu form (post), plus daftar kiriman sendiri + statusnya. Judulnya "Usulan
saya" dan isinya sama persis untuk `user` maupun admin; tidak ada satu pun cabang
`if ($admin)` yang tersisa, di controller maupun di markup. Bedanya cuma siapa yang
boleh menekan tombol approval-nya, dan itu ada di halaman lain.

Textarea "usul akun" **dibuang dari halaman ini**: form post sudah membuat akunnya
sekalian (`firstOrCreate` di `store()`), jadi dua kotak untuk satu maksud. Endpoint
`POST /accounts` tetap ada — itu yang dipakai textarea di `/accounts`, dan itu jalur
admin yang langsung `approved`. Konsekuensinya peran `user` tidak bisa mengusulkan akun
tanpa sekalian mengirim satu postnya; itu memang saringan yang dimau — usul akun kosong
tidak bisa dinilai admin.

- **akun**: `SourceAccount::firstOrCreate($user, ['status' => 'pending', 'suggested_by' => <email>])`.
  Yang menahannya `status`, bukan kolom `suggested_by` — semua jalur crawl menyaring
  `approved`. Approval di `/accounts`: tabel usulan di atas daftar kerja, tombolnya
  memanggil endpoint bulk yang sama (`action=approve`).
- **post**: raw ditulis seperti biasa, tapi `post.json` dapat `_suggested_by` dan
  `ExtractPost` **tidak** di-dispatch. `ExtractPending` menyaring kuncinya dengan
  `str_contains`; pemindai yang lain (`probe.php extract` tanpa `--only`) melewatinya
  juga. Approval = tombol **setujui & baca** di `/posts` (tab **usulan**), per baris
  maupun kelompok (`POST /posts/bulk` dengan `action=extract` — aksinya memang sama,
  yang beda cuma katanya di tab usulan). Jalurnya persis `bacaUlang()`: penandanya
  dibuang lalu `ExtractPost` dilempar. Menolak = tombol blokir yang sudah ada.

**Menyetujui postnya sekalian menyetujui akunnya.** `store()` membuat akunnya `pending`,
dan akun `pending` tidak ikut satu pun putaran scrap — jadi approval post yang tidak
menyentuh akunnya menyisakan satu baris menggantung di `/accounts` per usulan, yang harus
di-approve lagi satu-satu untuk sesuatu yang sudah diputuskan. `bacaUlang()` menaikkannya
saat membuang `_suggested_by`, dilingkupi `status = pending`: `blocked` itu vonis bukan
antrian, jadi baca ulang post hasil scrap tidak pernah menghidupkannya kembali.

Usulan **tidak boleh menimpa** post yang sudah ada (`store()` menolak kalau `akun()`
menemukannya). Tanpa itu kiriman ulang dari peran `user` = tombol hapus paket yang sudah
di-review, cuma lewat pintu lain — `store()` memang membuang raw + hasil ekstraksi +
baris paket se-`media_id` supaya kiriman admin bisa menimpa.

Lolos approval **tidak** berarti jadi paket: gerbang vision tetap menilai dan
`belumLengkap()` tetap berlaku. Tidak ada yang bisa publish lewat sini.

**URI, nama route, dan query param semuanya bahasa Inggris; label UI tetap
Indonesia.** Batasnya jelas: yang masuk address bar, bookmark, log akses, dan
`route:list` itu permukaan teknis — dibaca alat dan orang lain, jadi ikut konvensi
Laravel. Teks yang dibaca operator (label kolom, pesan `with('status')`, tooltip,
komentar) tetap Indonesia; itu bahasa produknya. Jangan campur: `?sort=paket` yang
menghasilkan label "paket" bikin kelihatan seolah nilainya berasal dari data.

Auth-nya masih di kerangka Breeze walau starter kit-nya tidak dipasang: `/login` +
`/logout`, `Auth\AuthenticatedSessionController` dengan `create`/`store`/`destroy`
(+ `callback`), `resources/views/auth/login.blade.php`. Nama route `login`
**wajib** — itu yang dituju middleware `auth` saat menolak tamu.

Sisanya jamak + kata kerja Inggris: `/accounts`, `/accounts/crawl`, `/accounts/bulk`,
`/posts` + `/accounts/{account}/posts` (`?filter=packages|rejected|pending|suggestions`),
`/posts/bulk`, `/suggestions` + `POST /posts`, `/posts/{media}/extract`, `/packages/{id}`,
`/pipeline/queue/{queue?}`, `/pipeline/queue/retry/{queue?}`, `/flyers/{media}/{i}.jpg`,
`/users` + `PATCH /users/{user}`, `/login/callback`, `/extension` + `/extension.zip`.
Param: `?all=` (pratinjau), facet `?account=`, `?sort=` + `?dir=`, `new`/`failed`/`hours`
(scrap kelompok), `force` (scrap paksa), `unblock`, `action` (bulk),
`suspended` (penangguhan pengguna).

## Chrome extension

Folder `extension/` (MV3, tanpa build step). Isinya otomatisasi dari apa yang sudah
dikerjakan tangan di `/suggestions`: baca permalink + username + caption + semua slide
carousel dari halaman post yang **sedang dibuka operator**, lalu kirim. Nol request ke
Graph, nol private API — kalau ini terhitung melanggar "jangan scraping unofficial",
yang dibuang extension-nya, bukan aturannya.

Unduhnya dari menu burger (`GET /extension.zip`, `PostController::extension()`), dirakit
dari folder itu saat diminta — tidak ada zip yang bisa basi sesudah satu file diedit.
Pasangnya Load unpacked di `chrome://extensions`; `.crx` yang di-double-click ditolak
Chrome sejak versi 33 dan tidak ada flag yang membukanya, jadi jalur "sekali klik" cuma
Chrome Web Store — teks submitnya di `extension/STORE.md`, zip yang sama itu paketnya.

**Menunya menunjuk halaman `/extension`, bukan langsung ke zip-nya** (`Route::view`,
`extension.blade.php`; zip-nya pindah nama route jadi `extension.download`). Yang
diunduh itu zip yang harus diekstrak lalu di-Load unpacked dengan Developer mode
menyala — enam langkah yang tidak bisa ditebak sendiri, dan tautan unduh telanjang
cuma menyisakan folder yang tidak ada gunanya di Downloads. Halamannya sekalian memuat
alamat portal ini (`url('/')`) untuk ditempel ke options extension-nya, cara pakai, dan
tiga kegagalan yang paling sering.

**Alamat portalnya diatur di halaman options, tidak boleh hardcode.** Portalnya dipasang
sendiri-sendiri, jadi satu alamat tetap cuma benar buat satu orang — dan extension yang
dibagikan lewat Web Store dengan `localhost:8000` tertanam tidak bisa dipakai siapa pun.
Konsekuensinya `fill.js` **tidak** didaftarkan lewat `content_scripts` di manifest
(pola `matches`-nya baru diketahui sesudah alamatnya diisi) melainkan lewat
`chrome.scripting.registerContentScripts` di `onInstalled`/`onStartup` — pendaftaran
dinamis tidak selalu selamat dari restart browser. Izin hostnya `optional_host_permissions`
yang diminta saat form options disimpan; `chrome.permissions.request` menolak panggilan
tanpa gestur klik, jadi tempatnya memang di situ dan bukan di popup.

**Mode grid mengemudikan tab, bukan mengambil datanya dari grid.** Tile di halaman
profil memuat caption penuh (`alt` gambarnya) dan satu thumbnail, tapi **tidak**
memuat tanggal posting maupun slide carousel lainnya — dan tanggal posting itu
jangkar tahun yang wajib diisi dan tidak boleh dikira-kira. Jadi yang dipanen dari
grid cuma daftar permalinknya (`kumpulLink()`, `/p/` saja: reel & tv itu video,
dan satu halaman IG yang dimuat percuma jauh lebih mahal daripada satu href yang
dibuang); sesudah itu tiap post dibuka satu per satu lewat `chrome.tabs.update` dan
lewat `panen()` + `kirim()` yang **sama persis** dengan tombol satuan. Tidak ada
jalur kirim kedua.

**Gridnya discroll sendiri, dan selalu dari paling atas.** IG melepas tile yang
keluar layar dari DOM (windowing), dan itu dua masalah sekaligus: tile di bawah
belum ada sampai discroll ke sana, **dan** sesudah operator scroll jauh yang terbaca
`querySelectorAll` cuma tile di sekitar viewport — putarannya mulai dari post paling
bawah, bukan yang teratas (terukur: 48 post ter-scroll, yang kepanen justru ekornya).
`kumpulLink(target)` karena itu `scrollTo(0, 0)` dulu lalu memanen sambil turun ke
dalam `Set`, jadi urutannya urutan pertama-kali-terlihat = urutan grid. Berhenti
kalau target tercapai, grid tidak tumbuh **5 putaran** berturut-turut (bukan sekali —
IG sering perlu satu-dua putaran untuk batch berikutnya), atau 200 putaran.

Konsekuensinya target ditentukan **sebelum** tautannya ada, jadi popup tidak lagi
menampilkan "N post di halaman ini" dan tombol stop disembunyikan selama fase kumpul:
pengumpulannya satu `executeScript` di halaman IG yang tidak membaca `antre`.

Dibuka lewat navigasi URL, bukan klik thumbnail: dialog overlay bergantung pada
markup grid yang class-nya diacak, sementara permalinknya sudah ada di `href` tiap
tile. Loopnya di service worker (`jalanGrid()`), bukan di popup — satu putaran 9
post makan menit-menitan dan popup Chrome menutup diri begitu operator mengklik apa
pun di luarnya; popup yang dibuka lagi cuma membaca `antre` lewat `statusGrid`.
Kemajuannya menempel di **badge ikonnya** (`chrome.action.setBadgeText`, sisa post —
badge cuma muat ~4 karakter, kalimat penuhnya di `setTitle`), bukan cuma di popup:
tanpa itu satu-satunya cara tahu putarannya masih jalan adalah membuka popupnya lagi.
Izin host portal dicek **sekali di depan**, bukan per post — kalau tidak, sembilan
post dibuka satu-satu cuma untuk gagal `belum-diatur` dengan sebab yang sudah pasti.
Post yang sudah ada ditolak portal dan itu dihitung **dilewat**, bukan gagal:
putaran kedua atas profil yang sama kalau tidak tampil sebagai sembilan galat merah.

**Tidak ada endpoint, token, atau kolom baru untuk extension.** Kirimannya lewat tab
tersembunyi ke `/suggestions` yang mengisi form itu lalu `fetch` POST-nya: cookie sesi,
token CSRF, `auth`, dan validasi yang sudah ada terpakai apa adanya. Kredensial kedua
di dalam extension berarti gudang rahasia kedua yang harus dijaga dan dicabut, dan
`SESSION_SAME_SITE=none` supaya cookie-nya terkirim lintas-origin.

Kirimannya **tidak beda sedikit pun** dari form manual — dulu ada `overwrite=0` supaya
kiriman otomatis tidak menimpa paket yang sudah di-review, sekarang tidak ada kiriman
yang menimpa apa pun jadi parameternya inert. Yang tersisa cuma kesepakatan supaya
kegagalan tidak diam:

- header `X-Requested-With` → `ValidationException` balas 422 JSON, jadi pesan aslinya
  bisa ditampilkan tanpa mengurai HTML halaman.
- `role="status"` / `role="alert"` di `x-ui.alert` — pengait "kiriman ini selamat" buat
  jalur manual (kelas Tailwind-nya berubah tiap ganti tema, `role` tidak).
- lemparan ke `/login` diperiksa dua kali (URL tab saat dibuka, `res.url` sesudah POST):
  fetch mengikuti redirect, jadi sesi yang mati terbaca 200 = "berhasil" palsu.

Video dilewat dengan aturan yang sama seperti `probe.php`, tapi saringannya **elemen**
`<video>` per slide, bukan URL — poster video juga `<img>` dari `scontent`.

**Kunci JSON `/pipeline/status` sengaja TIDAK ikut** (`post_diunduh`, `hasil_ekstraksi`,
`antri_ig`, `alasan`, `sekarang`, `jalan`). Itu payload internal buat satu panel di
`accounts.blade.php`, bukan API, dan namanya justru menjelaskan aturan corong yang
ditulis panjang di dokumen ini — menerjemahkannya memutus rujukan itu tanpa ada yang
membaca hasilnya.

## Login: Google SSO, tidak ada gerbang kedua

**Sandi dibuang seluruhnya — `user:create` ikut dihapus.** Dulu akun dibuat dari CLI,
dan itu cukup selama operatornya satu orang. Begitu ada peran `user` yang mengusulkan
post, "bikin akun dari terminal" berarti tiap contributor menunggu seseorang membuka
laptop. Google yang menanggung verifikasi email + 2FA-nya; yang tersisa di sini cuma
peran dan penangguhan. Kolom `password` ditinggal nullable supaya baris lama tidak perlu
dihapus — isinya tidak pernah dibaca lagi.

Tidak ada sandi cadangan dan itu pilihan sadar: gerbang kedua = satu jalur lagi yang
bisa ditebak, dan justru itu yang paling gampang jebol. Kalau Google-nya mati, tidak ada
yang masuk.

**Dikerjakan tangan, bukan Socialite.** Authorization code flow untuk confidential client
itu tiga request (authorize → token → userinfo) — satu paket lagi untuk itu adalah
dependency yang tidak dibayar. Yang tidak boleh dilewat cuma dua: `state` sekali pakai
(`session()->pull`, dibanding `hash_equals` — tanpa itu siapa pun bisa memaksa korban
login ke akun penyerang) dan `email_verified` (tanpa itu siapa pun bisa mendaftarkan
alamat orang lain di Workspace-nya sendiri lalu masuk sebagai dia). ID token-nya sengaja
**tidak** diverifikasi tanda tangannya: kodenya ditukar langsung ke
`oauth2.googleapis.com` lewat TLS dengan `client_secret` kita, jadi balasannya sudah
terpercaya. Verifikasi JWT baru perlu kalau tokennya datang lewat browser.

`POST /login` (bukan tautan) yang berangkat ke Google: rutenya sudah ber-CSRF dan
`throttle:5,1`, jadi tidak ada yang bisa memancing orang lain memulai login dari halaman
lain. Baliknya `GET /login/callback` — URI itu wajib **persis** sama dengan yang
didaftarkan di Google Cloud Console (`GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET`,
`GOOGLE_REDIRECT_URI` cuma untuk kasus proxy yang menulis ulang path).

**Barisnya dicari lewat email, bukan `google_id`.** Admin pertama disemai migrasi dan
belum pernah punya `sub`; cari lewat id-nya saja = dia lahir kembar dan kehilangan
perannya. `role` tidak pernah ditulis ulang untuk baris yang sudah ada — kalau tidak,
login berikutnya menurunkan adminnya sendiri.

**`dimitry.adam@gmail.com` admin tunggal**, disemai migrasi
`2026_08_02_000001_google_sso_and_suspend`. Migrasi yang sama **menurunkan semua baris
lain jadi `user`**: migrasi peran sebelumnya menaikkan semua baris jadi admin karena
saat itu satu-satunya jalan bikin user adalah CLI, dan asumsi itu mati begitu siapa pun
bisa mendaftar.

**Penangguhan digigit tiap request, bukan cuma saat login.** `EnsureNotSuspended`
dipasang di grup **`web`** (`bootstrap/app.php`), bukan di grup route `auth`: sesi hidup
120 menit dan `remember` memperpanjangnya berbulan-bulan, jadi cek yang cuma ada di
callback berarti yang ditangguhkan tetap jalan sampai cookienya mati. Di `web` karena
ada dua grup `auth` di `routes/web.php` dan halaman publik pun merender menu operator
kalau yang membuka sedang login.

Tidak ada reset lewat email (`MAIL_MAILER=log`, tidak ada email yang benar-benar keluar)
— tidak perlu, tidak ada sandi. Manajemen datanya langsung ke SQLite
(`database/database.sqlite`) atau `artisan tinker`.

**Sebelum deploy:** `APP_DEBUG=false` (halaman error debug memuat isi env — itu
jalur bocornya `IG_ACCESS_TOKEN` & `AI_API_KEY` yang paling gampang),
`APP_ENV=production`, `SESSION_SECURE_COOKIE=true` (butuh HTTPS), `FLYER_DISK=s3`
+ `AWS_*`, `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET` dengan redirect URI produksi
didaftarkan di Google Cloud Console (`https://<domain>/login/callback`, dicocokkan
persis — tanpa itu tidak ada yang bisa masuk sama sekali),
lalu `php artisan migrate --force` dan `npm run build`. `.env`,
`database/*.sqlite`, `storage/raw|extracted|profiles|flyers` semuanya di-gitignore;
tidak ada `env()` di luar `config/` (kecuali `probe.php`, yang punya pembacanya
sendiri dan tidak lewat Laravel), jadi `php artisan config:cache` aman.

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
hasil ekstraksi. Halaman `/pipeline` menampilkannya terbuka terus di blok "jejak detail".

Catatan: stdout `queue:work` sendiri block-buffered saat dipipe, jadi baris
"Processing:" bisa muncul terlambat. Baris detail tidak terpengaruh — job menulis
langsung ke file, bukan lewat stdout worker.

Redis/Horizon belum dipasang; queue pakai driver `database` (jalankan
`php artisan queue:work`).

**`LOG_STACK=daily`, bukan `single`.** Channel `single` tidak pernah dirotasi sama
sekali: `laravel.log` pernah tembus 2,6 GB — lebih berat dari seluruh `storage/raw`
saat itu — karena satu stacktrace yang berulang. `daily` bikin file per hari dan
menghapus yang lebih tua dari `LOG_DAILY_DAYS` (3) tiap kali menulis.

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

Pemicunya: tombol `scrap` di `/accounts` (atau `php artisan packages:crawl accounts.txt`)
yang cuma mengantrikan job. Tidak ada langkah manual di antara fetch, extract, dan import —
`ig` menyelesaikan satu akun, `db` langsung memindainya ke `ai`, dan `ai` tidak
menunggu akun berikutnya.

Paket masuk sebagai `review` atau `draft`, tidak pernah langsung `published`.
Publish = pilih `published` di select status pada kartu (pratinjau `/`), setelah
datanya dilihat.
