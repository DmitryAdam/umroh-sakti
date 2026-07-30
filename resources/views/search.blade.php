@extends('layout')
@section('title', 'Cari Paket Umroh')

@section('content')
{{-- Di lokal semua status tampil apa adanya, tanpa spanduk: yang sudah dibuang
     lewat × memang hilang dari tabel. Toggle `?semua=0` tetap ada kalau perlu. --}}
@unless ($preview ?? false)
    <p class="mb-4 text-sm text-muted-foreground">Cuma paket published yang tampil.</p>
@endunless

@if ($preview ?? false)
    {{-- Panel pipeline pindah ke /akun: halaman ini khusus buat mencari paket. --}}
    <p class="mb-4 text-sm text-muted-foreground">
        Pratinjau lokal. <a href="{{ route('accounts') }}" class="font-medium text-foreground underline underline-offset-4">daftar akun &amp; pipeline</a>
    </p>
@endif

@php
    // Label select facet. Kuncinya = param query di PackageSearchController::FACETS.
    $labelFacet = [
        'city' => 'kota berangkat',
        'airline' => 'maskapai',
        'akun' => 'akun sumber',
        'extension' => 'extension',
        'certainty' => 'kepastian tanggal',
        'status' => 'status',
    ];
    // Semua yang bukan urutan/pratinjau dihitung sebagai filter aktif.
    $aktif = array_filter(request()->except(['sort', 'semua']), fn ($v) => is_scalar($v) && $v !== '');
@endphp

<x-ui.card as="details" class="mb-4 px-4 py-3" :open="(bool) $aktif">
    <summary class="flex cursor-pointer select-none items-center gap-2 text-sm font-medium">
        Filter
        @if ($aktif)<x-ui.badge variant="default">{{ count($aktif) }}</x-ui.badge>@endif
    </summary>

    {{-- Satu form GET untuk semua filter. Pilihan select diambil dari data yang
         benar-benar ada (facets), jadi tidak ada opsi yang hasilnya nol. --}}
    <form method="GET" action="{{ route('search') }}" class="mt-4 grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
        <input type="hidden" name="sort" value="{{ $sort }}">
        @if (request()->has('semua'))<input type="hidden" name="semua" value="{{ request('semua') }}">@endif

        <x-ui.field label="cari" class="sm:col-span-2">
            <x-ui.input name="q" value="{{ request('q') }}" placeholder="hotel, pembimbing, kota, maskapai…" />
        </x-ui.field>

        @foreach ($facets as $param => $pilihan)
            {{-- Kolom dengan satu nilai saja tidak perlu select (status saat publik). --}}
            @continue (count($pilihan) < 2)
            <x-ui.field :label="$labelFacet[$param]">
                <x-ui.select name="{{ $param }}" onchange="this.form.submit()">
                    <option value="">semua ({{ $pilihan->sum() }})</option>
                    @foreach ($pilihan as $nilai => $jumlah)
                        <option value="{{ $nilai }}" @selected(request($param) === (string) $nilai)>{{ $nilai }} ({{ $jumlah }})</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
        @endforeach

        <x-ui.field label="berangkat">
            <span class="flex gap-2">
                <x-ui.input type="date" name="from" value="{{ request('from') }}" min="{{ config('umroh.min_departure') }}" />
                <x-ui.input type="date" name="to" value="{{ request('to') }}" min="{{ config('umroh.min_departure') }}" />
            </span>
        </x-ui.field>

        <x-ui.field label="durasi (hari)">
            <span class="flex gap-2">
                @foreach (['duration_min' => 'min', 'duration_max' => 'maks'] as $param => $label)
                    <x-ui.select name="{{ $param }}" onchange="this.form.submit()">
                        <option value="">{{ $label }}</option>
                        @foreach ($durations as $hari)
                            <option value="{{ $hari }}" @selected(request($param) == $hari)>{{ $hari }}</option>
                        @endforeach
                    </x-ui.select>
                @endforeach
            </span>
        </x-ui.field>

        <x-ui.field label="harga (rupiah, tier mana saja)">
            <span class="flex gap-2">
                <x-ui.input type="number" name="min_price" value="{{ request('min_price') }}" step="1000000" min="0" placeholder="min" />
                <x-ui.input type="number" name="max_price" value="{{ request('max_price') }}" step="1000000" min="0" placeholder="maks" />
            </span>
        </x-ui.field>

        <x-ui.field label="hotel">
            <x-ui.input name="hotel" value="{{ request('hotel') }}" placeholder="nama hotel" />
        </x-ui.field>

        <div class="flex flex-wrap items-center justify-end gap-2 sm:col-span-3 lg:col-span-4">
            @if ($aktif)
                <x-ui.button as="a" variant="ghost" size="sm"
                             href="{{ request()->fullUrlWithQuery(array_map(fn () => null, $aktif)) }}">reset</x-ui.button>
            @endif
            <x-ui.button size="sm">Terapkan</x-ui.button>
        </div>
    </form>
</x-ui.card>

<div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
    <span class="text-muted-foreground"><span data-count>{{ $packages->count() }}</span> paket</span>
    <span class="ml-auto text-xs text-muted-foreground">urut</span>
    @foreach (App\Http\Controllers\PackageSearchController::SORTS as $key => $label)
        {{-- fullUrlWithQuery: filter yang sedang aktif ikut kebawa. --}}
        <x-ui.button as="a" size="sm" :variant="$sort === $key ? 'secondary' : 'ghost'"
                     href="{{ request()->fullUrlWithQuery(['sort' => $key]) }}">{{ $label }}</x-ui.button>
    @endforeach
