@extends('layout')
@section('title', 'Cari Paket Umroh')

@section('content')
@if ($preview ?? false)
    <div class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-1 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900">
        <strong>Pratinjau lokal.</strong>
        <span>Termasuk draft/review yang belum lolos pemeriksaan manusia.</span>
        <span class="text-amber-700">Tombol &times; = bukan flyer umroh, langsung dibuang ke storage/trash.</span>
        <a href="{{ route('search', ['semua' => 0]) }}" class="underline">published saja</a>
    </div>
@else
    <p class="mb-3 text-xs text-stone-500">Cuma paket published yang tampil.</p>
@endif

@if ($preview ?? false)
    {{-- Panel pipeline: tombolnya cuma melempar job, `php artisan queue:work` yang kerja. --}}
    <div class="mb-3 rounded border border-stone-200 bg-white px-3 py-2 text-xs">
        <div class="flex flex-wrap items-center gap-3">
            <button type="button" data-pipeline
                    class="rounded bg-stone-900 px-2 py-1 text-xs text-white disabled:opacity-40">Jalankan pipeline</button>
            <div class="h-1.5 w-40 overflow-hidden rounded bg-stone-200">
                <div data-bar class="h-full w-0 bg-stone-900 transition-all"></div>
            </div>
            <span data-progress class="text-stone-500">memuat…</span>
            <a href="{{ route('search') }}" data-refresh class="ml-auto hidden text-stone-900 underline">muat ulang</a>
            <a href="{{ route('accounts') }}" class="text-stone-500 underline">daftar akun</a>
        </div>

        {{-- Satu baris per antrian: yang sedang dikerjakan masing-masing worker. --}}
        <div data-now class="mt-2 grid gap-1 sm:grid-cols-3"></div>

        {{-- Jejak detail, langsung dari stdout probe.php. --}}
        <details class="mt-2 border-t border-stone-100 pt-2" data-logbox>
            <summary class="cursor-pointer text-[11px] text-stone-500">jejak detail</summary>
            <pre data-log class="mt-1 max-h-64 overflow-auto whitespace-pre-wrap break-all rounded bg-stone-50 p-2 font-mono text-[10px] leading-tight text-stone-600"></pre>
        </details>
    </div>
@endif

<p class="mb-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-stone-600">
    <span><span data-count>{{ $packages->count() }}</span> paket</span>
    <span class="text-stone-400">urut</span>
    @foreach (App\Http\Controllers\PackageSearchController::SORTS as $key => $label)
        {{-- fullUrlWithQuery: filter yang sedang aktif ikut kebawa. --}}
        <a href="{{ request()->fullUrlWithQuery(['sort' => $key]) }}"
           @class(['font-medium text-stone-900' => $sort === $key, 'text-stone-500 underline' => $sort !== $key])>{{ $label }}</a>
    @endforeach

    {{-- Rentang keberangkatan. `type=date` bawaan browser, tanpa JS & tanpa library. --}}
    <span class="text-stone-400">berangkat</span>
    <input type="date" name="from" form="rentang" value="{{ request('from') }}" min="{{ config('umroh.min_departure') }}"
           class="rounded border-stone-300 px-1 py-0.5 text-xs">
    <span class="text-stone-400">s/d</span>
    <input type="date" name="to" form="rentang" value="{{ request('to') }}" min="{{ config('umroh.min_departure') }}"
           class="rounded border-stone-300 px-1 py-0.5 text-xs">
    <button form="rentang" class="rounded bg-stone-900 px-2 py-0.5 text-xs text-white">terapkan</button>
    @if (request('from') || request('to'))
        <a href="{{ request()->fullUrlWithQuery(['from' => null, 'to' => null]) }}" class="text-stone-500 underline">hapus</a>
    @endif
</p>

