@extends('layout')
@section('title', 'Tentang Umroh Sakti')

@section('content')
{{-- Halaman untuk pengunjung, bukan operator: tidak ada satu pun istilah pipeline
     di sini (lihat aturan "teks yang dibaca pengusul ditulis untuk orang awam").
     Yang perlu dijawab cuma tiga: ini apa, datanya dari mana, dan seberapa boleh
     dipercaya. --}}
<div class="mx-auto grid max-w-2xl gap-4">
    <div>
        <h1 class="text-lg font-semibold tracking-tight">Tentang Umroh Sakti</h1>
        <p class="mt-1 text-sm leading-6 text-muted-foreground">
            Tempat membandingkan paket umroh dari banyak travel dalam satu halaman &mdash;
            tanggal, lama perjalanan, hotel, maskapai, dan harganya berjajar rapi, tanpa
            perlu membuka puluhan akun Instagram satu per satu.
        </p>
    </div>

    <x-ui.card class="p-4">
        <h2 class="text-sm font-medium">Masalah yang kami rapikan</h2>
        <p class="mt-2 text-sm leading-6 text-muted-foreground">
            Hampir semua travel mengumumkan paketnya lewat poster di Instagram. Isinya
            lengkap, tapi bentuknya gambar: tidak bisa dicari, tidak bisa diurutkan dari
            yang termurah, dan tidak bisa disandingkan dengan paket travel sebelah.
            Mencari satu keberangkatan yang cocok berarti menggulir feed berjam-jam dan
            mencatat sendiri di kertas.
        </p>
        <p class="mt-2 text-sm leading-6 text-muted-foreground">
            Kami membaca poster-poster itu, lalu menuliskan isinya jadi tabel yang bisa
            disaring dan diurutkan.
        </p>
    </x-ui.card>

    <x-ui.card class="p-4">
        <h2 class="text-sm font-medium">Cara kerjanya</h2>
        <ol class="mt-3 grid gap-3 text-sm leading-6 marker:font-semibold marker:text-muted-foreground">
            <li class="ml-5 list-decimal pl-1">
                <strong>Mengumpulkan.</strong> Kami mengikuti akun Instagram resmi travel dan
                mengambil postingan barunya. Semuanya postingan publik &mdash; yang bisa
                dilihat siapa saja yang membuka akun tersebut.
            </li>
            <li class="ml-5 list-decimal pl-1">
                <strong>Membaca.</strong> Poster dan keterangannya dibaca komputer, lalu
                isinya dipisah jadi kolom: tanggal berangkat, berapa hari, hotel di Makkah
                dan Madinah, maskapai, pembimbing, dan harga per kamar. Satu poster yang
                memuat banyak tanggal keberangkatan dipecah jadi beberapa baris.
            </li>
            <li class="ml-5 list-decimal pl-1">
                <strong>Diperiksa orang.</strong> Tidak ada paket yang langsung tampil.
                Hasil bacaan komputer diperiksa dulu &mdash; terutama harganya, karena
                angka yang salah baca paling merugikan yang membacanya. Yang meragukan
                tidak ditampilkan.
            </li>
            <li class="ml-5 list-decimal pl-1">
                <strong>Ditayangkan.</strong> Yang lolos muncul di halaman depan, lengkap
                dengan tautan ke postingan aslinya supaya bisa dicek sendiri.
            </li>
        </ol>
    </x-ui.card>

    <x-ui.card class="p-4">
        <h2 class="text-sm font-medium">Yang perlu diketahui sebelum memakai</h2>
        <ul class="mt-3 grid gap-2 text-sm leading-6 text-muted-foreground">
            <li class="ml-5 list-disc pl-1">
                <strong class="text-foreground">Kami bukan travel dan tidak menjual paket.</strong>
                Tidak ada pemesanan dan tidak ada pembayaran di sini. Semua transaksi
                langsung dengan travelnya.
            </li>
            <li class="ml-5 list-disc pl-1">
                <strong class="text-foreground">Konfirmasi ulang sebelum membayar.</strong>
                Poster bisa berubah, kuota bisa habis, harga bisa naik. Yang mengikat
                adalah keterangan dari travelnya, bukan halaman ini.
            </li>
            <li class="ml-5 list-disc pl-1">
                <strong class="text-foreground">Harga yang jauh di bawah pasaran kami tandai.</strong>
                Pemerintah menetapkan harga acuan umroh; paket di bawah angka itu diberi
                peringatan di kartunya. Kami tidak menyembunyikannya &mdash; hanya
                mengingatkan supaya ditanyakan lebih teliti.
            </li>
            <li class="ml-5 list-disc pl-1">
                <strong class="text-foreground">Pastikan travelnya berizin.</strong>
                Umroh hanya boleh diselenggarakan travel berizin resmi Kementerian Agama.
                Nomor izinnya bisa ditanyakan langsung ke travel yang bersangkutan.
            </li>
        </ul>
    </x-ui.card>

    <x-ui.card class="p-4">
        <h2 class="text-sm font-medium">Untuk travel</h2>
        <p class="mt-2 text-sm leading-6 text-muted-foreground">
            Paket Anda tampil di sini dan datanya keliru, atau justru tidak mau ditampilkan
            sama sekali? Sampaikan lewat kontak yang tertera di postingan &mdash; datanya
            kami perbaiki atau kami turunkan. Kami hanya menampilkan ringkasan dan tautan
            balik ke postingan asli Anda; posternya tidak kami sebarkan ulang.
        </p>
    </x-ui.card>

    <p class="text-center text-xs text-muted-foreground">
        <a href="{{ route('search') }}" class="underline underline-offset-4">Mulai cari paket</a>
    </p>
</div>
@endsection
