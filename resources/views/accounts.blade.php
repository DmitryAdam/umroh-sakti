@extends('layout')
@section('title', 'Akun Sumber')

@section('content')
{{-- Panel pipeline pindah ke halamannya sendiri: numpang di atas tabel ini, jejaknya
     harus dilipat supaya daftar akunnya kelihatan. --}}
<div class="mb-3 text-xs">
    <a href="{{ route('pipeline') }}" class="underline">panel pipeline &rarr;</a>
</div>

@include('partials.flash')

{{-- Usulan akun dari peran `user`: belum di-scrap (semua jalur crawl menyaring
     `approved`), dan yang bisa dikerjakan atasnya cuma dua — setujui atau buang.
     Tombolnya lewat endpoint bulk yang sama dengan tabel di bawah, jadi tidak ada
     jalur kedua yang bisa menyimpang. --}}
@if ($pending->isNotEmpty())
    <div class="mb-3 rounded border border-amber-300 bg-amber-50 text-xs">
        <div class="flex flex-wrap items-center gap-2 border-b border-amber-200 px-3 py-2 text-amber-900">
            <p><strong>{{ $pending->count() }} usulan akun</strong> menunggu — belum ikut di putaran scrap.</p>
            {{-- Setujui semua: endpoint bulk yang sama, cuma id-nya semua sekaligus.
                 Approval itu murah (cuma status) dan tidak mengantrikan fetch apa pun,
                 jadi tidak ada kuota yang bisa terbakar karena salah pencet. --}}
            <form method="POST" action="{{ route('accounts.bulk') }}" class="ml-auto"
                  onsubmit="return confirm('Setujui semua {{ $pending->count() }} usulan akun?')">
                @csrf
                @foreach ($pending as $account)
                    <input type="hidden" name="ids[]" value="{{ $account->id }}">
                @endforeach
                <button name="action" value="approve"
                        class="rounded border border-amber-400 bg-white px-2 py-0.5 hover:bg-amber-100">setujui semua ({{ $pending->count() }})</button>
            </form>
        </div>
        {{-- Wadah overflow-x sendiri: selnya `whitespace-nowrap`, jadi tabel yang
             lebih lebar dari layar HP menggeser SELURUH halaman kalau tidak dikurung. --}}
        <div class="overflow-x-auto">
        <table class="w-full">
            <tbody class="divide-y divide-amber-200">
                @foreach ($pending as $account)
                    <tr>
                        <td class="px-3 py-1.5">
                            <a href="https://www.instagram.com/{{ $account->username }}" target="_blank" rel="noopener"
                               class="font-medium underline">{{ '@'.$account->username }}</a>
                        </td>
                        <td class="px-3 py-1.5 text-stone-500">diusulkan {{ $account->suggested_by ?? '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-1.5 text-right">
                            <form method="POST" action="{{ route('accounts.bulk') }}" class="inline">
                                @csrf
                                <input type="hidden" name="ids[]" value="{{ $account->id }}">
                                <button name="action" value="approve"
                                        class="rounded border border-stone-300 bg-white px-2 py-0.5 hover:bg-stone-100">setujui</button>
                                <button name="action" value="delete"
                                        onclick="return confirm('Buang usulan {{ '@'.$account->username }}?')"
                                        class="rounded px-1 leading-none text-stone-400 hover:bg-red-600 hover:text-white">&times;</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </div>
@endif

{{-- Form tambah akun di <dialog>: jarang dipakai, tapi textarea-nya makan satu
     baris penuh di atas daftar. Esc & fokus ditangani browser, tanpa library. --}}
<dialog id="tambah" class="m-auto w-[min(28rem,92vw)] rounded border border-stone-300 p-4 shadow-lg backdrop:bg-stone-900/40">
    <form method="POST" action="{{ route('accounts.store') }}" class="flex flex-col gap-2 text-xs">
        @csrf
        <label for="usernames" class="font-medium">Tambah akun sumber</label>
        <p class="text-stone-500">Username, <code>@handle</code>, atau URL Instagram — satu per baris. Baris diawali <code>#</code> dilewat.</p>
        <textarea id="usernames" name="usernames" rows="6" required autofocus
                  placeholder="hamdantour&#10;https://www.instagram.com/sunnatravel.id"
                  class="rounded border border-stone-300 p-2 font-mono text-xs"></textarea>
        <div class="flex justify-end gap-2">
            <button type="button" formnovalidate onclick="tambah.close()"
                    class="rounded border border-stone-300 px-3 py-1.5 hover:bg-stone-100">Batal</button>
            <button class="rounded bg-stone-900 px-3 py-1.5 text-white">Tambah</button>
        </div>
    </form>
</dialog>
@error('usernames') <script>tambah.showModal()</script> @enderror

{{-- "gagal" di sini bukan sekadar `last_error` terisi: akun yang pernah berhasil
     di-scrap atau sudah punya post/paket cuma kena satu percobaan meleset.
     Definisinya satu tempat, SourceAccount::gagal(); isinya dari `$isi`. --}}
{{-- Angka & tombol kelompok dihitung dari `$semua` (seluruh akun), bukan dari
     `$accounts` yang cuma sehalaman: "belum pernah di-scrap" yang ikut nomor halaman
     bukan angka yang bisa dipakai memutuskan apa pun. --}}
@php ($approved = $semua->where('status', 'approved'))
@php ($belum = $approved->whereNull('last_fetched_at'))
@php ($gagal = $approved->filter(fn ($a) => $a->gagal($isi($a))))
<div class="mb-2 flex flex-wrap items-center gap-3 text-xs text-stone-600">
    <button type="button" onclick="tambah.showModal()"
            class="rounded bg-stone-900 px-2 py-1 text-xs text-white">+ Tambah akun</button>
    <span>
        {{ $semua->count() }} akun ·
        {{ $belum->count() }} belum pernah di-scrap
        @if ($gagal->isNotEmpty())
            · <span class="text-red-700" title="gagal dan masih kosong — yang error tapi sudah punya paket tidak dihitung">{{ $gagal->count() }} gagal &amp; kosong</span>
        @endif
        @if ($sort)
            · <a href="{{ route('accounts') }}" class="underline">urutan default</a>
        @endif
    </span>
    @if ($approved->isNotEmpty())
        <div class="ml-auto flex flex-wrap items-center gap-2">
            @if ($gagal->isNotEmpty())
                <form method="POST" action="{{ route('accounts.crawl') }}"
                      onsubmit="return confirm('Antrikan fetch ulang untuk {{ $gagal->count() }} akun yang gagal?')">
                    @csrf
                    <input type="hidden" name="failed" value="1">
                    <button class="rounded border border-red-300 px-2 py-1 text-xs text-red-700 hover:bg-red-50">Scrap yang gagal ({{ $gagal->count() }})</button>
                </form>
            @endif
            @if ($belum->isNotEmpty())
                <form method="POST" action="{{ route('accounts.crawl') }}"
                      onsubmit="return confirm('Antrikan fetch untuk {{ $belum->count() }} akun yang belum pernah di-scrap?')">
                    @csrf
                    <input type="hidden" name="new" value="1">
                    <button class="rounded border border-stone-300 px-2 py-1 text-xs hover:bg-stone-100">Scrap yang belum pernah ({{ $belum->count() }})</button>
                </form>
            @endif
            {{-- Scrap dari yang paling lama tidak disentuh: akun yang baru saja
                 di-scrap dilewat, jadi kuota Graph tidak dibakar untuk post yang
                 itu-itu juga. Dispatch-nya sudah diurut terlama duluan di
                 packages:crawl. --}}
            <form method="POST" action="{{ route('accounts.crawl') }}" class="flex items-center gap-1"
                  onsubmit="return confirm('Antrikan fetch untuk akun yang terakhir di-scrap lebih dari ' + this.jam.value + ' jam lalu?')">
                @csrf
                <input type="number" name="hours" value="24" min="1" max="8760" required
                       class="w-14 rounded border border-stone-300 px-1 py-1 text-xs tabular-nums">
                <button title="scrap yang terakhir berhasilnya paling lama — yang baru di-scrap dilewat"
                        class="rounded border border-stone-300 px-2 py-1 text-xs hover:bg-stone-100">Scrap &gt; jam</button>
            </form>
        </div>
    @endif
</div>

{{-- Form bulk sengaja KOSONG dan di luar tabel: checkbox tiap baris menunjuk ke
     sini lewat atribut `form="bulk"` (HTML5), jadi tidak ada <form> yang
     membungkus tabel dan bertabrakan dengan form per-baris di kolom terakhir —
     form bersarang itu tidak valid dan browsernya membuang yang di dalam. --}}
<form id="bulk" method="POST" action="{{ route('accounts.bulk') }}">@csrf</form>

<div data-bulk class="mb-2 hidden flex-wrap items-center gap-2 rounded border border-stone-300 bg-stone-50 px-3 py-2 text-xs">
    <span><strong data-terpilih>0</strong> akun dipilih</span>
    <button type="button" data-batal-pilih class="text-stone-500 underline">batal pilih</button>
    <div class="ml-auto flex flex-wrap items-center gap-2">
        <button form="bulk" name="action" value="crawl"
                class="rounded border border-stone-300 bg-white px-2 py-1 hover:bg-stone-100">Scrap terpilih</button>
        <button form="bulk" name="action" value="force" data-confirm="Scrap paksa"
                data-catatan="Post yang pernah ditolak dilepas dan dibaca AI lagi (bayar model lagi)."
                title="post yang pernah ditolak ikut di-download & dibaca AI lagi"
                class="rounded border border-stone-300 bg-white px-2 py-1 hover:bg-stone-100">Scrap paksa terpilih</button>
        <button form="bulk" name="action" value="block" data-confirm="Blokir"
                class="rounded border border-stone-300 bg-white px-2 py-1 hover:bg-stone-100">Blokir terpilih</button>
        <button form="bulk" name="action" value="delete" data-confirm="Hapus total"
                class="rounded border border-red-300 bg-white px-2 py-1 text-red-700 hover:bg-red-50">Hapus terpilih</button>
    </div>
</div>

{{-- Tabel: angkanya banyak dan cuma bisa dibandingkan kalau sekolom. Urutannya
     query string (`?sort=&dir=`), satu form GET tanpa JS — sama seperti filter di `/`.
     Whitelist kolomnya di AccountController::SORTS; nilai asing balik ke urutan
     default (yang perlu tindakan di atas). --}}
{{-- Sengaja bentuk sebaris, bukan blok: file ini sudah punya satu directive php
     sebaris di atas, dan penutup blok mana pun (termasuk yang cuma disebut di
     dalam komentar seperti ini — blok raw dipungut sebelum komentar dibuang)
     akan dipasangkan dengannya, menelan markup di antaranya jadi PHP mentah. --}}
@php($angka = ['followers', 'following', 'ig_posts', 'downloaded', 'packages', 'rejected'])
@php($judul = [
    'followers' => 'pengikut akun ini di Instagram',
    'ig_posts' => 'total post akun ini di Instagram — bukan yang kita unduh',
    'downloaded' => 'post yang sudah di-download ke storage/raw',
    'packages' => 'baris paket di database dari akun ini',
    'rejected' => 'post yang ditolak dan tidak akan di-scrap lagi',
])
<div class="overflow-x-auto rounded border border-stone-200 bg-white">
    <table class="w-full text-xs">
        <thead class="border-b border-stone-200 bg-stone-50 text-stone-500">
            <tr>
                <th class="px-2 py-1">
                    <input type="checkbox" data-semua title="pilih semua baris di halaman ini" class="align-middle">
                </th>
                @foreach (\App\Http\Controllers\AccountController::SORTS as $key => $label)
                    <th class="whitespace-nowrap px-2 py-1 font-medium {{ in_array($key, $angka) ? 'text-right' : 'text-left' }}">
                        {{-- Klik = urut menurun, klik lagi = balik arah. --}}
                        <a href="{{ request()->fullUrlWithQuery(['sort' => $key, 'dir' => $sort === $key && $dir === 'desc' ? 'asc' : 'desc']) }}"
                           @isset($judul[$key]) title="{{ $judul[$key] }}" @endisset
                           class="hover:text-stone-900 {{ $sort === $key ? 'font-semibold text-stone-900' : '' }}">
                            {{ $label }}{{ $sort === $key ? ($dir === 'desc' ? ' ↓' : ' ↑') : '' }}
                        </a>
                    </th>
                @endforeach
                <th class="px-2 py-1"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
            @forelse ($accounts as $account)
                <tr class="{{ ($packages[$account->username] ?? 0) === 0 ? 'bg-red-50 hover:bg-red-100' : 'hover:bg-stone-50' }}">
                    <td class="px-2 py-1">
                        {{-- `form="bulk"` menitipkan centang ini ke form di atas tabel,
                             tanpa <form> yang membungkus <tr>. --}}
                        <input type="checkbox" form="bulk" name="ids[]" value="{{ $account->id }}"
                               data-pilih class="align-middle">
                    </td>
                    <td class="px-2 py-1">
                        <div class="flex items-center gap-1.5">
                            {{-- Foto profil hasil download; filenya bisa belum ada (belum
                                 pernah di-scrap, atau downloadnya gagal) -> elemennya dibuang. --}}
                            @if ($account->last_fetched_at)
                                <img src="{{ route('avatar', $account->username) }}" alt="" loading="lazy"
                                     onerror="this.remove()" class="size-6 shrink-0 rounded-full bg-stone-100 object-cover">
                            @endif
                            <div class="min-w-0">
                                <a href="https://www.instagram.com/{{ $account->username }}" target="_blank" rel="noopener"
                                   class="block truncate font-medium underline">{{ '@'.$account->username }}</a>
                                @if ($account->full_name)
                                    <span class="block max-w-[15rem] truncate text-[11px] text-stone-500">{{ $account->full_name }}</span>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td class="px-2 py-1 text-right tabular-nums">{{ $account->followers_count === null ? '–' : Number::format($account->followers_count, locale: 'id') }}</td>
                    <td class="px-2 py-1 text-right tabular-nums text-stone-500">{{ $account->follows_count === null ? '–' : Number::format($account->follows_count, locale: 'id') }}</td>
                    <td class="px-2 py-1 text-right tabular-nums text-stone-500">{{ $account->media_count === null ? '–' : Number::format($account->media_count, locale: 'id') }}</td>
                    {{-- Tiga angka ini menautkan ke isinya per post (gambar + alasan
                         ditolak) di /accounts/{account}/posts, masing-masing ke tabnya. --}}
                    <td class="px-2 py-1 text-right tabular-nums text-stone-500">
                        <a href="{{ route('accounts.posts', $account) }}" class="underline decoration-stone-300 hover:decoration-stone-900"
                           title="lihat post yang terunduh">{{ $posts[$account->username] ?? 0 }}</a>
                    </td>
                    <td class="px-2 py-1 text-right tabular-nums font-medium">
                        <a href="{{ route('accounts.posts', [$account, 'filter' => 'packages']) }}" class="underline decoration-stone-300 hover:decoration-stone-900"
                           title="lihat post yang jadi paket">{{ $packages[$account->username] ?? 0 }}</a>
                    </td>
                    <td class="px-2 py-1 text-right tabular-nums text-stone-400">
                        <a href="{{ route('accounts.posts', [$account, 'filter' => 'rejected']) }}" class="underline decoration-stone-300 hover:decoration-stone-900"
                           title="lihat post yang ditolak + alasannya">{{ $dikecualikan[$account->username] ?? 0 }}</a>
                    </td>
                    <td class="whitespace-nowrap px-2 py-1 text-stone-500">
                        @if ($account->last_fetched_at)
                            <span title="{{ $account->last_fetched_at }}">{{ $account->last_fetched_at->diffForHumans() }}</span>
                        @else
                            <span class="rounded bg-amber-100 px-1.5 py-0.5 text-amber-900">belum pernah</span>
                        @endif
                        @if ($account->status !== 'approved') &middot; {{ $account->status }} @endif

                        {{-- Percobaan terakhir gagal. Timestamp di sebelahnya tetap yang
                             terakhir BERHASIL, jadi dua-duanya perlu kelihatan sekaligus.
                             Merah cuma kalau akunnya masih kosong (SourceAccount::gagal());
                             error di akun yang sudah punya post/paket itu riwayat,
                             bukan tugas. --}}
                        @if ($account->last_error)
                            <span class="ml-1 inline-block max-w-[18rem] truncate align-bottom {{ $account->gagal($isi($account)) ? 'text-red-700' : 'text-stone-400' }}"
                                  title="{{ $account->last_error }}">gagal: {{ $account->last_error }}</span>
                        @endif
                    </td>
                    <td class="whitespace-nowrap px-2 py-1 text-right">
                        <form method="POST" action="{{ route('accounts.fetch', $account) }}" class="inline">
                            @csrf
                            <button class="rounded border border-stone-300 px-2 py-0.5 hover:bg-stone-100">scrap</button>
                        </form>
                        {{-- Scrap paksa: lepas post akun ini dari excluded_posts dulu, jadi yang
                             pernah ditolak ikut di-download & dibayar ke model lagi. --}}
                        <form method="POST" action="{{ route('accounts.fetch', $account) }}" class="inline"
                              onsubmit="return confirm('Scrap paksa {{ '@'.$account->username }}? {{ $dikecualikan[$account->username] ?? 0 }} post yang pernah ditolak dilepas dan dibaca AI lagi (bayar model lagi).')">
                            @csrf
                            <input type="hidden" name="force" value="1">
                            <button title="scrap paksa: post yang pernah ditolak ikut di-download & dibaca AI lagi"
                                    class="rounded border border-stone-300 px-2 py-0.5 hover:bg-stone-100">paksa</button>
                        </form>
                        {{-- Dua tindakan yang beda akibatnya, jadi dua tombol: blokir menyimpan
                             barisnya (itu yang menolak username yang sama nanti), hapus
                             menghilangkan semuanya. Dua-duanya membuang paket akun ini. --}}
                        <form method="POST" action="{{ route('accounts.block', $account) }}" class="inline"
                              onsubmit="return confirm('Blokir {{ '@'.$account->username }}? {{ $packages[$account->username] ?? 0 }} paketnya dihapus dan username ini ditolak kalau dimasukkan lagi.')">
                            @csrf
                            <button title="blokir: simpan di blocklist, buang paketnya" class="rounded border border-stone-300 px-2 py-0.5 hover:bg-stone-100">blokir</button>
                        </form>
                        <form method="POST" action="{{ route('accounts.destroy', $account) }}" class="inline"
                              onsubmit="return confirm('Hapus total {{ '@'.$account->username }}? {{ $packages[$account->username] ?? 0 }} paket, raw, dan profilnya ikut dihapus — username ini bisa dimasukkan lagi nanti.')">
                            @csrf @method('DELETE')
                            <button title="hapus total (baris + paket + raw)" class="rounded px-1 leading-none text-stone-400 hover:bg-red-600 hover:text-white">&times;</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="p-4 text-center text-stone-500">
                        Belum ada akun. Tambah di atas, atau <code>php artisan packages:crawl accounts.txt</code>.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- Prev/next saja, bukan `$accounts->links()`: paginator bawaan Laravel merender
     kelas Tailwind v3 yang tidak ada di build v4 kita. Sama dengan /posts. --}}
