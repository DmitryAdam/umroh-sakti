@extends('layout')
@section('title', $account ? 'Post @'.$account->username : 'Semua post')

@section('content')
{{-- Post apa adanya: gambar + kenapa ditolak / jadi paket. Satu halaman dua ruang
     lingkup — `/posts` seluruh akun, `/accounts/{id}/posts` satu akun; yang beda cuma
     himpunannya. Alat kerja operator (grup `auth`), gambarnya dari storage/raw. --}}
<div class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
    <a href="{{ route('accounts') }}" class="underline">&larr; daftar akun</a>
    @if ($account)
        <strong class="text-sm">{{ '@'.$account->username }}</strong>
        <a href="https://www.instagram.com/{{ $account->username }}" target="_blank" rel="noopener"
           class="text-stone-500 underline">buka di Instagram</a>
        <a href="{{ route('posts', ['filter' => $f]) }}" class="text-stone-500 underline">semua akun</a>
    @else
        <strong class="text-sm">Semua post</strong>
    @endif
    <a href="{{ route('posts.create') }}" class="ml-auto underline">+ tambah post manual</a>
</div>

@if (session('status'))
    <p class="mb-3 rounded border border-emerald-300 bg-emerald-50 px-3 py-2 text-xs text-emerald-900">{{ session('status') }}</p>
@endif

{{-- Jejak fetch terakhir akun ini apa adanya dari storage/pipeline.jsonl: post mana
     yang dilewat dan kenapa (VIDEO tanpa gambar / sudah dikecualikan / sudah ada di
     raw). Ini yang menjawab "kenapa cuma segini yang terunduh" — cuma untuk satu
     akun; gabungannya ada di panel pipeline. --}}
@if ($account)
<details class="mb-3 rounded border border-stone-200 bg-white text-xs">
    <summary class="cursor-pointer px-3 py-2 text-stone-600">
        jejak scrap terakhir
        @if ($jejak)
            <span class="text-stone-400">— {{ $jejak[0]['t'] }}, {{ count($jejak) }} baris</span>
        @endif
    </summary>
    @if ($jejak)
        <pre class="max-h-72 overflow-auto border-t border-stone-100 px-3 py-2 font-mono text-[11px] leading-5 text-stone-700">@foreach ($jejak as $baris)<span class="text-stone-400">{{ $baris['t'] }}</span> {{ $baris['m'] }}
@endforeach</pre>
    @else
        <p class="border-t border-stone-100 px-3 py-2 text-stone-400">
            tidak ada di 800 baris terakhir <code>storage/pipeline.jsonl</code> — scrap lagi untuk melihatnya
        </p>
    @endif
</details>
@endif

{{-- Tab = filter, query string biasa; URL-nya yang sedang dibuka + `?filter=`, jadi
     satu markup melayani kedua ruang lingkup. Angka `terunduh`/`ditolak` di tabel
     akun menunjuk langsung ke tabnya. --}}
<div class="mb-3 flex flex-wrap gap-1 text-xs">
    @foreach ([null => 'semua', 'packages' => 'jadi paket', 'rejected' => 'ditolak', 'pending' => 'menunggu'] as $key => $label)
        <a href="{{ request()->fullUrlWithQuery(['filter' => $key, 'page' => null]) }}"
           class="rounded border px-2 py-1 {{ $f === $key ? 'border-stone-900 bg-stone-900 text-white' : 'border-stone-300 text-stone-600 hover:bg-stone-50' }}">
            {{ $label }} <span class="tabular-nums opacity-70">{{ $jumlah[$key] }}</span>
        </a>
    @endforeach
</div>

{{-- Form bulk KOSONG dan di luar tabel: checkbox tiap baris menunjuk ke sini lewat
     `form="bulk"` (HTML5), jadi tidak ada <form> yang membungkus tabel dan
     bertabrakan dengan form per-baris di kolom aksi. Pola yang sama dipakai di
     daftar akun. --}}
<form id="bulk" method="POST" action="{{ route('posts.bulk') }}">@csrf</form>

<div data-bulk class="mb-2 hidden flex-wrap items-center gap-2 rounded border border-stone-300 bg-stone-50 px-3 py-2 text-xs">
    <span><strong data-terpilih>0</strong> post dipilih</span>
    <button type="button" data-batal-pilih class="text-stone-500 underline">batal pilih</button>
    <div class="ml-auto flex flex-wrap items-center gap-2">
        <button form="bulk" name="action" value="extract" data-confirm="Baca ulang"
                data-catatan="Blok penolakan dilepas, paket lamanya dibikin ulang, dan modelnya dibayar lagi."
                class="rounded border border-stone-300 bg-white px-2 py-1 hover:bg-stone-100">Baca ulang AI</button>
        {{-- Vonis manusia: baru di sini file rawnya dibuang. Import sengaja
             menahannya untuk `bukan_paket` — itu vonis mesin dan paling sering salah. --}}
        <button form="bulk" name="action" value="block" data-confirm="Blokir"
                data-catatan="Gambar + hasil ekstraksinya dibuang permanen; barisnya di excluded_posts tinggal sebagai penahan fetch."
                class="rounded border border-red-300 bg-white px-2 py-1 text-red-700 hover:bg-red-50">Blokir terpilih</button>
        {{-- Kebalikannya: barisnya `excluded_posts` dibuang, tidak ada file yang
             disentuh. Gambarnya baru ada lagi setelah scrap berikutnya. --}}
        <button form="bulk" name="action" value="unblock"
                title="buang barisnya di excluded_posts; gambarnya baru ada setelah scrap berikutnya"
                class="rounded border border-stone-300 bg-white px-2 py-1 hover:bg-stone-100">Hapus blokir</button>
    </div>
