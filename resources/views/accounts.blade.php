@extends('layout')
@section('title', 'Akun Sumber')

@section('content')
<div class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-1 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900">
    <strong>Daftar akun (lokal).</strong>
    <span>Akun yang dimasukkan di sini langsung <code>approved</code> — ikut di putaran pipeline berikutnya.</span>
    <a href="{{ route('search') }}" class="ml-auto underline">ke daftar paket</a>
</div>

{{-- Panel pipeline: tombolnya cuma melempar job, `php artisan queue:work` yang kerja. --}}
<div class="mb-3 rounded border border-stone-200 bg-white px-3 py-2 text-xs">
    <div class="flex flex-wrap items-center gap-3">
        <button type="button" data-pipeline
                class="rounded bg-stone-900 px-2 py-1 text-xs text-white disabled:opacity-40">Jalankan pipeline</button>
        <div class="h-1.5 w-40 overflow-hidden rounded bg-stone-200">
            <div data-bar class="h-full w-0 bg-stone-900 transition-all"></div>
        </div>
        <span data-progress class="text-stone-500">memuat…</span>
        <a href="{{ route('search') }}" data-refresh class="ml-auto hidden text-stone-900 underline">lihat paket</a>
    </div>

    {{-- Corong: berapa yang masuk tiap tahap, dari akun sampai published. --}}
    <div data-corong class="mt-2 grid grid-cols-2 gap-px overflow-hidden rounded border border-stone-200 bg-stone-200 sm:grid-cols-4 lg:grid-cols-7"></div>

    {{-- Satu baris per antrian: yang sedang dikerjakan masing-masing worker. --}}
    <div data-now class="mt-2 grid gap-1 sm:grid-cols-3"></div>

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

<form method="POST" action="{{ route('accounts.store') }}" class="mb-4 rounded border border-stone-200 bg-white p-3 text-xs">
    @csrf
    <label for="usernames" class="block text-stone-600">
        Username, <code>@handle</code>, atau URL Instagram — satu per baris. Baris yang diawali <code>#</code> dilewat.
    </label>
    <textarea id="usernames" name="usernames" rows="4" required
              placeholder="hamdantour&#10;https://www.instagram.com/sunnatravel.id"
              class="mt-2 w-full rounded border-stone-300 p-2 font-mono text-xs"></textarea>
    <button class="mt-2 rounded bg-stone-900 px-3 py-1 text-xs text-white">Tambah akun</button>
</form>

<div class="mb-2 flex flex-wrap items-center gap-3 text-xs text-stone-600">
    <span>
        {{ $accounts->count() }} akun ·
        {{ $accounts->whereNull('last_fetched_at')->count() }} belum pernah di-scrap
        @if ($gagal = $accounts->whereNotNull('last_error')->count())
            · <span class="text-red-700">{{ $gagal }} gagal di percobaan terakhir</span>
        @endif
    </span>
    @php ($approved = $accounts->where('status', 'approved'))
    @if ($approved->isNotEmpty())
        @if ($belum = $approved->whereNull('last_fetched_at')->count())
            <form method="POST" action="{{ route('accounts.fetch_all') }}" class="ml-auto"
                  onsubmit="return confirm('Antrikan fetch untuk {{ $belum }} akun yang belum pernah di-scrap?')">
                @csrf
                <input type="hidden" name="baru" value="1">
                <button class="rounded border border-stone-300 px-2 py-1 text-xs hover:bg-stone-100">Scrap yang belum pernah ({{ $belum }})</button>
            </form>
        @endif
        <form method="POST" action="{{ route('accounts.fetch_all') }}" class="{{ $belum ? '' : 'ml-auto' }}"
              onsubmit="return confirm('Antrikan fetch untuk {{ $approved->count() }} akun approved?')">
            @csrf
            <button class="rounded bg-stone-900 px-2 py-1 text-xs text-white">Scrap semua</button>
        </form>
    @endif
</div>

{{-- Kartu, bukan tabel: 197 baris kolomnya cuma dua yang panjang, sisanya angka —
     4-6 kolom bikin daftarnya muat di satu layar. --}}
