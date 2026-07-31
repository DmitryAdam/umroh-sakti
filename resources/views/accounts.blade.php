@extends('layout')
@section('title', 'Akun Sumber')

@section('content')
<div class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-1 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900">
    <strong>Daftar akun (lokal).</strong>
    <span>Akun yang dimasukkan di sini langsung <code>approved</code> — ikut di putaran pipeline berikutnya.</span>
    <a href="{{ route('posts') }}" class="ml-auto underline">semua post</a>
    <a href="{{ route('search') }}" class="underline">ke daftar paket</a>
</div>

{{-- Panel pipeline: cuma pemantau + tombol batal. Yang mengantrikan job itu tombol
     scrap di tabel bawah (atau `packages:crawl`), yang mengerjakan `queue:work`. --}}
<div class="mb-3 rounded border border-stone-200 bg-white px-3 py-2 text-xs">
    <div class="flex flex-wrap items-center gap-3">
        <button type="button" data-batal
                class="rounded border border-stone-300 px-2 py-1 text-xs text-stone-700 disabled:opacity-40">Batalkan semua job</button>
        <div class="h-1.5 w-40 overflow-hidden rounded bg-stone-200">
            <div data-bar class="h-full w-0 bg-stone-900 transition-all"></div>
        </div>
        <span data-progress class="text-stone-500">memuat…</span>
        <a href="{{ route('search') }}" data-refresh class="ml-auto hidden text-stone-900 underline">lihat paket</a>
    </div>

    {{-- Corong: berapa yang masuk tiap tahap, dari akun sampai published. --}}
    <div data-corong class="mt-2 grid grid-cols-2 gap-px overflow-hidden rounded border border-stone-200 bg-stone-200 sm:grid-cols-4 lg:grid-cols-7"></div>

    {{-- Satu kartu per antrian: sisa antrian + progress batch berjalan + apa yang
         sedang dikerjakan workernya, plus tombol batal khusus antrian itu. --}}
    <div data-now class="mt-2 grid gap-2 sm:grid-cols-3"></div>

    {{-- Jejak detail, langsung dari stdout probe.php. --}}
    <details class="mt-2 border-t border-stone-100 pt-2" data-logbox>
        <summary class="cursor-pointer text-[11px] text-stone-500">jejak detail</summary>
        <pre data-log class="mt-1 max-h-64 overflow-auto whitespace-pre-wrap break-all rounded bg-stone-50 p-2 font-mono text-[10px] leading-tight text-stone-600"></pre>
    </details>
</div>

@if (session('status'))
    <p class="mb-3 rounded border border-emerald-300 bg-emerald-50 px-3 py-2 text-xs text-emerald-900">{{ session('status') }}</p>
@endif

@error('usernames')
    <p class="mb-3 rounded border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-900">{{ $message }}</p>
@enderror

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
@php ($approved = $accounts->where('status', 'approved'))
@php ($belum = $approved->whereNull('last_fetched_at'))
@php ($gagal = $approved->filter(fn ($a) => $a->gagal($isi($a))))
<div class="mb-2 flex flex-wrap items-center gap-3 text-xs text-stone-600">
    <button type="button" onclick="tambah.showModal()"
            class="rounded bg-stone-900 px-2 py-1 text-xs text-white">+ Tambah akun</button>
    <span>
        {{ $accounts->count() }} akun ·
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

{{-- Blocklist: barisnya sengaja disimpan walau datanya sudah dibuang — itu yang
     menolak username yang sama saat dimasukkan lagi. Dilipat karena isinya tidak
     perlu dilihat tiap hari. --}}
@if ($blocked->isNotEmpty())
    <details class="mt-3 rounded border border-stone-200 bg-white text-xs">
        <summary class="cursor-pointer px-3 py-2 text-stone-600">
            Blocklist ({{ $blocked->count() }}) — tidak di-scrap, ditolak kalau dimasukkan lagi
        </summary>
        <table class="w-full border-t border-stone-200 text-xs">
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
    </details>
@endif

{{-- Pipeline: tombol lempar job, sisanya polling angka dari server. Tidak ada
     state progress yang disimpan — semuanya dihitung ulang dari raw/extracted/DB. --}}
<script>
const csrf     = document.querySelector('meta[name=csrf-token]').content;
const bar      = document.querySelector('[data-bar]');
const progress = document.querySelector('[data-progress]');
const now      = document.querySelector('[data-now]');
const corong   = document.querySelector('[data-corong]');
const log      = document.querySelector('[data-log]');
const escapeHtml = (text) => text.replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
const batal    = document.querySelector('[data-batal]');
const refresh  = document.querySelector('[data-refresh]');
let paketAwal  = null;
// Puncak antrian per queue selama halaman ini terbuka — bahan bar progress.
// Job yang selesai tidak meninggalkan barisnya, jadi "berapa dari berapa" cuma bisa
// dihitung dari angka tertinggi yang pernah terlihat; balik ke 0 begitu antrian habis.
const puncak = { ig: 0, ai: 0, db: 0 };