</div>

{{-- Tabel, bukan grid kartu: yang dibaca operator itu caption + alasan berdampingan,
     dan centang per baris cuma masuk akal kalau barisnya sebaris. --}}
<div class="overflow-x-auto rounded border border-stone-200 bg-white">
    <table class="w-full text-xs">
        <thead class="border-b border-stone-200 bg-stone-50 text-left text-stone-500">
            <tr>
                <th class="px-2 py-1">
                    <input type="checkbox" data-semua title="pilih semua baris di halaman ini" class="align-middle">
                </th>
                <th class="px-2 py-1 font-medium">gambar</th>
                @unless ($account)
                    <th class="px-2 py-1 font-medium">akun</th>
                @endunless
                <th class="px-2 py-1 font-medium">status</th>
                <th class="px-2 py-1 font-medium">caption</th>
                <th class="whitespace-nowrap px-2 py-1 font-medium">tanggal</th>
                <th class="px-2 py-1"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
            @forelse ($posts as $post)
                <tr class="align-top hover:bg-stone-50">
                    <td class="px-2 py-1.5">
                        <input type="checkbox" form="bulk" name="media[]" value="{{ $post['media_id'] }}"
                               data-pilih class="align-middle">
                    </td>
                    <td class="px-2 py-1.5">
                        @if ($post['images'])
                            {{-- Klik = gambar aslinya di tab baru. --}}
                            <div class="flex gap-1">
                                @foreach ($post['images'] as $src)
                                    <a href="{{ $src }}" target="_blank" rel="noopener">
                                        <img src="{{ $src }}" alt="slide {{ $loop->index }}" loading="lazy"
                                             class="h-16 w-16 rounded border border-stone-100 bg-stone-50 object-cover">
                                    </a>
                                @endforeach
                            </div>
                        @elseif ($post['alasan'])
                            <span class="text-stone-400">file raw sudah dihapus</span>
                        @else
                            <span class="text-stone-400">tidak ada gambar</span>
                        @endif
                    </td>
                    @unless ($account)
                        <td class="whitespace-nowrap px-2 py-1.5">
                            @if ($id = $akunId[$post['account']] ?? null)
                                <a href="{{ route('accounts.posts', ['account' => $id, 'filter' => $f]) }}"
                                   class="underline decoration-stone-300 hover:decoration-stone-900">{{ '@'.$post['account'] }}</a>
                            @else
                                <span class="text-stone-400">{{ $post['account'] ? '@'.$post['account'] : '—' }}</span>
                            @endif
                        </td>
                    @endunless
                    <td class="whitespace-nowrap px-2 py-1.5">
                        @if ($post['alasan'])
                            <span class="rounded bg-red-100 px-1.5 py-0.5 text-red-900" title="alasan di excluded_posts">{{ $post['alasan'] }}</span>
                        @endif
                        @if (! $post['alasan'] && $post['paket']->isEmpty())
                            <span class="rounded bg-amber-100 px-1.5 py-0.5 text-amber-900">menunggu</span>
                        @endif
                        {{-- Satu select per paket (satu post bisa jadi beberapa slide).
                             Perubahannya langsung disimpan (PATCH) tanpa tombol simpan
                             dan tanpa reload — sama seperti bar aksi kartu di `/`. --}}
                        @foreach ($post['paket'] as $p)
                            <select data-status="{{ route('package.status', $p) }}"
                                    title="Status publikasi paket #{{ $p->id }} — cuma `published` yang tampil ke pengunjung"
                                    class="mt-0.5 block w-full cursor-pointer rounded border border-stone-300 bg-white px-1 py-0.5 text-[11px]">
                                @foreach (App\Models\Package::STATUSES as $status)
                                    <option value="{{ $status }}" @selected($p->status === $status)>#{{ $p->id }} {{ $status }}</option>
                                @endforeach
                            </select>
                        @endforeach
                    </td>
                    <td class="px-2 py-1.5">
                        @if ($post['caption'])
                            {{-- Klik = caption penuh. <details> bawaan, tanpa JS: teksnya
                                 dirender sekali, yang berubah cuma clamp-nya saat open. --}}
                            <details class="group max-w-xl">
                                <summary class="line-clamp-2 block cursor-pointer list-none whitespace-pre-line text-stone-600 group-open:line-clamp-none">{{ $post['caption'] }}</summary>
                            </details>
                        @endif
                        <div class="mt-1 flex flex-wrap items-center gap-2 text-[11px] text-stone-400">
                            @if ($post['permalink'])
                                <a href="{{ $post['permalink'] }}" target="_blank" rel="noopener" class="underline">post asli</a>
                            @endif
                            @foreach ($post['paket'] as $p)
                                <a href="{{ route('package.show', $p) }}" class="underline">paket #{{ $p->id }}</a>
                            @endforeach
                            <span class="font-mono">{{ $post['media_id'] }}</span>
                        </div>
                    </td>
                    <td class="whitespace-nowrap px-2 py-1.5 text-stone-500">
                        @if ($post['timestamp'])
                            {{ \Illuminate\Support\Carbon::parse($post['timestamp'])->format('d M Y') }}
                        @endif
                    </td>
                    <td class="px-2 py-1.5">
                        {{-- Baca ulang: blok `excluded_posts` dilepas, hasil bacaan lama
                             dibuang, lalu gambar + caption dikirim lagi ke AI. Status
                             hasil review manusia untuk post ini ikut hilang. --}}
                        <form method="POST" action="{{ route('posts.reextract', $post['media_id']) }}"
                              onsubmit="return confirm('Baca ulang post ini pakai AI? Blok penolakan dilepas dan paket lamanya dibikin ulang.')">
                            @csrf
                            <button type="submit" class="whitespace-nowrap rounded border border-stone-300 px-2 py-1 text-stone-600 hover:bg-stone-50"
                                    title="lepas blok, kirim gambar + caption ke AI lagi, lalu bikin paketnya">baca ulang AI</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-2 py-4 text-center text-stone-500">Belum ada post di kelompok ini.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- Prev/next saja, bukan `$posts->links()`: paginator bawaan Laravel merender kelas
     Tailwind v3 yang tidak ada di build v4 kita. --}}