<div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6">
    @forelse ($accounts as $account)
        <div class="flex flex-col gap-1 rounded border border-stone-200 bg-white p-2 text-xs">
            <div class="flex items-start gap-1">
                <a href="https://www.instagram.com/{{ $account->username }}" target="_blank" rel="noopener"
                   class="truncate font-medium underline">{{ '@'.$account->username }}</a>
                <form method="POST" action="{{ route('accounts.destroy', $account) }}" class="ml-auto"
                      onsubmit="return confirm('Hapus {{ '@'.$account->username }} dari daftar akun?')">
                    @csrf @method('DELETE')
                    <button title="hapus dari daftar" class="rounded px-1 leading-none text-stone-400 hover:bg-red-600 hover:text-white">&times;</button>
                </form>
            </div>

            <div class="text-stone-500">
                @if ($account->last_fetched_at)
                    <span title="{{ $account->last_fetched_at }}">{{ $account->last_fetched_at->diffForHumans() }}</span>
                @else
                    <span class="rounded bg-amber-100 px-1.5 py-0.5 text-amber-900">belum pernah</span>
                @endif
                @if ($account->status !== 'approved') &middot; {{ $account->status }} @endif
            </div>

            {{-- Percobaan terakhir gagal. Timestamp di atas tetap yang terakhir
                 BERHASIL, jadi dua-duanya perlu kelihatan sekaligus. --}}
            @if ($account->last_error)
                <div class="truncate text-red-700" title="{{ $account->last_error }}">gagal: {{ $account->last_error }}</div>
            @endif

            <div class="mt-auto flex items-center gap-2 pt-1 text-stone-500">
                <span title="post">{{ $posts[$account->username] ?? 0 }}p</span>
                <span title="paket" class="text-stone-700">{{ $packages[$account->username] ?? 0 }}pkt</span>
                <span title="dikecualikan" class="text-stone-400">{{ $dikecualikan[$account->username] ?? 0 }}x</span>
                <form method="POST" action="{{ route('accounts.fetch', $account) }}" class="ml-auto">
                    @csrf
                    <button class="rounded border border-stone-300 px-2 py-0.5 hover:bg-stone-100">scrap</button>
                </form>
            </div>
        </div>
    @empty
        <p class="col-span-full rounded border border-dashed border-stone-300 p-4 text-xs text-stone-500">
            Belum ada akun. Tambah di atas, atau <code>php artisan packages:crawl accounts.txt</code>.
        </p>
    @endforelse
</div>

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
const runButton = document.querySelector('[data-pipeline]');
const refresh  = document.querySelector('[data-refresh]');
let paketAwal  = null;

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
    runButton.disabled = n.jalan;

    corong.innerHTML = CORONG(n).map(({ label, nilai, sub }) =>
        `<div class="bg-white px-2 py-1.5">`
        + `<div class="text-[10px] uppercase tracking-wide text-stone-400">${escapeHtml(label)}</div>`
        + `<div class="font-medium text-stone-900">${escapeHtml(String(nilai))}</div>`
        + `<div class="truncate text-[10px] text-stone-400" title="${escapeHtml(sub)}">${escapeHtml(sub)}</div>`
        + `</div>`).join('');

    // Tiga antrian jalan sendiri-sendiri: ig fetch, ai konversi, db import.
    now.innerHTML = ['ig', 'ai', 'db'].map((q) => {
        const pesan = n.sekarang?.[q];
        // Tanpa truncate: nama model + ukuran base64 kepotong terus, padahal itu
        // yang dibaca kalau ada yang aneh. Biar wrap, tingginya toh cuma 2-3 baris.
        return `<div class="break-words ${pesan ? WARNA[q] : 'text-stone-300'}">`
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
    if (n.paket !== paketAwal) refresh.textContent = `${n.paket - paketAwal} paket baru — lihat paket`;
};

const poll = async () => {
    try { render(await (await fetch('{{ route('pipeline.status') }}', { headers: { 'Accept': 'application/json' } })).json()); }
    catch (e) { progress.textContent = 'status gagal: ' + e.message; }
};

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
</script>
@endsection
