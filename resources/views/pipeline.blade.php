@extends('layout')
@section('title', 'Pipeline')

@section('content')
{{-- Panel pipeline, halamannya sendiri. Dulu numpang di atas /accounts: tabel akun
     mendorongnya ke luar layar dan jejaknya harus dilipat supaya daftar akunnya
     kelihatan. Dipisah, jejaknya boleh terbuka terus.
     Halaman ini cuma pemantau + tombol batal/ulangi — yang mengantrikan job itu
     tombol scrap di /accounts (atau `packages:crawl`), yang mengerjakan `queue:work`. --}}
<div class="mb-3 flex flex-wrap items-center gap-3 text-xs text-stone-600">
    <a href="{{ route('accounts') }}" class="underline">&larr; akun sumber</a>
    <a href="{{ route('search') }}" data-refresh class="hidden text-stone-900 underline">lihat paket</a>
</div>

<div class="mb-3 rounded border border-stone-200 bg-white px-3 py-2 text-xs">
    <div class="flex flex-wrap items-center gap-3">
        <button type="button" data-batal
                class="rounded border border-stone-300 px-2 py-1 text-xs text-stone-700 disabled:opacity-40">Batalkan semua job</button>
        {{-- Beda dari "batalkan": itu membuang job, ini menghentikan yang mengerjakan.
             Antriannya utuh dan lanjut sendiri begitu `queue:work` dinyalakan lagi. --}}
        <button type="button" data-stop
                class="rounded border border-red-300 px-2 py-1 text-xs text-red-700">Stop worker</button>
        <div class="h-1.5 w-40 overflow-hidden rounded bg-stone-200">
            <div data-bar class="h-full w-0 bg-stone-900 transition-all"></div>
        </div>
        <span data-progress class="text-stone-500">memuat…</span>
    </div>

    {{-- Corong: berapa yang masuk tiap tahap, dari akun sampai published. --}}
    <div data-corong class="mt-2 grid grid-cols-2 gap-px overflow-hidden rounded border border-stone-200 bg-stone-200 sm:grid-cols-4 lg:grid-cols-7"></div>

    {{-- Satu kartu per antrian: sisa antrian + progress batch berjalan + apa yang
         sedang dikerjakan workernya, plus tombol batal khusus antrian itu. --}}
    <div data-now class="mt-2 grid gap-2 sm:grid-cols-3"></div>
</div>

{{-- Jejak detail, langsung dari stdout probe.php. Tidak dilipat lagi: itu isi
     halaman ini, dan yang membukanya memang mau melihat barisnya jalan. --}}
<div class="rounded border border-stone-200 bg-white px-3 py-2">
    <p class="mb-1 text-[11px] text-stone-500">jejak detail — stdout probe.php apa adanya</p>
    <pre data-log class="max-h-[60vh] overflow-auto whitespace-pre-wrap break-all rounded bg-stone-50 p-2 font-mono text-[10px] leading-tight text-stone-600"></pre>
</div>

{{-- Tidak ada tombol lempar job di sini: semuanya polling angka dari server, dan
     tidak ada state progress yang disimpan — dihitung ulang dari raw/extracted/DB. --}}
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

// Stop worker: job yang sedang dipegang tetap diselesaikan (SIGTERM, bukan kill),
// sisanya menunggu di antrian sampai `queue:work` dinyalakan lagi dari terminal.
document.querySelector('[data-stop]').addEventListener('click', async () => {
    if (!confirm('Hentikan worker? Job yang sedang jalan diselesaikan dulu, sisanya menunggu di antrian.\n\nMenyalakannya lagi cuma bisa dari terminal: php artisan queue:work')) return;
    progress.textContent = 'menghentikan worker…';
    try {
        render(await (await fetch('{{ route('pipeline.stop') }}', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
        })).json());
        progress.textContent = 'stop diminta — tunggu job yang sedang jalan selesai (lihat jejak di bawah)';
    } catch (e) {
        progress.textContent = 'gagal: ' + e.message;
    }
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