// Warna per antrian, biar kelihatan bagian mana yang jalan tanpa harus dibaca.
const WARNA = { ig: 'text-sky-700', ai: 'text-violet-700', db: 'text-emerald-700', run: 'text-stone-700' };

// Corong pipeline. Dua satuan yang tidak boleh dicampur: tiga tahap pertama
// menghitung POST (satu postingan IG), sisanya PAKET (satu gambar penawaran) —
// satu carousel bisa jadi beberapa paket, jadi angkanya memang boleh naik di
// tengah corong. Satuannya ditulis di tiap kotak supaya tidak dikira dobel.
const CORONG = (n) => [
    { label: 'akun', nilai: `${n.terfetch}/${n.akun}`, sub: n.akun_gagal ? `${n.akun_gagal} gagal` : 'sudah di-scrap' },
    { label: 'post diunduh', nilai: n.post_diunduh, sub: 'post' },
    { label: 'nunggu ai', nilai: n.post_menunggu, sub: n.antri_ai ? `${n.antri_ai} job antri` : 'post' },
    { label: 'dibaca ai', nilai: n.post_dibaca, sub: `${n.hasil_ekstraksi} hasil` },
    {
        label: 'dikecualikan',
        nilai: n.post_dikecualikan,
        sub: Object.entries(n.alasan ?? {}).map(([k, v]) => `${k.replace('_', ' ')} ${v}`).join(' · ') || 'post',
    },
    { label: 'jadi paket', nilai: n.paket, sub: `${n.review} review · ${n.draft} draft` },
    { label: 'published', nilai: n.published, sub: n.published ? 'tampil publik' : 'belum ada' },
];