{{-- Filter lain (kota, maskapai, sort, pratinjau) ikut kebawa saat rentangnya diganti. --}}
<form id="rentang" method="GET" action="{{ route('search') }}">
    @foreach (array_filter(request()->except(['from', 'to']), 'is_scalar') as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach
</form>

<div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
    @forelse ($packages as $package)
        @php $flyers = $package->flyers(); @endphp   {{-- sudah disaring ke gambar yang memuat detail paket --}}
        <article id="p{{ $package->id }}" class="relative flex scroll-mt-4 flex-col overflow-hidden rounded border border-stone-200 bg-white text-xs">
            @if ($preview ?? false)
                <button type="button" data-delete="{{ route('package.destroy', $package) }}" title="Bukan flyer umroh — buang"
                        class="absolute right-1 top-1 z-10 grid h-6 w-6 place-items-center rounded-full bg-black/60 text-sm leading-none text-white hover:bg-red-600">&times;</button>
            @endif

            @if ($flyers)
                {{-- Carousel pakai scroll-snap bawaan browser: geser/swipe, tanpa JS. --}}
                <div class="relative bg-stone-100">
                    <div class="flex snap-x snap-mandatory overflow-x-auto">
                        @foreach ($flyers as $url)
                            <img src="{{ $url }}" alt="Flyer dari &#64;{{ $package->source_account }}" loading="lazy"
                                 class="aspect-[4/5] w-full shrink-0 snap-center object-contain">
                        @endforeach
                    </div>
                    @if (count($flyers) > 1)
                        <span class="pointer-events-none absolute bottom-1 right-1 rounded bg-black/60 px-1 text-[10px] text-white">{{ count($flyers) }}</span>
                    @endif
                </div>
            @endif

            <div class="flex flex-1 flex-col gap-0.5 p-2 leading-snug">
                <a href="{{ route('package.show', ['package' => $package] + (($preview ?? false) ? ['semua' => 1] : [])) }}"
                   class="font-medium hover:underline">
                    <span class="font-mono text-[10px] text-stone-400">#{{ $package->id }}</span>
                    {{ $package->departure_city ?? 'Kota ?' }} &middot;
                    {{ $package->duration_days ? $package->duration_days . 'h' : 'durasi ?' }}
                    @if ($package->extension !== 'none') &middot; +{{ $package->extension }} @endif
                </a>

                <p class="text-stone-600">
                    {{ $package->departure_date?->translatedFormat('d M Y') ?? 'Tanggal ?' }}
                    @if ($package->date_certainty !== 'exact')<span class="text-stone-400">({{ $package->date_certainty }})</span>@endif
                    @if ($package->airline) &middot; {{ $package->airline }} @endif
                </p>

                @foreach ($package->prices() as $occupancy => $amount)
                    <p><span class="text-stone-500">{{ $occupancy }}</span>
                        <span class="font-medium">{{ number_format($amount / 1000000, 1, ',', '.') }} jt</span>
                        @if ($package->price_starting_from)<span class="text-[10px] text-stone-400">mulai</span>@endif
                    </p>
                @endforeach

                @if ($package->convertedFromUsd())
                    <p class="text-[10px] text-stone-400">konversi dari USD, kurs {{ number_format((int) config('umroh.usd_rate'), 0, ',', '.') }}</p>
                @endif

                @php $stays = array_filter(['Mkh' => $package->hotel_makkah, 'Mdn' => $package->hotel_madinah]); @endphp
                @foreach ($stays as $city => $raw)
                    <p class="text-stone-600"><span class="text-stone-400">{{ $city }}</span> {{ $raw }}</p>
                @endforeach

                @if ($package->guide_name)
                    <p class="text-stone-600"><span class="text-stone-400">Pmb</span> {{ $package->guide_name }}</p>
                @endif

                @if ($package->reposts)
                    <p class="text-stone-400">+{{ count($package->reposts) }} akun repost</p>
                @endif

                <p class="truncate">
                    <a href="{{ $package->source_permalink }}" rel="nofollow noopener" target="_blank"
                       class="text-stone-500 underline @if (! $package->source_permalink) pointer-events-none text-stone-300 @endif"
                    >&#64;{{ $package->source_account ?? '-' }}</a>
                </p>

                @include('partials.warnings')

                @if ($preview ?? false)
                    <details class="mt-1 border-t border-stone-100 pt-1">
                        <summary class="cursor-pointer text-[10px] text-stone-400">catatan &amp; jejak</summary>

                        <p class="mt-1 select-all break-all font-mono text-[10px] leading-tight text-stone-400">
                            {{ $package->status }} &middot; conf={{ $package->confidence ?? '-' }}<br>
                            raw/{{ $package->source_account }}/{{ $package->media_id }}
                        </p>

                        <form method="POST" action="{{ route('package.feedback', $package) }}" data-feedback class="mt-1 space-y-1">
                            @csrf
                            <select name="review_verdict" required class="w-full rounded border-stone-300 py-0.5 text-[11px]">
                                <option value="">— nilai —</option>
                                @foreach (App\Models\Package::REVIEW_VERDICTS as $code => $label)
                                    <option value="{{ $code }}" @selected($package->review_verdict === $code)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <input name="review_note" value="{{ $package->review_note }}" placeholder="salahnya di mana?"
                                   class="w-full rounded border-stone-300 py-0.5 text-[11px]">
                            <div class="flex items-center gap-1">
                                <button class="rounded bg-stone-900 px-1.5 py-0.5 text-[11px] text-white">Simpan</button>
                                <span data-status class="text-[10px] text-stone-400">
                                    @if ($package->reviewed_at) dinilai @endif
                                </span>
                            </div>
                        </form>
                    </details>
                @endif
            </div>
        </article>
    @empty
        <p class="col-span-full rounded border border-dashed border-stone-300 p-8 text-center text-sm text-stone-500">
            Belum ada paket. Jalankan fetch &rarr; extract &rarr; packages:import.
        </p>
    @endforelse
</div>

@if ($preview ?? false)
{{-- Simpan catatan & buang kartu tanpa reload: halamannya panjang, reload tiap
     aksi bikin posisi scroll balik ke atas terus. --}}
<script>
const csrf = document.querySelector('meta[name=csrf-token]').content;

document.addEventListener('submit', async (event) => {
    const form = event.target.closest('form[data-feedback]');
    if (!form) return;
    event.preventDefault();

    const status = form.querySelector('[data-status]');
    const set = (text, color) => { status.className = 'text-[10px] ' + color; status.textContent = text; };
    set('menyimpan…', 'text-stone-400');

    try {
        const res = await fetch(form.action, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(form),
        });
        set(res.ok ? 'tersimpan' : 'gagal (' + res.status + ')', res.ok ? 'text-green-700' : 'text-red-600');
    } catch (e) {
        set('gagal: ' + e.message, 'text-red-600');
    }
});

