<!doctype html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Cari Paket Umroh')</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="preconnect" href="https://fonts.bunny.net">
    {{-- Lalezar = huruf Latin bergaya khat Arab, dipakai HANYA untuk logo (utility
         `font-display`). Isi halaman tetap Inter — font display begini tidak
         terbaca untuk angka harga dan tabel. --}}
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600|lalezar:400&display=swap" rel="stylesheet">
    {{-- Tailwind + token shadcn di-build lewat Vite (`npm run build`), bukan CDN:
         token-nya ada di resources/css/app.css dan CDN tidak membacanya. --}}
    @vite('resources/css/app.css')
</head>
<body class="min-h-full bg-background font-sans text-foreground antialiased">
    <header class="sticky top-0 z-40 border-b bg-background/85 backdrop-blur">
    {{-- Satu baris untuk semuanya: logo, bar cari (`@section('bar')` — cuma diisi
         halaman pencarian), burger. Dulu barnya baris sendiri di bawah header, dan
         dua baris sticky memakan sepertiga layar sebelum kartu pertama kelihatan.
         `min-h` + `flex-wrap`, bukan tinggi tetap: di layar sempit bar-nya turun
         baris dan headernya ikut tinggi, bukan terpotong. --}}
        <div class="flex min-h-12 flex-wrap items-center gap-3 px-4 py-1.5">
            {{-- Logonya file yang sudah ada (apple-touch-icon), bukan aset kedua:
                 gambarnya sama persis dan satu salinan berarti satu yang perlu
                 diganti kalau lambangnya berubah. --}}
            <a href="{{ route('search') }}" class="flex items-center gap-2">
                <img src="/apple-touch-icon.png" alt="" width="28" height="28" class="size-7">
                <span class="font-display text-xl leading-none text-primary">Umroh Sakti</span>
            </a>

            @hasSection('bar')
                <div class="order-last w-full min-w-0 sm:order-none sm:mx-auto sm:w-auto sm:max-w-4xl sm:flex-1">@yield('bar')</div>
            @endif

            {{-- Menu = perannya, satu tempat, di balik burger: enam tautan berjajar
                 memakan separuh header dan tidak satu pun dipakai pengunjung. Tamu
                 cuma lihat "masuk" (satu tautan tidak perlu burger); peran `user`
                 dapat satu pintu (usulan); admin dapat alat kerjanya. Kuncinya
                 sendiri tetap di route (`auth` + `can:admin`) — ini cuma yang
                 kelihatan, jadi menu yang kelewat bukan lubang.

                 <details>, bukan JS: sama dengan popover filter, dan Esc/klik-luar
                 ditangani listener combo yang sudah ada di halaman pencarian —
                 di halaman lain cukup klik summary-nya lagi. --}}
            <nav class="ml-auto flex items-center gap-1 text-sm">
                @auth
                    @php
                        $menu = Gate::allows('admin')
                            ? ['posts' => 'post', 'accounts' => 'akun & pipeline', 'suggestions' => 'usulan saya', 'users' => 'pengguna']
                            : ['suggestions' => 'usulan saya'];
                        $item = 'block rounded-md px-2 py-1.5 hover:bg-accent hover:text-accent-foreground';
                    @endphp
                    <details class="relative">
                        <summary class="grid size-9 cursor-pointer place-items-center rounded-md text-muted-foreground hover:bg-accent hover:text-accent-foreground marker:content-none [&::-webkit-details-marker]:hidden">
                            <x-ui.icon name="menu" class="!size-5" />
                        </summary>
                        <div class="absolute right-0 top-full z-50 mt-1 w-52 rounded-xl border bg-popover p-1 text-popover-foreground shadow-lg">
                            @foreach ($menu as $name => $label)
                                <a href="{{ route($name) }}"
                                   class="{{ $item }} {{ request()->routeIs($name) ? 'bg-accent font-medium' : '' }}"
                                >{{ $label }}</a>
                            @endforeach

                            {{-- Pratinjau menyala default untuk admin; ini saklarnya, dan
                                 tempatnya di menu — dulu satu baris spanduk di atas hasil
                                 pencarian yang cuma jadi noise di tiap halaman. --}}
                            @if (request()->routeIs('search') && Gate::allows('admin'))
                                <a href="{{ request()->fullUrlWithQuery(['all' => request()->boolean('all', true) ? 0 : 1]) }}"
                                   class="{{ $item }}"
                                >{{ request()->boolean('all', true) ? 'lihat sebagai pengunjung' : 'pratinjau operator' }}</a>
                            @endif

                            {{-- Chrome extension: kirim post langsung dari halaman
                                 Instagram. Dirakit dari folder `extension/` saat
                                 diunduh, jadi tidak ada zip yang bisa basi. --}}
                            <a href="{{ route('extension') }}" class="{{ $item }} border-t mt-1 pt-2">extension chrome</a>

                            <form method="POST" action="{{ route('logout') }}" class="border-t pt-1 mt-1">
                                @csrf
                                <button class="{{ $item }} w-full cursor-pointer text-left text-destructive">keluar</button>
                            </form>
                        </div>
                    </details>
                @else
                    <x-ui.button as="a" variant="ghost" size="sm" href="{{ route('login') }}">masuk</x-ui.button>
                @endauth
            </nav>
        </div>
    </header>

    {{-- Tanpa max-w: kartu paket cuma perlu grid yang lebih lebar, bukan kolom
         teks — bar filternya sticky pakai `-mx-4` yang bergantung ke padding ini. --}}
    {{-- pt kecil: bar cari di `/` sudah punya padding sendiri dan sticky tepat di
         bawah header — pt-6 di sini bikin jarak header ke bar menganga. --}}
    {{-- pb besar: footernya fixed, jadi kartu terakhir perlu ruang supaya tidak
         tertutup — 2 baris di layar sempit. --}}
    <main class="px-4 pb-24 pt-3">@yield('content')</main>

    <footer class="fixed inset-x-0 bottom-0 z-40 border-t bg-background/85 px-4 py-3 text-xs text-muted-foreground backdrop-blur">
        Data dikumpulkan dari postingan publik travel. Selalu konfirmasi ke travel
        bersangkutan sebelum melakukan pembayaran.
        {{-- Di footer, bukan di menu burger: burgernya cuma muncul untuk yang sudah
             login, sementara halaman ini justru untuk pengunjung. --}}
        <a href="{{ route('about') }}" class="underline underline-offset-4 hover:text-foreground">Tentang</a>
    </footer>
</body>
</html>
