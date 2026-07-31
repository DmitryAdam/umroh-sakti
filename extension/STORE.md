# Submit ke Chrome Web Store

Teks siap tempel untuk form di [Developer Dashboard](https://chrome.google.com/webstore/devconsole).
Biaya pendaftaran $5 sekali seumur akun. Distribusi: **Unlisted** — tidak muncul di
pencarian, cuma yang punya link yang bisa memasang.

Paket uploadnya zip dari `GET /extension.zip` (menu burger portal). Tidak ada langkah
build; isinya sudah persis yang diminta.

## Single purpose

> Mengirim satu postingan Instagram yang sedang dibuka pengguna — caption, nama akun,
> tanggal, dan gambar carousel-nya — ke portal Umroh Sakti milik pengguna sendiri.

Satu kalimat, satu maksud. Reviewer menolak extension yang maksudnya jamak.

`description` di manifest maksimal **132 karakter** — upload ditolak kalau lewat, dan
angkanya karakter, bukan byte. Deskripsi panjang tempatnya di listing, bukan di manifest.

## Deskripsi listing

> Umroh Sakti mengumpulkan penawaran paket umroh dari postingan travel di Instagram
> supaya bisa dicari dan dibandingkan. Sebagian flyer tidak terjangkau API resmi
> Instagram — postingan lama yang di-pin, misalnya. Extension ini jalan masuk untuk
> yang begitu.
>
> Buka satu postingan Instagram, klik ikon extension, periksa datanya, lalu kirim.
> Caption penuh, nama akun, tanggal posting, dan semua slide carousel terbaca sendiri.
> Postingan video dilewat.
>
> Extension ini tidak punya server. Kiriman masuk ke portal Umroh Sakti yang kamu
> jalankan sendiri, memakai sesi login browser kamu di portal itu — alamatnya diisi
> di halaman pengaturan. Tidak ada data yang dikirim ke pihak lain.

## Justifikasi permission

Kolom ini wajib diisi per permission; kosong = ditolak.

| permission | justifikasi |
|---|---|
| `scripting` | Membaca caption, nama akun, tanggal, dan URL gambar dari halaman post yang sedang dibuka pengguna, lalu mengisikannya ke formulir portal. Tidak ada kode remote — semua skrip ikut di dalam paket. |
| `storage` | Menyimpan alamat portal pengguna, dan menitipkan data satu post di antara popup dan halaman portal saat dikirim. |
| `tabs` | Mengetahui postingan mana yang sedang dibuka, dan menutup tab pengiriman setelah selesai. |
| `https://www.instagram.com/*` | Satu-satunya tempat data postingan dibaca. |
| `https://*.cdninstagram.com/*`, `https://*.fbcdn.net/*` | Mengunduh gambar postingan; CDN inilah yang melayaninya. |
| `optional_host_permissions` `http://*/*`, `https://*/*` | Portal Umroh Sakti dipasang sendiri oleh tiap pengguna, jadi alamatnya tidak bisa ditulis di muka. Izin diminta saat pengguna mengisi alamatnya di halaman pengaturan, hanya untuk alamat itu. |

## Data usage

Centang **tidak** untuk semuanya, lalu ketiga pernyataan di bawahnya:

- tidak menjual data ke pihak ketiga
- tidak memakai/mengirim data untuk tujuan di luar fungsi utamanya
- tidak memakai/mengirim data untuk menentukan kelayakan kredit atau pinjaman

Extension ini tidak mengumpulkan apa pun ke server mana pun. Data postingan mengalir
dari tab Instagram pengguna langsung ke portal pengguna sendiri.

## Privacy policy

Wajib ada URL-nya. Isi minimal:

> Extension Umroh Sakti tidak mengumpulkan, menyimpan, atau mengirimkan data pribadi
> ke server milik pengembang. Extension membaca isi postingan Instagram yang sedang
> dibuka pengguna, lalu mengirimkannya ke portal Umroh Sakti yang alamatnya diisi
> sendiri oleh pengguna. Alamat itu disimpan di penyimpanan extension pada perangkat
> pengguna. Tidak ada analytics, tidak ada pelacakan, tidak ada pihak ketiga.

## Aset

- ikon 128×128 — sudah ada (`icon-128.png`)
- screenshot 1280×800 atau 640×400, minimal satu — belum ada. Bidik popup extension
  di atas halaman post Instagram; jangan memuat data pribadi akun siapa pun.
- promo tile 440×280 — opsional, dilewat saja

## Sesudah terbit

Update = naikkan `version` di `manifest.json`, upload zip baru. Yang sudah memasang
dapat sendiri dalam beberapa jam. Review update biasanya lebih cepat dari yang pertama.
