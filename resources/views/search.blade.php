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
    // Tombol aksi per kartu (pratinjau lokal). Semuanya sebaris di bawah gambar,
    // tooltipnya `title` bawaan browser — tidak perlu library.
    $aksi = 'grid size-7 place-items-center rounded-md text-muted-foreground hover:bg-accent hover:text-accent-foreground disabled:opacity-50';
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

        {{-- Filter sisanya di popover <details>: tetap satu form. --}}
        <details class="relative">
            <summary class="flex h-10 cursor-pointer select-none items-center gap-1.5 rounded-xl border border-input bg-background px-3 text-sm font-medium shadow-xs hover:bg-accent/40 marker:content-none [&::-webkit-details-marker]:hidden">
                <x-ui.icon name="filter" />
                filter
                @if ($aktif)<x-ui.badge variant="default">{{ count($aktif) }}</x-ui.badge>@endif
            </summary>

            <x-ui.card class="absolute right-0 top-full z-40 mt-2 grid w-[min(40rem,88vw)] gap-4 p-4 sm:grid-cols-2">
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
     yang benar-benar diklik. --}}
<p class="mb-3 text-xs text-muted-foreground"><span data-count>{{ $packages->count() }}</span> paket</p>

{{-- Maksimal 8 kolom di layar lebar; di bawah itu jumlahnya turun per breakpoint.
     Pilihan manual dipasang inline dari JS, jadi menimpa semua breakpoint. --}}
