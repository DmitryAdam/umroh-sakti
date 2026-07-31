{{-- Isi detail paket. Dipakai dua kali: halaman /paket/{id} dan lightbox di `/`.
     Tanpa <script>: di lightbox isinya masuk lewat innerHTML, jadi script apa pun
     di sini tidak akan jalan (tombol salin pakai onclick inline). --}}
@php
    // Ringkasan sebaris di kepala: ikon + nilai, yang kosong tidak dirender sama
    // sekali (bukan "—") supaya kepalanya tidak penuh placeholder.
    $ringkas = array_filter([
        ['calendar', $package->dateLabel('F') ?? 'tanggal belum pasti'],
        ['clock', $package->duration_days ? $package->duration_days.' hari' : null],
        ['pin', $package->departure_city],
        ['plane', $package->airline],
        ['plus', $package->extension !== 'none' ? 'extension '.$package->extension : null],
    ], fn ($x) => (bool) $x[1]);

    $chip = 'inline-flex items-center gap-1.5 rounded-md bg-muted px-2 py-1 text-xs text-muted-foreground';
    $judul = 'mt-6 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground';
@endphp

<div class="flex flex-wrap items-start justify-between gap-2">
    <h1 class="text-xl font-semibold tracking-tight">
        {{ $package->departure_city ?? 'Kota ?' }} &middot;
        {{ $package->duration_days ? $package->duration_days.' hari' : 'durasi ?' }}
    </h1>
    <div class="flex items-center gap-1.5">
        @if ($package->date_certainty !== 'exact')
            <x-ui.badge variant="outline" title="kepastian tanggal">{{ $package->date_certainty }}</x-ui.badge>
        @endif
        <x-ui.badge variant="{{ $package->status === 'published' ? 'default' : 'secondary' }}">{{ $package->status }}</x-ui.badge>
        {{-- ID paket sering ditempel ke catatan/chat, jadi sekali klik = tersalin. --}}
        <x-ui.copy :text="$package->id" title="salin ID paket" class="text-xs text-muted-foreground">#{{ $package->id }}</x-ui.copy>
    </div>
</div>

<div class="mt-2 flex flex-wrap gap-1.5">
    @foreach ($ringkas as [$ikon, $teks])
        <span class="{{ $chip }}"><x-ui.icon :name="$ikon" />{{ $teks }}</span>
    @endforeach
</div>

@include('partials.warnings')

@if ($flyers = $package->flyers())
    <div class="mt-4 flex snap-x snap-mandatory gap-2 overflow-x-auto rounded-lg bg-muted p-1">
        @foreach ($flyers as $url)
            <img src="{{ $url }}" alt="Flyer dari &#64;{{ $package->source_account }}" loading="lazy"
                 class="max-h-[60vh] w-full shrink-0 snap-center object-contain">
        @endforeach
    </div>
@endif

<h2 class="{{ $judul }}"><x-ui.icon name="money" />Harga</h2>
<table class="mt-2 w-full text-sm">
    @forelse ($package->prices() as $occupancy => $amount)
        <tr class="border-b last:border-0">
            <td class="py-1.5 capitalize">{{ $occupancy }}</td>
            <td class="py-1.5 text-right font-medium tabular-nums">
                Rp{{ number_format($amount, 0, ',', '.') }}
                @if ($package->price_starting_from)<span class="text-xs font-normal text-muted-foreground">mulai dari</span>@endif
            </td>
        </tr>
    @empty
        <tr><td class="py-1.5 text-muted-foreground">Tidak disebutkan.</td></tr>
    @endforelse
</table>
@if ($package->convertedFromUsd())
    <p class="mt-1 text-xs text-muted-foreground">
        Flyer memasang harga dalam USD. Angka di atas hasil konversi kurs
        {{ number_format((int) config('umroh.usd_rate'), 0, ',', '.') }} — konfirmasi ke travel.
    </p>
@endif

