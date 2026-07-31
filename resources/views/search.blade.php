@extends('layout')
@section('title', 'Cari Paket Umroh')

@section('content')
{{-- Sudah login: semua status tampil apa adanya, tanpa spanduk — yang sudah
     dibuang lewat × memang hilang dari tabel. Toggle `?all=0` untuk melihat
     persis seperti yang dilihat pengunjung. --}}
@unless ($preview ?? false)
    <p class="mb-4 text-sm text-muted-foreground">Cuma paket published yang tampil.</p>
@endunless

@if ($preview ?? false)
    {{-- Panel pipeline pindah ke /akun: halaman ini khusus buat mencari paket. --}}
    <p class="mb-4 text-sm text-muted-foreground">
        Pratinjau operator. <a href="{{ route('accounts') }}" class="font-medium text-foreground underline underline-offset-4">daftar akun &amp; pipeline</a>
        &middot; <a href="{{ request()->fullUrlWithQuery(['all' => 0]) }}" class="underline underline-offset-4">lihat sebagai pengunjung</a>
    </p>
@endif

@php
    // Label + ikon select facet. Kuncinya = param query di PackageSearchController::FACETS.
    $labelFacet = [
        'city' => ['pin', 'kota'],
        'airline' => ['plane', 'maskapai'],
        'account' => ['at', 'akun'],
        'extension' => ['plus', 'extension'],
        'certainty' => ['help', 'kepastian'],
        'status' => ['tag', 'status'],
    ];
    // Semua yang bukan urutan/pratinjau dihitung sebagai filter aktif.
    $aktif = array_filter(request()->except(['sort', 'all']), fn ($v) => is_scalar($v) && $v !== '');

    // Kontrolnya dipadatkan jadi "pill": satu kotak setinggi 8 berisi ikon + kontrol
    // tanpa border sendiri, jadi labelnya tidak perlu baris sendiri. Bukan x-ui.input/
    // select karena dua-duanya h-9 + border, tiga barisnya bikin bar-nya setinggi kartu.
    $pill = 'inline-flex h-8 items-center gap-1.5 rounded-md border border-input bg-background px-2 text-xs '
        .'shadow-xs focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50';
    $bare = 'min-w-0 cursor-pointer bg-transparent text-xs outline-none placeholder:text-muted-foreground';
    // Tombol aksi per kartu (pratinjau lokal). Semuanya sebaris di bawah gambar,
    // tooltipnya `title` bawaan browser — tidak perlu library.
    $aksi = 'grid size-7 place-items-center rounded-md text-muted-foreground hover:bg-accent hover:text-accent-foreground disabled:opacity-50';
@endphp

{{-- Satu form GET untuk filter + urutan sekaligus. Sticky di bawah header (h-14)
     supaya bisa ganti filter tanpa scroll balik ke atas; `-mx-4 px-4` bikin
     latarnya penuh selebar main yang punya padding 4. --}}