<div id="grid" data-view="grid" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-6 min-[1800px]:grid-cols-8">
    @forelse ($packages as $package)
        @php $flyers = $package->flyers(); @endphp   {{-- sudah disaring ke gambar yang memuat detail paket --}}
        {{-- Seluruh kartu membuka lightbox (lihat JS di bawah), bukan cuma judulnya.
             Sengaja tanpa overlay <a> yang menutupi kartu: overlay itu memakan
             gesture geser carousel flyernya. --}}
        <x-ui.card as="article" id="p{{ $package->id }}"
                   class="relative flex cursor-pointer scroll-mt-20 flex-col overflow-hidden transition-shadow hover:shadow-md">
            @if ($flyers)
                {{-- Carousel pakai scroll-snap bawaan browser: geser/swipe, tanpa JS.
                     `data-flyer` dipakai CSS tampilan daftar buat menaruhnya di kolom kiri. --}}
                <div data-flyer class="relative bg-muted">
                    <div class="flex snap-x snap-mandatory overflow-x-auto">
                        @foreach ($flyers as $url)
                            <img src="{{ $url }}" alt="Flyer dari &#64;{{ $package->source_account }}" loading="lazy"
                                 class="aspect-[4/5] w-full shrink-0 snap-center object-contain">
                        @endforeach
                    </div>
                    @if (count($flyers) > 1)
                        <span class="pointer-events-none absolute bottom-2 right-2 rounded-md bg-foreground/60 px-1.5 py-0.5 text-[10px] text-background">{{ count($flyers) }}</span>
                    @endif
                </div>
            @endif

            @if ($preview ?? false)
                {{-- Aksi kartu, semuanya di bawah gambar. `data-gone` = barisnya
                     hilang setelah aksi (baca ulang bikin baris baru saat import).
                     `data-aksi`/`data-isi` dipakai CSS tampilan daftar: di sana yang
                     duduk di samping gambar harus teksnya, bukan bar tombol ini. --}}
                <div data-aksi class="flex items-center gap-1 border-b bg-muted/40 px-2 py-1">
                    <button type="button" data-post="{{ route('package.reextract', $package) }}" data-gone
                            title="Baca ulang pakai AI — gambarnya dikirim lagi ke vision, paketnya disusun & diklasifikasi ulang"
                            class="{{ $aksi }}"><x-ui.icon name="sparkles" /></button>
                    <button type="button" data-post="{{ route('package.refetch', $package) }}" data-gone
                            title="Segarkan — postingannya di-download ulang dari Instagram lalu dibaca ulang, paketnya disusun dari nol"
                            class="{{ $aksi }}"><x-ui.icon name="refresh" /></button>
                    {{-- Status publikasi. Perubahannya langsung disimpan (PATCH), tanpa
                         tombol simpan: satu kolom, satu pilihan tertutup. --}}
                    <select data-status="{{ route('package.status', $package) }}"
                            title="Status publikasi — cuma `published` yang tampil ke pengunjung"
                            class="ml-1 h-7 cursor-pointer rounded-md border border-input bg-background px-1.5 text-xs outline-none focus:border-ring">
                        @foreach (App\Models\Package::STATUSES as $status)
                            <option value="{{ $status }}" @selected($package->status === $status)>{{ $status }}</option>
                        @endforeach
                    </select>
                    <button type="button" data-delete="{{ route('package.destroy', $package) }}"
                            title="Bukan flyer umroh — buang paketnya & kecualikan postnya dari scrap berikutnya"
                            class="{{ $aksi }} ml-auto hover:bg-destructive hover:text-background"><x-ui.icon name="x" /></button>
                </div>
            @endif

            <div data-isi class="flex flex-1 flex-col gap-1 p-3 text-sm">
                {{-- Lightbox: href-nya tetap halaman detail, jadi tanpa JS / klik-tengah
                     tetap jalan. Yang di-klik biasa dicegat dan diambil lewat fetch. --}}
                <a href="{{ route('package.show', ['package' => $package] + (($preview ?? false) ? ['all' => 1] : [])) }}"
                   data-detail class="font-semibold leading-snug tracking-tight hover:underline">
                    <span class="font-mono text-[10px] font-normal text-muted-foreground">#{{ $package->id }}</span>
                    {{ $package->departure_city ?? 'Kota ?' }} &middot;
                    {{ $package->duration_days ? $package->duration_days . ' hari' : 'durasi ?' }}
                    @if ($package->extension !== 'none') &middot; +{{ $package->extension }} @endif
                </a>

                <p class="flex items-center gap-1 text-xs text-muted-foreground">
                    {{-- Rentang: tanggal pulang dihitung dari durasi, biar tidak dihitung sendiri. --}}
                    <x-ui.icon name="calendar" />
                    <span class="truncate">{{ $package->dateLabel() ?? 'Tanggal ?' }}
                        @if ($package->date_certainty !== 'exact')({{ $package->date_certainty }})@endif</span>
                </p>
                @if ($package->airline)
                    <p class="flex items-center gap-1 text-xs text-muted-foreground">
                        <x-ui.icon name="plane" /><span class="truncate">{{ $package->airline }}</span>
                    </p>
                @endif

                @if ($harga = $package->prices())
                    <dl class="mt-1 grid grid-cols-[auto_1fr] items-baseline gap-x-2">
                        @foreach ($harga as $occupancy => $amount)
                            <dt class="text-xs capitalize text-muted-foreground">{{ $occupancy }}</dt>
                            <dd class="text-right font-medium tabular-nums text-accent2">
                                {{ number_format($amount / 1000000, 1, ',', '.') }} jt
                                @if ($package->price_starting_from)<span class="text-[10px] font-normal text-muted-foreground">mulai</span>@endif
                            </dd>
                        @endforeach
                    </dl>
                @endif

                @if ($package->convertedFromUsd())
                    <p class="text-[10px] text-muted-foreground">konversi dari USD, kurs {{ number_format((int) config('umroh.usd_rate'), 0, ',', '.') }}</p>
                @endif

                @php $stays = array_filter(['Makkah' => $package->hotel_makkah, 'Madinah' => $package->hotel_madinah]); @endphp
                @foreach ($stays as $city => $raw)
                    <p class="flex items-center gap-1 text-xs" title="{{ $city }}: {{ $raw }}">
                        <x-ui.icon name="hotel" class="text-muted-foreground" />
                        <span class="truncate"><span class="text-muted-foreground">{{ Str::substr($city, 0, 3) }}</span> {{ $raw }}</span>
                    </p>
                @endforeach

                @if ($package->guide_name)
                    <p class="flex items-center gap-1 text-xs">
                        <x-ui.icon name="user" class="text-muted-foreground" /><span class="truncate">{{ $package->guide_name }}</span>
                    </p>
                @endif

                @if ($package->facilities_raw)
                    {{-- Fasilitas apa adanya dari flyer/caption: `facilities` cuma kode
                         yang kenal di FACILITY_CODES, sisanya cuma ada di sini. --}}
                    <p class="truncate text-xs text-muted-foreground" title="{{ $package->facilities_raw }}">{{ $package->facilities_raw }}</p>
                @endif

                <div class="mt-auto flex flex-wrap items-center gap-2 pt-2">
                    <a href="{{ $package->source_permalink }}" rel="nofollow noopener" target="_blank"
                       class="flex min-w-0 items-center gap-1 text-xs text-muted-foreground underline underline-offset-4 @if (! $package->source_permalink) pointer-events-none opacity-50 @endif"
                    ><x-ui.icon name="at" /><span class="truncate">{{ $package->source_account ?? '-' }}</span></a>
                    {{-- Tombol "saring ke akun ini" dibuang: facet `Akun travel` di
                         popover filter sudah bisa dicari, dan tombol per kartu bikin
                         baris akun ramai tanpa menambah jalan yang belum ada. --}}
                    @if ($package->reposts)
                        <x-ui.badge variant="outline">+{{ count($package->reposts) }} repost</x-ui.badge>
                    @endif
                </div>

                @include('partials.warnings')

                {{-- "catatan & jejak" (form penilaian manusia) sementara dilepas dari
                     kartu. Endpoint POST /paket/{id}/feedback masih ada. --}}
            </div>
        </x-ui.card>
    @empty
        <p class="col-span-full rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
            Tidak ada hasil
        </p>
    @endforelse
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
let tampilan = localStorage.getItem('view') ?? 'grid';
let kolom = localStorage.getItem('cols') ?? '';

function terapkanTampilan() {
    grid.dataset.view = tampilan;
    // Pilihan kolom berlaku di dua tampilan. Auto: kartu ikut kelas breakpoint
    // (kelas dilepas dengan mengosongkan inline style), daftar pakai auto-fill
    // 24rem — baris daftar butuh lebar minimum, bukan 8 kolom seperti kartu.
    grid.style.gridTemplateColumns = kolom ? `repeat(${kolom}, minmax(0, 1fr))`
        : (tampilan === 'list' ? 'repeat(auto-fill, minmax(24rem, 1fr))' : '');
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
            document.querySelector('[data-count]').textContent = document.querySelectorAll('article[id^=p]').length;
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