<h2 class="{{ $judul }}"><x-ui.icon name="hotel" />Hotel</h2>
<ul class="mt-2 space-y-1 text-sm">
    @php $stays = array_filter(['makkah' => $package->hotel_makkah, 'madinah' => $package->hotel_madinah]); @endphp
    @forelse ($stays as $city => $raw)
        <li>
            <span class="capitalize text-muted-foreground">{{ $city }}:</span>
            {{-- Apa adanya dari flyer: tidak dicocokkan ke master hotel. --}}
            {{ $raw }}
            @if ($nights = $package->{"nights_$city"}) <span class="text-muted-foreground">({{ $nights }} malam)</span> @endif
        </li>
    @empty
        <li class="text-muted-foreground">Tidak disebutkan.</li>
    @endforelse
</ul>

@if ($package->guide_name)
    <p class="mt-2 flex items-center gap-1.5 text-sm">
        <x-ui.icon name="user" class="text-muted-foreground" />{{ $package->guide_name }}
    </p>
@endif

@if ($package->facilities || $package->facilities_raw)
    <h2 class="{{ $judul }}"><x-ui.icon name="sparkles" />Termasuk</h2>
    <div class="mt-2 flex flex-wrap gap-1.5">
        @foreach ($package->facilities as $code)
            <x-ui.badge>{{ str_replace('_', ' ', $code) }}</x-ui.badge>
        @endforeach
    </div>
    {{-- Apa adanya dari flyer/caption. `facilities` cuma kode yang kenal di
         FACILITY_CODES, jadi "2x Jum'atan"/"Kereta Cepat Haramain" cuma ada di sini. --}}
    @if ($package->facilities_raw)
        <p class="mt-2 text-sm text-muted-foreground">{{ $package->facilities_raw }}</p>
    @endif
@endif

{{-- Caption post asal: sering memuat yang tidak muat di flyer (jadwal lengkap,
     syarat, kontak). Dilipat karena panjangnya bisa ratusan baris dan yang dicari
     orang tetap datanya di atas. Dibaca dari raw, bukan kolom — lihat caption(). --}}
@if ($caption = $package->caption())
    <details class="group mt-6 rounded-lg border">
        <summary class="flex cursor-pointer select-none items-center gap-1.5 px-3 py-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground marker:content-none [&::-webkit-details-marker]:hidden">
            <x-ui.icon name="quote" />Caption postingan
            <x-ui.icon name="chevron" class="ml-auto transition-transform group-open:rotate-180" />
        </summary>
        <p class="whitespace-pre-line border-t px-3 py-2 text-sm leading-relaxed">{{ $caption }}</p>
    </details>
@endif

<h2 class="{{ $judul }}"><x-ui.icon name="at" />Sumber</h2>
<ul class="mt-2 space-y-1 text-sm">
    @foreach ($package->posts() as $post)
        <li class="flex flex-wrap items-center gap-x-2">
            <span class="text-muted-foreground">&#64;{{ $post['account'] }}</span>
            @if ($post['permalink'])
                <a href="{{ $post['permalink'] }}" rel="nofollow noopener" target="_blank"
                   class="inline-flex items-center gap-1 underline underline-offset-4">lihat post asli<x-ui.icon name="external" /></a>
            @endif
            {{-- media_id dipakai buat nyocokin baris ini ke storage/raw & excluded_posts. --}}
            @if ($post['media_id'])
                <x-ui.copy :text="$post['media_id']" title="salin media_id" class="text-xs text-muted-foreground" />
            @endif
            @if ($loop->first)<span class="text-xs text-muted-foreground">(sumber ekstraksi)</span>@endif
        </li>
    @endforeach
</ul>

{{-- Wajib per brief: tanggal data + arahan konfirmasi. --}}
<p class="mt-6 border-t pt-3 text-xs text-muted-foreground">
    Data per {{ $package->extracted_at?->translatedFormat('d F Y') ?? '-' }}.
    Harga dan ketersediaan bisa berubah — konfirmasi langsung ke travel.
</p>