<form method="GET" action="{{ route('search') }}"
      class="sticky top-14 z-30 -mx-4 mb-4 flex flex-wrap items-center gap-2 border-b bg-background/90 px-4 py-2 backdrop-blur">
    @if (request()->has('all'))<input type="hidden" name="all" value="{{ request('all') }}">@endif

    <label class="{{ $pill }} w-full flex-1 sm:w-auto sm:max-w-sm">
        <x-ui.icon name="search" class="text-muted-foreground" />
        <input name="q" value="{{ request('q') }}" placeholder="hotel, pembimbing, kota, maskapai…"
               class="{{ $bare }} w-full cursor-text">
    </label>

    {{-- Urutan digabung ke bar filter: satu select, bukan empat tombol. --}}
    <label class="{{ $pill }}" title="urut">
        <x-ui.icon name="sort" class="text-muted-foreground" />
        <select name="sort" onchange="this.form.submit()" class="{{ $bare }} appearance-none pr-1">
            @foreach (App\Http\Controllers\PackageSearchController::SORTS as $key => $label)
                <option value="{{ $key }}" @selected($sort === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </label>

    {{-- Filter sisanya di popover <details>: tetap satu form, tanpa JS. --}}
    <details class="relative">
        <summary class="{{ $pill }} cursor-pointer select-none gap-1.5 font-medium marker:content-none [&::-webkit-details-marker]:hidden">
            <x-ui.icon name="filter" />
            filter
            @if ($aktif)<x-ui.badge variant="default">{{ count($aktif) }}</x-ui.badge>@endif
        </summary>

        <x-ui.card class="absolute left-0 top-full z-40 mt-2 grid w-[min(34rem,88vw)] gap-3 p-3 sm:grid-cols-2">
            @foreach ($facets as $param => $pilihan)
                {{-- Kolom dengan satu nilai saja tidak perlu select (status saat publik). --}}
                @continue (count($pilihan) < 2)
                <label class="{{ $pill }} w-full">
                    <x-ui.icon :name="$labelFacet[$param][0]" class="text-muted-foreground" />
                    {{-- Daftar panjang (akun ~190, maskapai belasan ejaan) tidak bisa
                         dipindai mata: <input list> + <datalist> itu combobox
                         ketik-untuk-menyaring bawaan browser, tanpa JS. Nilainya tetap
                         harus persis — ketikan bebas yang tidak ada di daftar balik nol
                         hasil, sama seperti memilih opsi yang tidak ada. Daftar pendek
                         (status, kepastian) tetap select: menu 3 opsi tidak perlu dicari. --}}
                    @if ($pilihan->count() > 8)
                        <input name="{{ $param }}" value="{{ request($param) }}" list="facet-{{ $param }}"
                               onchange="this.form.submit()" placeholder="{{ $labelFacet[$param][1] }}: semua ({{ $pilihan->sum() }})"
                               class="{{ $bare }} w-full cursor-text">
                        <datalist id="facet-{{ $param }}">
                            @foreach ($pilihan as $nilai => $jumlah)
                                <option value="{{ $nilai }}" label="{{ $jumlah }}"></option>
                            @endforeach
                        </datalist>
                    @else
                        <select name="{{ $param }}" onchange="this.form.submit()" class="{{ $bare }} w-full appearance-none">
                            <option value="">{{ $labelFacet[$param][1] }}: semua ({{ $pilihan->sum() }})</option>
                            @foreach ($pilihan as $nilai => $jumlah)
                                <option value="{{ $nilai }}" @selected(request($param) === (string) $nilai)>{{ $nilai }} ({{ $jumlah }})</option>
                            @endforeach
                        </select>
                    @endif
                </label>
            @endforeach

            <label class="{{ $pill }} w-full" title="berangkat">
                <x-ui.icon name="calendar" class="text-muted-foreground" />
                <input type="date" name="from" value="{{ request('from') }}" min="{{ config('umroh.min_departure') }}" class="{{ $bare }} w-full">
                <span class="text-muted-foreground">–</span>
                <input type="date" name="to" value="{{ request('to') }}" min="{{ config('umroh.min_departure') }}" class="{{ $bare }} w-full">
            </label>

            <label class="{{ $pill }} w-full" title="durasi (hari)">
                <x-ui.icon name="clock" class="text-muted-foreground" />
                @foreach (['duration_min' => 'min', 'duration_max' => 'maks'] as $param => $label)
                    <select name="{{ $param }}" onchange="this.form.submit()" class="{{ $bare }} w-full appearance-none">
                        <option value="">{{ $label }} hari</option>
                        @foreach ($durations as $hari)
                            <option value="{{ $hari }}" @selected(request($param) == $hari)>{{ $hari }}</option>
                        @endforeach
                    </select>
                @endforeach
            </label>

            <label class="{{ $pill }} w-full" title="harga rupiah, kena ke tier mana saja">
                <x-ui.icon name="money" class="text-muted-foreground" />
                <input type="number" name="min_price" value="{{ request('min_price') }}" step="1000000" min="0" placeholder="min" class="{{ $bare }} w-full cursor-text">
                <span class="text-muted-foreground">–</span>
                <input type="number" name="max_price" value="{{ request('max_price') }}" step="1000000" min="0" placeholder="maks" class="{{ $bare }} w-full cursor-text">
            </label>

            <label class="{{ $pill }} w-full">
                <x-ui.icon name="hotel" class="text-muted-foreground" />
                <input name="hotel" value="{{ request('hotel') }}" placeholder="nama hotel" class="{{ $bare }} w-full cursor-text">
            </label>

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

    <span class="ml-auto text-xs text-muted-foreground"><span data-count>{{ $packages->count() }}</span> paket</span>
</form>

{{-- Maksimal 8 kolom di layar lebar; di bawah itu jumlahnya turun per breakpoint. --}}
<div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-6 min-[1800px]:grid-cols-8">
    @forelse ($packages as $package)
        @php $flyers = $package->flyers(); @endphp   {{-- sudah disaring ke gambar yang memuat detail paket --}}
        {{-- Seluruh kartu membuka lightbox (lihat JS di bawah), bukan cuma judulnya.
             Sengaja tanpa overlay <a> yang menutupi kartu: overlay itu memakan
             gesture geser carousel flyernya. --}}
        <x-ui.card as="article" id="p{{ $package->id }}"
                   class="relative flex cursor-pointer scroll-mt-20 flex-col overflow-hidden transition-shadow hover:shadow-md">
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

            @if ($preview ?? false)
                {{-- Aksi kartu, semuanya di bawah gambar. `data-gone` = barisnya
                     hilang setelah aksi (baca ulang bikin baris baru saat import). --}}
                <div class="flex items-center gap-1 border-b bg-muted/40 px-2 py-1">
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

            <div class="flex flex-1 flex-col gap-1 p-3 text-sm">
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
                    @if ($package->source_account)
                        {{-- Saring ke akun ini saja — facet `akun` yang sama dengan select di atas,
                             jadi klik kedua kalinya melepas filternya. --}}
                        @php($terpilih = request('account') === $package->source_account)
                        <x-ui.button as="a" variant="{{ $terpilih ? 'secondary' : 'ghost' }}" size="sm"
                                     class="h-6 px-1.5"
                                     href="{{ request()->fullUrlWithQuery(['account' => $terpilih ? null : $package->source_account]) }}"
                                     title="{{ $terpilih ? 'Hapus filter akun' : 'Tampilkan paket dari akun ini saja' }}"
                        ><x-ui.icon name="filter" class="!size-3.5" /></x-ui.button>
                    @endif
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
     tidak ada library. Isinya potongan yang sama dengan halaman /paket/{id}.
     `m-auto` wajib: UA stylesheet memusatkannya lewat `margin:auto`, dan preflight
     Tailwind menimpanya jadi `margin:0` — tanpa ini popupnya nempel kiri-atas. --}}
<dialog id="lightbox" class="m-auto w-[min(46rem,92vw)] rounded-xl border bg-card p-0 text-card-foreground shadow-lg backdrop:bg-foreground/50">
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
<script>
const csrf = document.querySelector('meta[name=csrf-token]').content;

// Ganti status = satu PATCH, tanpa reload: kalau halamannya dimuat ulang, filter
// `?status=review` yang sedang dipakai langsung membuang kartu yang baru dipublish
// dari layar — dan yang di sebelahnya ikut bergeser di tengah kerja.
document.addEventListener('change', async (event) => {
    const select = event.target.closest('[data-status]');
    if (!select) return;

    select.disabled = true;
    try {
        const res = await fetch(select.dataset.status, {
            method: 'PATCH',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ status: select.value }),
        });
        if (!res.ok) throw new Error(res.status);
        select.classList.remove('border-destructive');
        select.title = 'tersimpan: ' + select.value;
    } catch (e) {
        select.classList.add('border-destructive');
        select.title = 'gagal simpan: ' + e.message;
    }
    select.disabled = false;
});

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
