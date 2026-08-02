@extends('layout')
@section('title', 'Cari Paket Umroh')

@section('content')
{{-- Tanpa spanduk: peran + saklar pratinjau ada di menu (layout.blade), dan
     halaman ini isinya hasil pencarian. Yang membedakan pratinjau dari tampilan
     pengunjung sudah kelihatan sendiri — kartu non-published dan tombol aksinya. --}}
@php
    // Label + ikon select facet. Kuncinya = param query di PackageSearchController::FACETS.
    // Labelnya kalimat penuh: di popover tiap kontrol punya caption sendiri, jadi
    // "kota" saja tidak cukup menjelaskan kota berangkat vs kota tujuan.
    $labelFacet = [
        'city' => ['pin', 'Kota keberangkatan'],
        'airline' => ['plane', 'Maskapai'],
        'account' => ['at', 'Akun travel'],
        'extension' => ['plus', 'Extension'],
        'certainty' => ['help', 'Kepastian tanggal'],
        'status' => ['tag', 'Status publikasi'],
    ];
    // Semua yang bukan urutan/pratinjau dihitung sebagai filter aktif.
    $aktif = array_filter(request()->except(['sort', 'all']), fn ($v) => is_scalar($v) && $v !== '');

    // Kontrolnya dipadatkan jadi "pill": satu kotak berisi ikon + kontrol tanpa
    // border sendiri. Bukan x-ui.input karena captionnya sudah di atas kontrol.
    $pill = 'inline-flex h-9 items-center gap-1.5 rounded-xl border border-input bg-background px-2.5 text-xs '
        .'shadow-xs focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50';
    $bare = 'min-w-0 cursor-pointer bg-transparent text-xs outline-none placeholder:text-muted-foreground';
    // Caption tiap filter: ikon + teks di atas kontrolnya.
    $caption = 'flex items-center gap-1.5 text-xs font-medium text-foreground';
@endphp

{{-- Bar cari duduk DI DALAM header (`@section('bar')`, dirender layout di baris
     yang sama dengan logo & burger). Dulu ia baris sticky sendiri di bawah header:
     dua baris menempel yang sama-sama sticky memakan sepertiga layar sebelum kartu
     pertama kelihatan. Sticky-nya sekarang ikut header, jadi form ini tidak perlu
     posisi sendiri — cukup mengisi lebar yang disediakan. --}}