@if ($accounts->hasPages())
    <div class="mt-2 flex items-center gap-3 text-xs text-stone-500">
        @if ($accounts->previousPageUrl())
            <a href="{{ $accounts->previousPageUrl() }}" class="underline">&larr; sebelumnya</a>
        @endif
        <span>halaman {{ $accounts->currentPage() }} dari {{ $accounts->lastPage() }} — {{ $accounts->total() }} akun</span>
        @if ($accounts->nextPageUrl())
            <a href="{{ $accounts->nextPageUrl() }}" class="underline">berikutnya &rarr;</a>
        @endif
    </div>
@endif

{{-- Blocklist: barisnya sengaja disimpan walau datanya sudah dibuang — itu yang
     menolak username yang sama saat dimasukkan lagi. Dilipat karena isinya tidak
     perlu dilihat tiap hari. --}}
@if ($blocked->isNotEmpty())
    <details class="mt-3 rounded border border-stone-200 bg-white text-xs">
        <summary class="cursor-pointer px-3 py-2 text-stone-600">
            Blocklist ({{ $blocked->count() }}) — tidak di-scrap, ditolak kalau dimasukkan lagi
        </summary>
        <div class="overflow-x-auto border-t border-stone-200">
        <table class="w-full text-xs">
            <tbody class="divide-y divide-stone-100">
                @foreach ($blocked as $account)
                    <tr class="hover:bg-stone-50">
                        <td class="px-3 py-1">
                            <a href="https://www.instagram.com/{{ $account->username }}" target="_blank" rel="noopener"
                               class="font-medium underline">{{ '@'.$account->username }}</a>
                            @if ($account->full_name)
                                <span class="ml-1 text-[11px] text-stone-500">{{ $account->full_name }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-1 text-right tabular-nums text-stone-400">
                            {{ $dikecualikan[$account->username] ?? 0 }} post ditolak
                        </td>
                        <td class="whitespace-nowrap px-3 py-1 text-right">
                            <form method="POST" action="{{ route('accounts.block', $account) }}" class="inline">
                                @csrf
                                <input type="hidden" name="unblock" value="1">
                                <button title="kembalikan jadi approved (datanya tidak balik)"
                                        class="rounded border border-stone-300 px-2 py-0.5 hover:bg-stone-100">lepas blokir</button>
                            </form>
                            <form method="POST" action="{{ route('accounts.destroy', $account) }}" class="inline"
                                  onsubmit="return confirm('Hapus {{ '@'.$account->username }} dari blocklist juga? Username ini jadi bisa dimasukkan lagi.')">
                                @csrf @method('DELETE')
                                <button title="hapus total dari blocklist" class="rounded px-1 leading-none text-stone-400 hover:bg-red-600 hover:text-white">&times;</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </details>
@endif

{{-- Centang -> bar aksi. Partial yang sama dipakai /posts. --}}
@include('partials.bulk-select', ['satuan' => 'akun', 'catatan' => 'Paketnya ikut dihapus.'])
@endsection
