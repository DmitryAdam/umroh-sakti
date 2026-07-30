<!doctype html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Cari Paket Umroh')</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-full bg-stone-50 text-stone-900 antialiased">
    <header class="border-b border-stone-200 bg-white">
        <div class="flex items-baseline gap-3 px-3 py-2">
            <a href="{{ route('search') }}" class="font-semibold tracking-tight">Umroh Sakti</a>
            <p class="text-xs text-stone-500">Bandingkan paket umroh dari berbagai travel</p>
        </div>
    </header>

    <main class="px-3 py-3">@yield('content')</main>

    <footer class="px-3 py-6 text-xs text-stone-500">
        Data dikumpulkan dari postingan publik travel. Selalu konfirmasi ke travel
        bersangkutan sebelum melakukan pembayaran.
    </footer>
</body>
</html>