@section('bar')
<form method="GET" action="{{ route('search') }}" class="w-full">
    @if (request()->has('all'))<input type="hidden" name="all" value="{{ request('all') }}">@endif

    <div class="flex flex-wrap items-center gap-2">
        <label class="flex h-10 w-full min-w-0 flex-1 items-center gap-2 rounded-xl border border-input bg-background px-3 shadow-xs focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50 sm:w-auto">
            <x-ui.icon name="search" class="!size-4 text-muted-foreground" />
            <input name="q" value="{{ request('q') }}" placeholder="hotel, pembimbing, kota, maskapai…"
                   class="h-full w-full min-w-0 bg-transparent text-sm outline-none placeholder:text-muted-foreground">
        </label>

        {{-- Urutan digabung ke bar filter: satu combo, bukan empat tombol. --}}
        <x-ui.combo name="sort" icon="sort" size="lg" submit placeholder="urutkan"
                    :value="$sort" :options="App\Http\Controllers\PackageSearchController::SORTS" />

        {{-- Filter sisanya di popover <details>: tetap satu form.

             `relative` cuma dari sm ke atas. Di HP tombol filter duduk di tengah
             baris, jadi `right-0` yang diukur dari tombolnya menaruh kotak 88vw
             itu setengah keluar layar (kepotong `overflow-x: clip`). Tanpa
             `relative`, jangkarnya jadi <header> yang sticky + selebar viewport,
             dan `top-full` jatuh di bawah seluruh header — bukan di bawah
             tombolnya, yang di HP memang lebih benar. --}}
        <details class="sm:relative">
            <summary class="flex h-10 cursor-pointer select-none items-center gap-1.5 rounded-xl border border-input bg-background px-3 text-sm font-medium shadow-xs hover:bg-accent/40 marker:content-none [&::-webkit-details-marker]:hidden">
                <x-ui.icon name="filter" />
                filter
                @if ($aktif)<x-ui.badge variant="default">{{ count($aktif) }}</x-ui.badge>@endif
            </summary>

            <x-ui.card class="absolute right-4 top-full z-40 mt-2 grid w-[min(40rem,calc(100vw-2rem))] gap-4 p-4 sm:right-0 sm:grid-cols-2">
                @foreach ($facets as $param => $pilihan)
                    {{-- Kolom dengan satu nilai saja tidak perlu select (status saat publik). --}}
                    @continue (count($pilihan) < 2)
                    <div class="grid gap-1.5">
                        <span class="{{ $caption }}"><x-ui.icon :name="$labelFacet[$param][0]" class="text-primary" /> {{ $labelFacet[$param][1] }}</span>
                        {{-- Jumlah per pilihan ikut di labelnya; daftar panjang (akun ~190,
                             maskapai belasan ejaan) dapat kotak cari dari x-ui.combo. --}}
                        <x-ui.combo :name="$param" submit :value="request($param)"
                                    placeholder="semua ({{ $pilihan->sum() }})"
                                    :options="$pilihan->mapWithKeys(fn ($jumlah, $nilai) => [$nilai => $nilai.' ('.$jumlah.')'])" />
                    </div>
                @endforeach

                <div class="grid gap-1.5">
                    <span class="{{ $caption }}"><x-ui.icon name="calendar" class="text-primary" /> Tanggal keberangkatan</span>
                    <label class="{{ $pill }} w-full">
                        <input type="date" name="from" value="{{ request('from') }}" min="{{ config('umroh.min_departure') }}" class="{{ $bare }} w-full">
                        <span class="text-muted-foreground">–</span>
                        <input type="date" name="to" value="{{ request('to') }}" min="{{ config('umroh.min_departure') }}" class="{{ $bare }} w-full">
                    </label>
                </div>

                <div class="grid gap-1.5">
                    <span class="{{ $caption }}"><x-ui.icon name="clock" class="text-primary" /> Durasi perjalanan</span>
                    <div class="flex items-center gap-2">
                        @foreach (['duration_min' => 'min', 'duration_max' => 'maks'] as $param => $label)
                            <x-ui.combo :name="$param" submit :value="request($param)" placeholder="{{ $label }} hari"
                                        class="flex-1"
                                        :options="collect($durations)->mapWithKeys(fn ($hari) => [$hari => $hari.' hari'])" />
                        @endforeach
                    </div>
                </div>

                <div class="grid gap-1.5">
                    <span class="{{ $caption }}" title="kena ke tier mana saja"><x-ui.icon name="money" class="text-primary" /> Harga (rupiah)</span>
                    <label class="{{ $pill }} w-full">
                        <input type="number" name="min_price" value="{{ request('min_price') }}" step="1000000" min="0" placeholder="min" class="{{ $bare }} w-full cursor-text">
                        <span class="text-muted-foreground">–</span>
                        <input type="number" name="max_price" value="{{ request('max_price') }}" step="1000000" min="0" placeholder="maks" class="{{ $bare }} w-full cursor-text">
                    </label>
                </div>

                <div class="grid gap-1.5">
                    <span class="{{ $caption }}"><x-ui.icon name="hotel" class="text-primary" /> Nama hotel</span>
                    <label class="{{ $pill }} w-full">
                        <input name="hotel" value="{{ request('hotel') }}" placeholder="Makkah atau Madinah" class="{{ $bare }} w-full cursor-text">
                    </label>
                </div>

                <div class="flex items-center justify-end gap-2 sm:col-span-2">
                    @if ($aktif)
                        <x-ui.button as="a" variant="ghost" size="sm"
                                     href="{{ request()->fullUrlWithQuery(array_map(fn () => null, $aktif)) }}">
                            <x-ui.icon name="x" /> reset
                        </x-ui.button>
                    @endif
                    {{-- Tutup tanpa menerapkan. <details> tidak punya tombol tutup
                         selain summary-nya, dan di HP summary itu ada di atas kotak
                         yang setinggi layar. `type=button` wajib — default submit. --}}
                    <x-ui.button type="button" variant="outline" size="sm"
                                 onclick="this.closest('details').open = false">tutup</x-ui.button>
                    <x-ui.button size="sm">Terapkan</x-ui.button>
                </div>
            </x-ui.card>
        </details>

        {{-- Reset di bar, bukan cuma di dalam popover: kata cari + facet bisa aktif
             tanpa popover pernah dibuka, jadi jalan keluarnya harus kelihatan dari
             luar. Link biasa (query param yang aktif di-null-kan), bukan tombol —
             GET tanpa JS, dan bisa dibuka di tab baru. --}}
        @if ($aktif)
            <x-ui.button as="a" variant="outline"
                         class="h-10 rounded-xl px-3 text-sm"
                         href="{{ request()->fullUrlWithQuery(array_map(fn () => null, $aktif)) }}"
                         title="Hapus semua filter &amp; kata cari"
            ><x-ui.icon name="x" /> reset</x-ui.button>
        @endif

        {{-- Tampilan & jumlah kolom: preferensi tampilan, bukan filter — tidak
             ikut query string, disimpan di localStorage (lihat JS di bawah). --}}
        <div class="flex h-10 items-center gap-1 rounded-xl border border-input bg-background px-1 shadow-xs">
            @foreach (['grid' => ['grid', 'tampilan kartu'], 'list' => ['list', 'tampilan daftar']] as $mode => $ikon)
                <button type="button" data-view-set="{{ $mode }}" title="{{ $ikon[1] }}"
                        class="grid size-8 cursor-pointer place-items-center rounded-lg text-muted-foreground hover:bg-accent hover:text-accent-foreground aria-pressed:bg-primary aria-pressed:text-primary-foreground">
                    <x-ui.icon :name="$ikon[0]" class="!size-4" />
                </button>
            @endforeach
        </div>

        <x-ui.combo store="cols" icon="columns" size="lg" placeholder="kolom: auto"
                    class="hidden lg:block"
                    :options="collect(range(1, 8))->mapWithKeys(fn ($n) => [$n => $n.' kolom'])" />
    </div>
