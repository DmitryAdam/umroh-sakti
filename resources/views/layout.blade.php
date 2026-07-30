<!doctype html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Cari Paket Umroh')</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600&display=swap" rel="stylesheet">
    {{-- Tailwind + token shadcn di-build lewat Vite (`npm run build`), bukan CDN:
         token-nya ada di resources/css/app.css dan CDN tidak membacanya. --}}
    @vite('resources/css/app.css')
</head>
<body class="min-h-full bg-background font-sans text-foreground antialiased">
    <header class="sticky top-0 z-40 border-b bg-background/85 backdrop-blur">
        <div class="mx-auto flex h-14 max-w-7xl items-center gap-3 px-4">
            <a href="{{ route('search') }}" class="text-base font-semibold tracking-tight">Umroh Sakti</a>
            <p class="hidden text-sm text-muted-foreground sm:block">Bandingkan paket umroh dari berbagai travel</p>
        </div>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-6">@yield('content')</main>

    <footer class="mx-auto max-w-7xl px-4 py-8 text-xs text-muted-foreground">
        Data dikumpulkan dari postingan publik travel. Selalu konfirmasi ke travel
        bersangkutan sebelum melakukan pembayaran.
    </footer>
</body>
</html>
