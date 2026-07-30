{{-- Isi detail paket. Dipakai dua kali: halaman /paket/{id} dan lightbox di `/`. --}}
<h1 class="text-xl font-semibold tracking-tight">
    <span class="font-mono text-xs font-normal text-muted-foreground">#{{ $package->id }}</span>
    {{ $package->departure_city ?? 'Kota ?' }} &middot;
    {{ $package->duration_days ? $package->duration_days . ' hari' : 'durasi ?' }}
</h1>
<p class="mt-1 text-sm text-muted-foreground">
    {{-- Rentang: tanggal pulang dihitung dari durasi (hari ke-1 = hari berangkat). --}}
    {{ $package->dateLabel('F') ?? 'Tanggal belum pasti' }}
    @if ($package->date_certainty !== 'exact')({{ $package->date_certainty }})@endif
    @if ($package->airline) &middot; {{ $package->airline }} @endif
    @if ($package->extension !== 'none') &middot; extension {{ $package->extension }} @endif
</p>

@include('partials.warnings')

@if ($flyers = $package->flyers())
    <div class="mt-4 flex snap-x snap-mandatory gap-2 overflow-x-auto rounded-lg bg-muted p-1">
        @foreach ($flyers as $url)
            <img src="{{ $url }}" alt="Flyer dari &#64;{{ $package->source_account }}" loading="lazy"
                 class="max-h-[60vh] w-full shrink-0 snap-center object-contain">
        @endforeach
    </div>
@endif

<h2 class="mt-6 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Harga</h2>
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

<h2 class="mt-6 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Hotel</h2>
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
    <p class="mt-2 text-sm"><span class="text-muted-foreground">Pembimbing:</span> {{ $package->guide_name }}</p>
@endif

@if ($package->facilities || $package->facilities_raw)
    <h2 class="mt-6 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Termasuk</h2>
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

<h2 class="mt-6 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Sumber</h2>
<ul class="mt-2 space-y-1 text-sm">
    @foreach ($package->posts() as $post)
        <li>
            <span class="text-muted-foreground">&#64;{{ $post['account'] }}</span>
            @if ($post['permalink'])
                <a href="{{ $post['permalink'] }}" rel="nofollow noopener" target="_blank"
                   class="underline underline-offset-4">lihat post asli</a>
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