</form>
@endsection

{{-- Jumlah hasil pindah keluar bar: di header ia berebut tempat dengan kontrol
     yang benar-benar diklik. `total()`, bukan `count()`: yang kedua cuma sebanyak
     halaman ini. Angka ini tetap dikurangi JS saat kartu dibuang di pratinjau. --}}
<p class="mb-3 text-xs text-muted-foreground"><span data-count>{{ $packages->total() }}</span> paket</p>

{{-- Maksimal 8 kolom di layar lebar; di bawah itu jumlahnya turun per breakpoint.
     Pilihan manual dipasang inline dari JS, jadi menimpa semua breakpoint. --}}
<div id="grid" data-view="grid" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-6 min-[1800px]:grid-cols-8">
    @include('partials.cards')
</div>

{{-- Lightbox detail. <dialog> bawaan browser: Esc & fokusnya sudah ditangani,
     tidak ada library. Isinya potongan yang sama dengan halaman /paket/{id}.
     `m-auto` wajib: UA stylesheet memusatkannya lewat `margin:auto`, dan preflight
     Tailwind menimpanya jadi `margin:0` — tanpa ini popupnya nempel kiri-atas. --}}
<dialog id="lightbox" class="m-auto w-[min(46rem,92vw)] rounded-xl border bg-card p-0 text-card-foreground shadow-lg backdrop:bg-foreground/50">
    <button type="button" data-close aria-label="tutup"
            class="absolute right-3 top-3 grid size-7 place-items-center rounded-md text-muted-foreground hover:bg-accent hover:text-accent-foreground">&times;</button>
    <div data-body class="max-h-[88vh] overflow-y-auto p-6"></div>
</dialog>

<script>
// Tampilan kartu/daftar + jumlah kolom. Preferensi tampilan, bukan filter: tidak
// masuk query string (link yang dibagikan tidak boleh memaksa tata letak orang
// lain), disimpan di localStorage. Kolomnya dipasang inline supaya menimpa kelas
// breakpoint; tampilan daftar mengabaikan pilihan kolom (selalu satu).
const grid = document.getElementById('grid');
const kolomCombo = document.querySelector('[data-store=cols]');
// Default ikut lebar layar, bukan 'grid' untuk semua: di HP gridnya cuma satu
// kolom, jadi tiap kartu memakan hampir satu layar penuh untuk satu paket dan
// membandingkan berarti menggulir jauh. Daftar memuat 3-4 paket per layar.
// Cuma default — pilihan yang pernah ditekan tetap menang, di lebar berapa pun.
let tampilan = localStorage.getItem('view') ?? (window.matchMedia('(width < 40rem)').matches ? 'list' : 'grid');
let kolom = localStorage.getItem('cols') ?? '';

function terapkanTampilan() {
    grid.dataset.view = tampilan;
    // Pilihan kolom berlaku di dua tampilan. Auto: kartu ikut kelas breakpoint
    // (kelas dilepas dengan mengosongkan inline style), daftar pakai auto-fill
    // 24rem — baris daftar butuh lebar minimum, bukan 8 kolom seperti kartu.
    //
    // `min(24rem, 100%)`, bukan `24rem` telanjang: layar HP cuma 343px isi, jadi
    // lebar minimum 384px memaksa gridnya lebih lebar dari halamannya dan sisi
    // kanan kartunya kepotong. minmax menolak track yang lebih kecil dari min-nya,
    // dan `100%` di situ = lebar kolom yang tersedia.
    grid.style.gridTemplateColumns = kolom ? `repeat(${kolom}, minmax(0, 1fr))`
        : (tampilan === 'list' ? 'repeat(auto-fill, minmax(min(24rem, 100%), 1fr))' : '');
    document.querySelectorAll('[data-view-set]').forEach((b) =>
        b.setAttribute('aria-pressed', b.dataset.viewSet === tampilan));
}

document.addEventListener('click', (event) => {
    const tombol = event.target.closest('[data-view-set]');
    if (!tombol) return;
    tampilan = tombol.dataset.viewSet;
    localStorage.setItem('view', tampilan);
    terapkanTampilan();
});

