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
        <span data-progress class="text-stone-500">memuat…</span>
    </div>

    {{-- Tiga tahap = tiga antrian. Corong 7 kotak + baris kartu antrian dulu dipisah,
         dan itu yang bikin bingung: "nunggu ai 507" duduk jauh dari "ai 5636 job antri",
         "dibaca ai" mencampur satuan post dengan satuan hasil ekstraksi, dan "jadi paket"
         + "published" menghitung baris yang sama dua kali. Sekarang satu kartu per tahap:
         angkanya, barnya, sisa antrian antriannya, dan tombolnya di kotak yang sama. --}}
    <div data-tahap class="mt-2 grid gap-2 sm:grid-cols-3"></div>
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
const progress = document.querySelector('[data-progress]');
const tahap    = document.querySelector('[data-tahap]');
const log      = document.querySelector('[data-log]');
const escapeHtml = (text) => text.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const batal    = document.querySelector('[data-batal]');
const refresh  = document.querySelector('[data-refresh]');
let paketAwal  = null;

// Warna per antrian, biar kelihatan bagian mana yang jalan tanpa harus dibaca.
const WARNA = { ig: 'text-sky-700', ai: 'text-violet-700', db: 'text-emerald-700', run: 'text-stone-700' };

const N = (v) => Number(v ?? 0).toLocaleString('id-ID');
const pct = (a, b) => (b ? Math.round(a / b * 100) : 0);

// Menulis ulang innerHTML tiap 2 detik membuang seleksi teks dan fokus input —
// halamannya jadi tidak bisa dicopy dan angka worker kereset saat diketik. Dua
// syarat, dua sebab: sama persis = tidak ada yang perlu diubah (kasus idle, paling
// sering); sedang disorot/diketik = tunggu putaran berikutnya.
const nyorot = (el) => {
    const s = getSelection();
    return (s && !s.isCollapsed && el.contains(s.anchorNode)) || el.contains(document.activeElement);
};
const setHtml = (el, html) => {
    if (el.innerHTML === html || nyorot(el)) return;
    el.innerHTML = html;
};

// Tiga tahap = tiga antrian, satu kartu masing-masing. Satuannya dipisah per kartu
// supaya tidak dicampur: `ig`/`ai` menghitung POST (satu postingan IG), `db`
// menghitung PAKET (satu gambar penawaran) — satu carousel bisa jadi beberapa paket,
// jadi angka yang naik di tahap terakhir itu wajar, bukan dobel.
//
// Barnya bukan lagi "sisa antrian dari puncak sesi ini" (angka yang mulai lagi dari
// 0 tiap reload dan tidak berarti apa-apa di halaman yang baru dibuka), tapi corong
// beneran: berapa yang sudah lewat tahap ini dari yang masuk.
const TAHAP = (n) => [
    {
        q: 'ig', judul: 'ambil post dari instagram',
        nilai: N(n.post_diunduh), satuan: 'post terunduh',
        persen: pct(n.terfetch, n.akun),
        baris: [
            `${N(n.terfetch)}/${N(n.akun)} akun sudah di-scrap`,
            n.akun_gagal ? `${N(n.akun_gagal)} akun gagal` : null,
        ],
    },
    {
        q: 'ai', judul: 'baca flyer',
        nilai: `${N(n.post_dibaca)}/${N(n.post_diunduh)}`, satuan: 'post sudah dibaca',
        persen: pct(n.post_dibaca, n.post_diunduh),
        baris: [
            n.post_menunggu ? `${N(n.post_menunggu)} post belum dibaca` : 'semua post sudah dibaca',
            n.post_dikecualikan ? `${N(n.post_dikecualikan)} ditolak — ${alasan(n)}` : null,
        ],
    },
    {
        q: 'db', judul: 'jadi paket',
        nilai: `${N(n.published)}/${N(n.paket)}`, satuan: 'paket tampil publik',
        persen: pct(n.published, n.paket),
        baris: [
            `${N(n.review)} usulan nunggu review`,
            n.hasil_ekstraksi ? `${N(n.hasil_ekstraksi)} hasil ekstraksi belum diimpor` : null,
        ],
    },
];

const alasan = (n) => Object.entries(n.alasan ?? {})
    .map(([k, v]) => `${k.replace(/_/g, ' ')} ${N(v)}`).join(' · ');