</div>

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
    @forelse ($packages as $package)
        @php $flyers = $package->flyers(); @endphp   {{-- sudah disaring ke gambar yang memuat detail paket --}}
        {{-- Seluruh kartu membuka lightbox (lihat JS di bawah), bukan cuma judulnya.
             Sengaja tanpa overlay <a> yang menutupi kartu: overlay itu memakan
             gesture geser carousel flyernya. --}}
        <x-ui.card as="article" id="p{{ $package->id }}"
                   class="relative flex cursor-pointer scroll-mt-20 flex-col overflow-hidden p-0 transition-shadow hover:shadow-md">
            @if ($preview ?? false)
                <button type="button" data-delete="{{ route('package.destroy', $package) }}" title="Bukan flyer umroh — buang"
                        class="absolute right-2 top-2 z-10 grid size-7 place-items-center rounded-full bg-foreground/60 text-sm leading-none text-background hover:bg-destructive">&times;</button>
            @endif

            @if ($flyers)
                {{-- Carousel pakai scroll-snap bawaan browser: geser/swipe, tanpa JS. --}}
                <div class="relative bg-muted">
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

            <div class="flex flex-1 flex-col gap-1.5 p-4 text-sm">
                {{-- Lightbox: href-nya tetap halaman detail, jadi tanpa JS / klik-tengah
                     tetap jalan. Yang di-klik biasa dicegat dan diambil lewat fetch. --}}
                <a href="{{ route('package.show', ['package' => $package] + (($preview ?? false) ? ['semua' => 1] : [])) }}"
                   data-detail class="font-semibold leading-snug tracking-tight hover:underline">
                    <span class="font-mono text-[10px] font-normal text-muted-foreground">#{{ $package->id }}</span>
                    {{ $package->departure_city ?? 'Kota ?' }} &middot;
                    {{ $package->duration_days ? $package->duration_days . ' hari' : 'durasi ?' }}
                    @if ($package->extension !== 'none') &middot; +{{ $package->extension }} @endif
                </a>

                <p class="text-xs text-muted-foreground">
                    {{-- Rentang: tanggal pulang dihitung dari durasi, biar tidak dihitung sendiri. --}}
                    {{ $package->dateLabel() ?? 'Tanggal ?' }}
                    @if ($package->date_certainty !== 'exact')({{ $package->date_certainty }})@endif
                    @if ($package->airline) &middot; {{ $package->airline }} @endif
                </p>

                @if ($harga = $package->prices())
                    <dl class="mt-1 grid grid-cols-[auto_1fr] items-baseline gap-x-2">
                        @foreach ($harga as $occupancy => $amount)
                            <dt class="text-xs capitalize text-muted-foreground">{{ $occupancy }}</dt>
                            <dd class="text-right font-medium tabular-nums">
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
                    <p class="text-xs"><span class="text-muted-foreground">{{ $city }}</span> {{ $raw }}</p>
                @endforeach

                @if ($package->guide_name)
                    <p class="text-xs"><span class="text-muted-foreground">Pembimbing</span> {{ $package->guide_name }}</p>
                @endif

                @if ($package->facilities_raw)
                    {{-- Fasilitas apa adanya dari flyer/caption: `facilities` cuma kode
                         yang kenal di FACILITY_CODES, sisanya cuma ada di sini. --}}
                    <p class="truncate text-xs text-muted-foreground" title="{{ $package->facilities_raw }}">{{ $package->facilities_raw }}</p>
                @endif

                <div class="mt-auto flex flex-wrap items-center gap-2 pt-2">
                    <a href="{{ $package->source_permalink }}" rel="nofollow noopener" target="_blank"
                       class="truncate text-xs text-muted-foreground underline underline-offset-4 @if (! $package->source_permalink) pointer-events-none opacity-50 @endif"
                    >&#64;{{ $package->source_account ?? '-' }}</a>
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
            Belum ada paket. Jalankan fetch &rarr; extract &rarr; packages:import.
        </p>
    @endforelse
</div>

{{-- Lightbox detail. <dialog> bawaan browser: Esc & fokusnya sudah ditangani,
     tidak ada library. Isinya potongan yang sama dengan halaman /paket/{id}. --}}
<dialog id="lightbox" class="w-[min(46rem,92vw)] rounded-xl border bg-card p-0 text-card-foreground shadow-lg backdrop:bg-foreground/50">
    <button type="button" data-close aria-label="tutup"
            class="absolute right-3 top-3 grid size-7 place-items-center rounded-md text-muted-foreground hover:bg-accent hover:text-accent-foreground">&times;</button>
    <div data-body class="max-h-[88vh] overflow-y-auto p-6"></div>
</dialog>

<script>
const lightbox = document.getElementById('lightbox');
const lightboxBody = lightbox.querySelector('[data-body]');

document.addEventListener('click', async (event) => {
    // Klik di mana saja dalam kartu = buka detailnya, kecuali kalau yang diklik
    // memang punya aksi sendiri (tombol ×, link ke post asli).
    const card = event.target.closest('article[id^=p]');
    const link = event.target.closest('a[data-detail]')
        || (card && !event.target.closest('a, button') ? card.querySelector('a[data-detail]') : null);

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
<script>
const csrf = document.querySelector('meta[name=csrf-token]').content;

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-delete]');
    if (!button) return;

    const card = button.closest('article');
    button.disabled = true;
    card.style.opacity = 0.4;

    try {
        const res = await fetch(button.dataset.delete, {
            method: 'DELETE',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
        });
        if (!res.ok) throw new Error(res.status);
        card.remove();
        const count = document.querySelector('[data-count]');
        count.textContent = document.querySelectorAll('article[id^=p]').length;
    } catch (e) {
        button.disabled = false;
        card.style.opacity = 1;
        button.title = 'gagal: ' + e.message;
        button.classList.add('bg-destructive');
    }
});
</script>
@endif
@endsection