kolomCombo.addEventListener('change', () => {
    kolom = kolomCombo.value;
    localStorage.setItem('cols', kolom);
    terapkanTampilan();
});

comboPilih(kolomCombo, kolom);   // label combo ikut nilai yang tersimpan
terapkanTampilan();

// Gulir tak berujung. Halaman berikutnya diambil lewat fetch dan kartunya
// ditempel ke grid yang sama — `index()` membalas partial kartu saja kalau
// request-nya ajax, jadi tidak ada markup kedua yang bisa menyimpang.
//
// Kenapa bukan render semua sekaligus seperti dulu: tiap kartu memanggil
// `flyers()` (baca disk) dan memuat gambarnya, jadi 600 paket = 600 kali itu
// sebelum satu piksel tampil. Yang dilihat orang cuma layar pertama.
{
    let sibuk = false;
    const muat = async () => {
        const tombol = document.querySelector('[data-more]');
        if (!tombol || sibuk) return;
        sibuk = true;
        tombol.textContent = 'memuat…';
        try {
            const res = await fetch(tombol.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!res.ok) throw new Error(res.status);
            grid.insertAdjacentHTML('beforeend', await res.text());
            // Tombolnya ikut di balasan (atau tidak, kalau itu halaman terakhir),
            // jadi yang lama dibuang dan sentinelnya ikut hilang sendiri.
            tombol.closest('div').remove();
        } catch (e) {
            tombol.textContent = 'gagal memuat, coba lagi';
        }
        sibuk = false;
        pantau();
    };

    // rootMargin: mulai memuat sebelum sentinelnya benar-benar kelihatan.
    const observer = new IntersectionObserver(
        (entries) => entries.some((e) => e.isIntersecting) && muat(),
        { rootMargin: '600px' },
    );
    const pantau = () => {
        const tombol = document.querySelector('[data-more]');
        if (tombol) observer.observe(tombol);
    };
    pantau();

    document.addEventListener('click', (event) => {
        if (!event.target.closest('[data-more]')) return;
        event.preventDefault();
        muat();
    });
}

const lightbox = document.getElementById('lightbox');
const lightboxBody = lightbox.querySelector('[data-body]');

document.addEventListener('click', async (event) => {
    // Klik di mana saja dalam kartu = buka detailnya, kecuali kalau yang diklik
    // memang punya aksi sendiri (tombol ×, link ke post asli).
    const card = event.target.closest('article[id^=p]');
    const link = event.target.closest('a[data-detail]')
        || (card && !event.target.closest('a, button, select') ? card.querySelector('a[data-detail]') : null);

    if (link && !event.metaKey && !event.ctrlKey && event.button === 0) {
        event.preventDefault();
        lightboxBody.innerHTML = '<p class="text-sm text-muted-foreground">memuat…</p>';
        lightbox.showModal();
        try {
            const res = await fetch(link.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!res.ok) throw new Error(res.status);
            lightboxBody.innerHTML = await res.text();
        } catch (e) {
            lightboxBody.innerHTML = '<p class="text-sm text-destructive">gagal memuat: ' + e.message + '</p>';
        }
        return;
    }
    // Klik di luar kotak = di backdrop, karena isinya dibungkus [data-body].
    if (event.target.closest('[data-close]') || event.target === lightbox) lightbox.close();
});
</script>

@if ($preview ?? false)
{{-- Select status: satu partial, dipakai juga di kolom status /posts. --}}
@include('partials.status-patch')
<script>
const csrf = document.querySelector('meta[name=csrf-token]').content;

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-delete], [data-post]');
    if (!button) return;

    const card = button.closest('article');
    button.disabled = true;
    card.style.opacity = 0.4;

    try {
        const res = await fetch(button.dataset.delete ?? button.dataset.post, {
            method: button.dataset.delete ? 'DELETE' : 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
        });
        const body = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(body.error ?? res.status);

        // Barisnya hilang: dibuang, atau dibaca ulang (baris barunya lahir saat import).
        if (button.dataset.delete !== undefined || button.dataset.gone !== undefined) {
            card.remove();
            // Dikurangi satu, bukan dihitung ulang dari DOM: angkanya sekarang
            // total seluruh hasil, sementara yang di DOM cuma halaman yang sudah
            // digulir.
            const jumlah = document.querySelector('[data-count]');
            jumlah.textContent = Math.max(0, +jumlah.textContent - 1);
            return;
        }

        card.style.opacity = 1;
        button.title = 'sudah masuk antrian — pastikan `php artisan queue:work` jalan';
        button.classList.add('text-primary');
    } catch (e) {
        button.disabled = false;
        card.style.opacity = 1;
        button.title = 'gagal: ' + e.message;
        button.classList.add('text-destructive');
    }
});
</script>
@endif
@endsection
