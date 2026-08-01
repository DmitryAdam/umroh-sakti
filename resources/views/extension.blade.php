@extends('layout')
@section('title', 'Extension Chrome')

@section('content')
{{-- Halaman pasang, bukan cuma tautan unduh: paketnya zip yang harus di-Load unpacked,
     dan .crx yang di-double-click ditolak Chrome sejak versi 33 (tidak ada flag yang
     membukanya). Jadi langkahnya memang tidak bisa ditebak sendiri oleh yang mengunduh. --}}
<div class="mx-auto grid max-w-2xl gap-4">
    <div>
        <h1 class="text-lg font-semibold tracking-tight">Extension Chrome</h1>
        <p class="mt-1 text-sm leading-6 text-muted-foreground">
            Kirim postingan Instagram ke sini tanpa mengetik ulang. Buka postingannya, klik ikon
            extension, cek datanya, kirim. Caption, nama akun, tanggal, dan semua gambarnya terbaca
            sendiri.
        </p>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <x-ui.button as="a" href="{{ route('extension.download') }}" download>Unduh (.zip)</x-ui.button>
        <span class="text-xs text-muted-foreground">Chrome, Edge, Brave, atau Opera di komputer.</span>
    </div>

    <x-ui.card class="p-4">
        <h2 class="text-sm font-medium">Cara memasang</h2>
        <p class="mt-1 text-sm leading-6 text-muted-foreground">
            Sekali saja, sekitar dua menit.
        </p>

        {{-- <ol> biasa: nomornya urusan browser, dan yang membaca sambil menekan tombol
             tidak boleh kehilangan nomor langkahnya saat halaman di-zoom. --}}
        <ol class="mt-3 grid gap-3 text-sm leading-6 marker:font-semibold marker:text-muted-foreground">
            <li class="ml-5 list-decimal pl-1">
                Unduh filenya, lalu <strong>ekstrak</strong> zip-nya. Simpan foldernya di tempat yang
                tidak akan dihapus &mdash; kalau foldernya hilang, extension-nya ikut mati.
            </li>
            <li class="ml-5 list-decimal pl-1">
                Buka <code class="rounded bg-muted px-1 py-0.5 text-xs">chrome://extensions</code> di
                tab baru. Alamat itu tidak bisa diklik dari halaman ini &mdash; salin dan tempel sendiri.
            </li>
            <li class="ml-5 list-decimal pl-1">
                Nyalakan <strong>Developer mode</strong> (saklarnya di pojok kanan atas).
            </li>
            <li class="ml-5 list-decimal pl-1">
                Klik <strong>Load unpacked</strong>, lalu pilih folder hasil ekstrak tadi &mdash;
                foldernya, bukan zip-nya.
            </li>
            <li class="ml-5 list-decimal pl-1">
                Klik ikon puzzle di toolbar Chrome, lalu pin <strong>Umroh Sakti</strong> supaya
                ikonnya selalu kelihatan.
            </li>
            <li class="ml-5 list-decimal pl-1">
                Klik ikonnya sekali, pilih <strong>pengaturan</strong>, isi alamat portal
                <code class="rounded bg-muted px-1 py-0.5 text-xs break-all">{{ url('/') }}</code>,
                simpan, lalu izinkan aksesnya saat Chrome bertanya.
            </li>
        </ol>
    </x-ui.card>

    <x-ui.card class="p-4">
        <h2 class="text-sm font-medium">Cara memakai</h2>

        <ol class="mt-3 grid gap-3 text-sm leading-6 marker:font-semibold marker:text-muted-foreground">
            <li class="ml-5 list-decimal pl-1">
                Buka satu postingan paket umroh di Instagram &mdash; halaman postingannya, bukan
                halaman profil.
            </li>
            <li class="ml-5 list-decimal pl-1">
                Klik ikon Umroh Sakti. Datanya terisi sendiri; periksa nama akun dan tanggalnya.
            </li>
            <li class="ml-5 list-decimal pl-1">
                Klik <strong>Simpan &amp; tutup</strong>. Kirimannya masuk ke
                <a href="{{ route('suggestions') }}" class="underline underline-offset-4">Usulan saya</a>
                dan menunggu disetujui admin.
            </li>
        </ol>

        <p class="mt-3 text-sm leading-6 text-muted-foreground">
            Postingan video dilewat. Postingan yang sudah ada di sini akan ditolak &mdash; itu bukan
            kegagalan, cuma tandanya sudah masuk.
        </p>
    </x-ui.card>

    <x-ui.card class="p-4">
        <h2 class="text-sm font-medium">Kalau tidak jalan</h2>

        <dl class="mt-3 grid gap-3 text-sm leading-6">
            <div>
                <dt class="font-medium">Ikonnya diklik tapi tidak ada apa-apa</dt>
                <dd class="text-muted-foreground">
                    Alamat portalnya belum diisi, atau izinnya belum diberikan. Buka pengaturan
                    extension-nya lagi dan simpan ulang.
                </dd>
            </div>
            <div>
                <dt class="font-medium">Katanya harus masuk dulu</dt>
                <dd class="text-muted-foreground">
                    Extension memakai sesi login browser kamu di portal ini. Buka
                    <a href="{{ route('search') }}" class="underline underline-offset-4">halaman utama</a>,
                    pastikan sudah masuk, lalu coba lagi.
                </dd>
            </div>
            <div>
                <dt class="font-medium">Extension-nya hilang sendiri setelah restart</dt>
                <dd class="text-muted-foreground">
                    Foldernya kepindah atau kehapus. Ekstrak ulang zip-nya ke tempat yang tetap, lalu
                    Load unpacked lagi.
                </dd>
            </div>
        </dl>
    </x-ui.card>

    <p class="text-xs leading-5 text-muted-foreground">
        Extension ini tidak punya server sendiri. Data postingan mengalir dari tab Instagram kamu
        langsung ke portal ini &mdash; tidak ada pihak ketiga.
    </p>
</div>
@endsection