const render = (n) => {
    const persen = n.akun ? Math.round(n.terfetch / n.akun * 100) : 0;
    bar.style.width = persen + '%';
    progress.textContent = (n.antrian ? `${n.antrian} job antri (ig ${n.antri_ig} · ai ${n.antri_ai} · db ${n.antri_db})` : 'idle')
        + (n.gagal ? ` · ${n.gagal} job gagal` : '');
    batal.disabled = !n.antrian && !n.gagal;

    corong.innerHTML = CORONG(n).map(({ label, nilai, sub }) =>
        `<div class="bg-white px-2 py-1.5">`
        + `<div class="text-[10px] uppercase tracking-wide text-stone-400">${escapeHtml(label)}</div>`
        + `<div class="font-medium text-stone-900">${escapeHtml(String(nilai))}</div>`
        + `<div class="truncate text-[10px] text-stone-400" title="${escapeHtml(sub)}">${escapeHtml(sub)}</div>`
        + `</div>`).join('');

    // Tiga antrian jalan sendiri-sendiri: ig fetch, ai konversi, db import.
    now.innerHTML = ['ig', 'ai', 'db'].map((q) => {
        const a = n.antrian_per?.[q] ?? { antri: 0, proses: 0, gagal: 0 };
        const sisa = a.antri + a.proses;
        puncak[q] = sisa === 0 ? 0 : Math.max(puncak[q], sisa);
        const persen = puncak[q] ? Math.round((puncak[q] - sisa) / puncak[q] * 100) : 0;
        // Tanpa truncate: nama model + ukuran base64 kepotong terus, padahal itu
        // yang dibaca kalau ada yang aneh. Biar wrap, tingginya toh cuma 2-3 baris.
        return `<div class="rounded border border-stone-200 p-2">`
            + `<div class="flex items-center gap-2">`
            + `<span class="font-mono font-medium ${WARNA[q]}">${q}</span>`
            + `<span class="text-stone-500">${a.antri} antri${a.proses ? ` · ${a.proses} jalan` : ''}</span>`
            + (a.gagal ? `<span class="text-red-600">${a.gagal} gagal</span>` : '')
            + `<button type="button" data-ulangi-q="${q}" ${a.gagal ? '' : 'disabled'}`
            + ` class="ml-auto rounded border border-stone-300 px-1.5 py-0.5 text-[10px] text-stone-600 disabled:opacity-30">ulangi</button>`
            + `<button type="button" data-batal-q="${q}" ${sisa || a.gagal ? '' : 'disabled'}`
            + ` class="rounded border border-stone-300 px-1.5 py-0.5 text-[10px] text-stone-600 disabled:opacity-30">batal</button>`
            + `</div>`
            // Progress batch berjalan: dihitung dari puncak antrian yang pernah
            // terlihat di sesi ini, karena job yang selesai tidak meninggalkan jejak.
            + `<div class="my-1 h-1 overflow-hidden rounded bg-stone-200">`
            + `<div class="h-full bg-stone-900 transition-all" style="width:${persen}%"></div></div>`
            + `<div class="break-words ${a.sekarang ? WARNA[q] : 'text-stone-300'}">`
            + `${a.sekarang ? escapeHtml(a.sekarang) : 'idle'}</div>`
            // Sebab kegagalan terakhir, bukan cuma jumlahnya — kalau tidak, satu-satunya
            // cara tahu "3 gagal" itu apa adalah buka failed_jobs lewat sqlite.
            + (a.pesan_gagal ? `<div class="mt-1 break-words text-red-600">${escapeHtml(a.pesan_gagal)}</div>` : '')
            + `</div>`;
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
    if (n.paket !== paketAwal) refresh.textContent = `${n.paket - paketAwal} paket baru — lihat paket`;
};

const poll = async () => {
    try { render(await (await fetch('{{ route('pipeline.status') }}', { headers: { 'Accept': 'application/json' } })).json()); }
    catch (e) { progress.textContent = 'status gagal: ' + e.message; }
};

poll();
setInterval(poll, 2000);

// Job yang sedang dikerjakan worker tetap selesai (sudah dipegang di memori) —
// yang dibatalkan cuma yang belum diambil.
const batalkan = async (queue) => {
    const apa = queue ? `antrian ${queue}` : 'SEMUA antrian';
    if (!confirm(`Buang job ${apa} yang masih antri + daftar gagalnya? Job yang sedang dikerjakan tetap selesai.`)) return;
    progress.textContent = 'membatalkan…';
    try {
        render(await (await fetch('{{ url('pipeline/queue') }}' + (queue ? '/' + queue : ''), {
            method: 'DELETE',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
        })).json());
    } catch (e) {
        progress.textContent = 'gagal: ' + e.message;
    }
};

// Kebalikan batalkan(): job gagal dikembalikan ke antriannya. Tanpa konfirmasi —
// mengantrikan ulang itu bisa dibatalkan lagi lewat tombol di sebelahnya.
const ulangi = async (queue) => {
    progress.textContent = 'mengantrikan ulang…';
    try {
        render(await (await fetch('{{ url('pipeline/queue/retry') }}' + (queue ? '/' + queue : ''), {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
        })).json());
    } catch (e) {
        progress.textContent = 'gagal: ' + e.message;
    }
};

// Bulk: centang -> tiga tombol. Checkbox-nya milik form #bulk lewat atribut
// `form=`, jadi JS di sini cuma soal tampilan (bar muncul, angka, pilih semua)
// dan konfirmasi — submit-nya tetap form biasa.
const pilih   = () => [...document.querySelectorAll('[data-pilih]')];
const bulkBar = document.querySelector('[data-bulk]');
const terpilih = () => pilih().filter((c) => c.checked);

const sinkron = () => {
    const n = terpilih().length;
    bulkBar.classList.toggle('hidden', n === 0);
    bulkBar.classList.toggle('flex', n > 0);
    bulkBar.querySelector('[data-terpilih]').textContent = n;
    document.querySelector('[data-semua]').checked = n > 0 && n === pilih().length;
};

const tabel = document.querySelector('table');

tabel.addEventListener('change', (e) => {
    if (e.target.matches('[data-semua]')) pilih().forEach((c) => { c.checked = e.target.checked; });
    if (e.target.matches('[data-pilih],[data-semua]')) sinkron();
});

// Shift-klik = pilih serentetan. Tidak ada bawaannya di HTML: checkbox tidak
// saling kenal, jadi jangkarnya (baris yang diklik terakhir) disimpan sendiri.
// Urutan barisnya ikut `?sort=`, dan `pilih()` membacanya ulang tiap klik —
// yang dipakai urutan yang sedang kelihatan, bukan urutan saat halaman dimuat.
let jangkar = null;
tabel.addEventListener('click', (e) => {
    const c = e.target.closest('[data-pilih]');
    if (!c) return;
    const baris = pilih();
    const i = baris.indexOf(c);
    if (e.shiftKey && jangkar !== null) {
        // Shift di dalam tabel menyorot teks; sorotannya menutupi baris yang
        // baru saja ikut tercentang.
        window.getSelection().removeAllRanges();
        for (let k = Math.min(jangkar, i); k <= Math.max(jangkar, i); k++) baris[k].checked = c.checked;
    }
    jangkar = i;
    sinkron();
});
bulkBar.querySelector('[data-batal-pilih]').addEventListener('click', () => {
    pilih().forEach((c) => { c.checked = false; });
    sinkron();
});
// Tombol yang akibatnya tidak bisa dibatalkan (buang data / bayar model) konfirmasi
// dulu, menyebut jumlah + akibatnya. `data-catatan` menimpa akibat defaultnya.
bulkBar.addEventListener('click', (e) => {
    const b = e.target.closest('[data-confirm]');
    const catatan = b?.dataset.catatan ?? 'Paketnya ikut dihapus.';
    if (b && !confirm(`${b.dataset.confirm} ${terpilih().length} akun terpilih? ${catatan}`)) e.preventDefault();
});

batal.addEventListener('click', () => batalkan(null));
// Tombol per antrian ikut dirender ulang tiap polling, jadi listenernya di induknya.
now.addEventListener('click', (e) => {
    const q = e.target.closest('[data-batal-q]');
    if (q) return batalkan(q.dataset.batalQ);

    const u = e.target.closest('[data-ulangi-q]');
    if (u) ulangi(u.dataset.ulangiQ);
});
</script>
@endsection