const render = (n) => {
    progress.textContent = (n.antrian ? `${N(n.antrian)} job antri` : 'antrian kosong')
        + (n.gagal ? ` · ${N(n.gagal)} job gagal` : '');
    batal.disabled = !n.antrian && !n.gagal;

    setHtml(tahap, TAHAP(n).map(({ q, judul, nilai, satuan, persen, baris }) => {
        const a = n.antrian_per?.[q] ?? { antri: 0, proses: 0, gagal: 0, worker: 0 };
        // Tanpa truncate di baris "sekarang": nama model + ukuran base64 kepotong terus,
        // padahal itu yang dibaca kalau ada yang aneh. Biar wrap, tingginya 2-3 baris.
        return `<div class="rounded border border-stone-200 p-2">`
            + `<div class="flex items-center gap-2">`
            + `<span class="font-mono font-medium ${WARNA[q]}">${q}</span>`
            + `<span class="text-stone-500">${escapeHtml(judul)}</span>`
            // Jumlah worker paralel: setelan, bukan tombol jalankan. Induk queue:work
            // menyusul dalam ≤1 detik; 0 = antrian ini dipause.
            + `<label class="ml-auto flex items-center gap-1 text-[10px] text-stone-400">worker`
            + `<input type="number" data-worker="${q}" value="${a.worker}" min="0" max="${n.max_worker ?? 8}"`
            + ` class="w-11 rounded border border-stone-300 px-1 py-0.5 text-right text-[11px] text-stone-700"></label>`
            + `</div>`
            + `<div class="mt-1 text-lg font-medium leading-none text-stone-900">${escapeHtml(nilai)}</div>`
            + `<div class="text-[10px] text-stone-400">${escapeHtml(satuan)}</div>`
            + `<div class="my-1 h-1 overflow-hidden rounded bg-stone-200">`
            + `<div class="h-full bg-stone-900 transition-all" style="width:${persen}%"></div></div>`
            + baris.filter(Boolean).map((b) =>
                `<div class="truncate text-[10px] text-stone-500" title="${escapeHtml(b)}">${escapeHtml(b)}</div>`).join('')
            + `<div class="mt-1 flex items-center gap-2 text-[11px]">`
            + `<span class="text-stone-500">${N(a.antri)} antri${a.proses ? ` · ${N(a.proses)} jalan` : ''}</span>`
            + (a.gagal ? `<span class="text-red-600">${N(a.gagal)} gagal</span>` : '')
            + `<button type="button" data-ulangi-q="${q}" ${a.gagal ? '' : 'disabled'}`
            + ` class="ml-auto rounded border border-stone-300 px-1.5 py-0.5 text-[10px] text-stone-600 disabled:opacity-30">ulangi</button>`
            + `<button type="button" data-batal-q="${q}" ${a.antri + a.proses || a.gagal ? '' : 'disabled'}`
            + ` class="rounded border border-stone-300 px-1.5 py-0.5 text-[10px] text-stone-600 disabled:opacity-30">batal</button>`
            + `</div>`
            + `<div class="mt-1 break-words text-[11px] ${a.sekarang ? WARNA[q] : 'text-stone-300'}">`
            + `${a.sekarang ? escapeHtml(a.sekarang) : 'idle'}</div>`
            // Sebab kegagalan terakhir, bukan cuma jumlahnya — kalau tidak, satu-satunya
            // cara tahu "3 gagal" itu apa adalah buka failed_jobs lewat sqlite.
            + (a.pesan_gagal ? `<div class="mt-1 break-words text-[11px] text-red-600">${escapeHtml(a.pesan_gagal)}</div>` : '')
            + `</div>`;
    }).join(''));

    if (n.log?.length) {
        const habis = log.scrollTop + log.clientHeight >= log.scrollHeight - 20;
        // Satu baris = satu <div>: triple-click di <pre> menyorot seluruh blok,
        // per-div bikin yang kecopy cuma barisnya.
        setHtml(log, n.log.map((baris) =>
            `<div><span class="text-stone-400">${baris.t}</span> `
            + `<span class="${WARNA[baris.q] ?? 'text-stone-500'}">${baris.q}</span> `
            + escapeHtml(baris.m) + '</div>').join(''));
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

// Jumlah worker paralel per antrian. Setelan yang ditulis ke cache; loop induk
// `queue:work` yang menambah/mengurangi anaknya dalam ≤1 detik — tidak ada restart,
// dan yang dikurangi kena SIGTERM jadi job yang dipegangnya tetap selesai.
const setWorker = async (queue, jumlah) => {
    progress.textContent = `worker ${queue} → ${jumlah}…`;
    try {
        render(await (await fetch('{{ url('pipeline/workers') }}/' + queue, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ jumlah }),
        })).json());
    } catch (e) {
        progress.textContent = 'gagal: ' + e.message;
    }
};

batal.addEventListener('click', () => batalkan(null));
// Tombol + input per antrian ikut dirender ulang tiap polling, jadi listenernya di induknya.
tahap.addEventListener('click', (e) => {
    const q = e.target.closest('[data-batal-q]');
    if (q) return batalkan(q.dataset.batalQ);

    const u = e.target.closest('[data-ulangi-q]');
    if (u) ulangi(u.dataset.ulangiQ);
});
// `change`, bukan `input`: panah spinner ditahan = satu POST per angka yang dilewati.
tahap.addEventListener('change', (e) => {
    const w = e.target.closest('[data-worker]');
    if (w) setWorker(w.dataset.worker, Math.max(0, parseInt(w.value, 10) || 0));
});
</script>
@endsection
