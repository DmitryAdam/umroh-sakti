{{-- Kartu hasil pencarian. Partial sendiri karena dipakai dua kali: render
     halaman penuh, dan balasan `index()` saat halaman berikutnya diambil lewat
     fetch (gulir tak berujung). Satu markup, jadi kartu halaman 2 tidak bisa
     diam-diam beda dari halaman 1. --}}
@php
    // Tombol aksi per kartu (pratinjau lokal). Semuanya sebaris di bawah gambar,
    // tooltipnya `title` bawaan browser — tidak perlu library.
    $aksi = 'grid size-7 place-items-center rounded-md text-muted-foreground hover:bg-accent hover:text-accent-foreground disabled:opacity-50';
@endphp

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

{{-- Sentinel gulir tak berujung. Ikut di partial ini, bukan di search.blade.php,
     supaya balasan fetch membawa tombol halaman berikutnya sekalian — JS cukup
     menempel dan membuang yang lama, tanpa tahu nomor halaman. Tidak dirender
     sama sekali di halaman terakhir, jadi "sudah habis" tidak butuh state.
     <a> biasa: tanpa JS ini tetap tombol "halaman berikutnya". --}}
@if ($packages->hasMorePages())
    <div class="col-span-full mt-1 flex justify-center">
        <a data-more href="{{ $packages->nextPageUrl() }}" rel="next"
           class="rounded-xl border px-4 py-2 text-sm text-muted-foreground hover:bg-accent">muat lebih banyak</a>
    </div>
@endif