// Pipeline: tombol lempar job, sisanya polling angka dari server. Tidak ada
// state progress yang disimpan — semuanya dihitung ulang dari raw/extracted/DB.
const bar      = document.querySelector('[data-bar]');
const progress = document.querySelector('[data-progress]');
const now      = document.querySelector('[data-now]');
const log      = document.querySelector('[data-log]');
const escapeHtml = (text) => text.replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
const runButton = document.querySelector('[data-pipeline]');
const refresh  = document.querySelector('[data-refresh]');
let paketAwal  = null;

// Warna per antrian, biar kelihatan bagian mana yang jalan tanpa harus dibaca.
const WARNA = { ig: 'text-sky-700', ai: 'text-violet-700', db: 'text-emerald-700', run: 'text-stone-700' };

const render = (n) => {
    const persen = n.akun ? Math.round(n.terfetch / n.akun * 100) : 0;
    bar.style.width = persen + '%';
    progress.textContent = `${n.terfetch}/${n.akun} akun · ${n.raw} post · ${n.extracted} hasil ekstraksi`
        + ` · ${n.paket} paket · ${n.dibanned} dibanned`
        + (n.antrian ? ` · ${n.antrian} job antri` : ' · idle');
    runButton.disabled = n.jalan;

    // Tiga antrian jalan sendiri-sendiri: ig fetch, ai konversi, db import.
    now.innerHTML = ['ig', 'ai', 'db'].map((q) => {
        const pesan = n.sekarang?.[q];
        return `<div class="truncate ${pesan ? WARNA[q] : 'text-stone-300'}">`
            + `<span class="font-mono">${q}</span> ${pesan ? escapeHtml(pesan) : 'idle'}</div>`;
    }).join('');

    if (n.log?.length) {
        const habis = log.scrollTop + log.clientHeight >= log.scrollHeight - 20;
        // Satu baris = satu <div>: triple-click di <pre> menyorot seluruh blok,
        // per-div bikin yang kecopy cuma barisnya.
        log.innerHTML = n.log.map((baris) =>
            `<div><span class="text-stone-400">${baris.t}</span> `
            + `<span class="${WARNA[baris.q] ?? 'text-stone-500'}">${baris.q}</span> `
            + escapeHtml(baris.m) + '</div>').join('');
        if (habis) log.scrollTop = log.scrollHeight;   // jangan rebut scroll kalau sedang dibaca ke atas
    }

    if (paketAwal === null) paketAwal = n.paket;
    refresh.classList.toggle('hidden', n.paket === paketAwal);
    if (n.paket !== paketAwal) refresh.textContent = `${n.paket - paketAwal} paket baru — muat ulang`;
};

const poll = async () => {
    try { render(await (await fetch('{{ route('pipeline.status') }}', { headers: { 'Accept': 'application/json' } })).json()); }
    catch (e) { progress.textContent = 'status gagal: ' + e.message; }
};

if (runButton) {
    poll();
    setInterval(poll, 2000);

    runButton.addEventListener('click', async () => {
        runButton.disabled = true;
        progress.textContent = 'mengantrikan…';
        try {
            render(await (await fetch('{{ route('pipeline.start') }}', {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            })).json());
        } catch (e) {
            progress.textContent = 'gagal: ' + e.message;
            runButton.disabled = false;
        }
    });
}

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
        button.classList.add('bg-red-600');
    }
});
</script>
@endif
@endsection