@if ($posts->hasPages())
    <div class="mt-2 flex items-center gap-3 text-xs text-stone-500">
        @if ($posts->previousPageUrl())
            <a href="{{ $posts->previousPageUrl() }}" class="underline">&larr; sebelumnya</a>
        @endif
        <span>halaman {{ $posts->currentPage() }} dari {{ $posts->lastPage() }} — {{ $posts->total() }} post</span>
        @if ($posts->nextPageUrl())
            <a href="{{ $posts->nextPageUrl() }}" class="underline">berikutnya &rarr;</a>
        @endif
    </div>
@endif

<script>
// Centang -> bar aksi. Checkbox-nya milik form #bulk lewat atribut `form=`, jadi
// JS di sini cuma tampilan + konfirmasi; submitnya form biasa.
const pilih = () => [...document.querySelectorAll('[data-pilih]')];
const bulkBar = document.querySelector('[data-bulk]');
const terpilih = () => pilih().filter((c) => c.checked);
const tabel = document.querySelector('table');

const sinkron = () => {
    const n = terpilih().length;
    bulkBar.classList.toggle('hidden', n === 0);
    bulkBar.classList.toggle('flex', n > 0);
    bulkBar.querySelector('[data-terpilih]').textContent = n;
    document.querySelector('[data-semua]').checked = n > 0 && n === pilih().length;
};

tabel.addEventListener('change', (e) => {
    if (e.target.matches('[data-semua]')) pilih().forEach((c) => { c.checked = e.target.checked; });
    if (e.target.matches('[data-pilih],[data-semua]')) sinkron();
});

// Shift-klik = pilih serentetan; tidak ada bawaannya, jangkarnya disimpan sendiri.
let jangkar = null;
tabel.addEventListener('click', (e) => {
    const c = e.target.closest('[data-pilih]');
    if (!c) return;
    const baris = pilih();
    const i = baris.indexOf(c);
    if (e.shiftKey && jangkar !== null) {
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

// Yang tidak bisa dibatalkan (bayar model / buang file) konfirmasi dulu.
bulkBar.addEventListener('click', (e) => {
    const b = e.target.closest('[data-confirm]');
    if (b && !confirm(`${b.dataset.confirm} ${terpilih().length} post terpilih? ${b.dataset.catatan}`)) e.preventDefault();
});

// Ganti status paket = satu PATCH tanpa reload, sama seperti bar aksi kartu di `/`:
// halaman yang dimuat ulang membuang baris yang baru dipublish dari tab yang sedang
// dipakai, dan sisanya bergeser di tengah kerja.
const csrf = document.querySelector('meta[name=csrf-token]').content;
tabel.addEventListener('change', async (e) => {
    const select = e.target.closest('[data-status]');
    if (!select) return;

    select.disabled = true;
    try {
        const res = await fetch(select.dataset.status, {
            method: 'PATCH',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ status: select.value }),
        });
        if (!res.ok) throw new Error(res.status);
        select.classList.remove('border-red-500');
    } catch (err) {
        select.classList.add('border-red-500');
        select.title = 'gagal simpan: ' + err.message;
    }
    select.disabled = false;
});
</script>
@endsection
