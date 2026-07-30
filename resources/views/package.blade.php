@extends('layout')
@section('title', 'Detail Paket')

@section('content')
<a href="{{ route('search') }}" class="text-sm text-stone-500 underline">&larr; Kembali</a>

<article class="mt-3 rounded-lg border border-stone-200 bg-white p-5">
    <h1 class="text-xl font-semibold">
        {{ $package->departure_city ?? 'Kota ?' }} &middot;
        {{ $package->duration_days ? $package->duration_days . ' hari' : 'durasi ?' }}
    </h1>
    <p class="text-sm text-stone-600">
        {{ $package->departure_date?->translatedFormat('d F Y') ?? 'Tanggal belum pasti' }}
        @if ($package->airline) &middot; {{ $package->airline }} @endif
        @if ($package->extension !== 'none') &middot; extension {{ $package->extension }} @endif
    </p>

    @include('partials.warnings')

    <h2 class="mt-5 text-sm font-semibold uppercase tracking-wide text-stone-500">Harga</h2>
    <table class="mt-2 w-full text-sm">
        @foreach ($package->prices() as $occupancy => $amount)
            <tr class="border-b border-stone-100">
                <td class="py-1 capitalize">{{ $occupancy }}</td>
                <td class="py-1 text-right font-medium">
                    Rp{{ number_format($amount, 0, ',', '.') }}
                    @if ($package->price_starting_from)<span class="text-xs text-stone-400">mulai dari</span>@endif
                </td>
            </tr>
        @endforeach
    </table>
    @if ($package->convertedFromUsd())
        <p class="mt-1 text-xs text-stone-400">
            Flyer memasang harga dalam USD. Angka di atas hasil konversi kurs
            {{ number_format((int) config('umroh.usd_rate'), 0, ',', '.') }} — konfirmasi ke travel.
        </p>
    @endif

    <h2 class="mt-5 text-sm font-semibold uppercase tracking-wide text-stone-500">Hotel</h2>
    <ul class="mt-2 space-y-1 text-sm">
        @php $stays = array_filter(['makkah' => $package->hotel_makkah, 'madinah' => $package->hotel_madinah]); @endphp
        @forelse ($stays as $city => $raw)
            <li>
                <span class="capitalize text-stone-500">{{ $city }}:</span>
                {{-- Apa adanya dari flyer: tidak dicocokkan ke master hotel. --}}
                {{ $raw }}
                @if ($nights = $package->{"nights_$city"}) <span class="text-stone-500">({{ $nights }} malam)</span> @endif
            </li>
        @empty
            <li class="text-stone-500">Tidak disebutkan.</li>
        @endforelse
    </ul>

    @if ($package->facilities)
        <h2 class="mt-5 text-sm font-semibold uppercase tracking-wide text-stone-500">Termasuk</h2>
        <div class="mt-2 flex flex-wrap gap-1">
            @foreach ($package->facilities as $code)
                <span class="rounded bg-stone-100 px-2 py-0.5 text-xs">{{ str_replace('_', ' ', $code) }}</span>
            @endforeach
        </div>
    @endif

    <h2 class="mt-5 text-sm font-semibold uppercase tracking-wide text-stone-500">Sumber</h2>
    <ul class="mt-2 space-y-1 text-sm">
        @foreach ($package->posts() as $post)
            <li>
                <span class="text-stone-500">&#64;{{ $post['account'] }}</span>
                @if ($post['permalink'])
                    <a href="{{ $post['permalink'] }}" rel="nofollow noopener" target="_blank"
                       class="text-stone-900 underline">lihat post asli</a>
                @endif
                @if ($loop->first)<span class="text-xs text-stone-400">(sumber ekstraksi)</span>@endif
            </li>
        @endforeach
    </ul>

    {{-- Wajib per brief: tanggal data + arahan konfirmasi. --}}
    <p class="mt-5 border-t border-stone-100 pt-3 text-xs text-stone-500">
        Data per {{ $package->extracted_at?->translatedFormat('d F Y') ?? '-' }}.
        Harga dan ketersediaan bisa berubah — konfirmasi langsung ke travel.
    </p>
</article>
@endsection
