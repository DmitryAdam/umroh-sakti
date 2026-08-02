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
    <a href="{{ route('suggestions') }}" class="ml-auto underline">+ usulkan post</a>
</div>

@include('partials.flash')

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
    @foreach ([null => 'semua', 'packages' => 'jadi paket', 'rejected' => 'ditolak', 'pending' => 'menunggu', 'suggestions' => 'usulan'] as $key => $label)
        <a href="{{ request()->fullUrlWithQuery(['filter' => $key, 'page' => null]) }}"
           class="rounded border px-2 py-1 {{ $f === $key ? 'border-stone-900 bg-stone-900 text-white' : 'border-stone-300 text-stone-600 hover:bg-stone-50' }}">
            {{ $label }} <span class="tabular-nums opacity-70">{{ $jumlah[$key] }}</span>
        </a>
    @endforeach
    {{-- Gambar mati secara default: 60 baris x thumbnail tetap puluhan request, dan
         yang dibaca operator itu caption + alasan. Pilihannya preferensi (localStorage),
         bukan query string — link yang dibagikan tidak boleh memaksa tata letak orang
         lain, aturan yang sama dengan kartu/daftar di `/`. --}}
    <label class="ml-auto flex cursor-pointer items-center gap-1 text-stone-500"
           title="thumbnail baru diunduh kalau ini dicentang">
        <input type="checkbox" data-gambar class="align-middle"> tampilkan gambar
    </label>
</div>

{{-- Saklar auto-approve: usulan berikutnya lewat jalur "setujui & baca" begitu masuk.
     Form biasa (tombolnya mengirim kebalikan keadaan sekarang), bukan checkbox
     auto-submit — ini setelan di server yang berlaku untuk semua orang, jadi
     tekanannya harus disengaja. Cuma di tab usulan: di tab lain tidak ada artinya. --}}
@if ($f === 'suggestions')
    <form method="POST" action="{{ route('posts.auto-approve') }}"
          class="mb-3 flex flex-wrap items-center gap-2 rounded border px-3 py-2 text-xs {{ $autoApprove ? 'border-emerald-300 bg-emerald-50' : 'border-stone-300 bg-stone-50' }}">
        @csrf
        <input type="hidden" name="on" value="{{ $autoApprove ? 0 : 1 }}">
        <span><strong>Auto-approve {{ $autoApprove ? 'nyala' : 'mati' }}</strong> —
            {{ $autoApprove
                ? 'usulan baru langsung disetujui, akunnya ikut approved, dan gambarnya dibaca AI.'
                : 'usulan baru menunggu tombol setujui di halaman ini.' }}
            Gerbang vision dan syarat tanggal/harga tetap berlaku.</span>
        <button class="ml-auto rounded border border-stone-300 bg-white px-2 py-1 hover:bg-stone-100"
                onclick="return {{ $autoApprove ? 'true' : "confirm('Nyalakan auto-approve? Usulan siapa pun langsung dibaca AI tanpa dilihat dulu.')" }}">
            {{ $autoApprove ? 'Matikan' : 'Nyalakan' }}
        </button>
    </form>
@endif

{{-- Form bulk KOSONG dan di luar tabel: checkbox tiap baris menunjuk ke sini lewat
     `form="bulk"` (HTML5), jadi tidak ada <form> yang membungkus tabel dan
     bertabrakan dengan form per-baris di kolom aksi. Pola yang sama dipakai di
     daftar akun. --}}
<form id="bulk" method="POST" action="{{ route('posts.bulk') }}">@csrf</form>

<div data-bulk class="mb-2 hidden flex-wrap items-center gap-2 rounded border border-stone-300 bg-stone-50 px-3 py-2 text-xs">
    <span><strong data-terpilih>0</strong> post dipilih</span>
    <button type="button" data-batal-pilih class="text-stone-500 underline">batal pilih</button>
    <div class="ml-auto flex flex-wrap items-center gap-2">
        {{-- Aksinya satu: `extract` = bacaUlang(), dan itu memang yang menyetujui
             usulan (penanda `_suggested_by` dibuang + akunnya ikut approved). Yang
             beda cuma katanya, supaya di tab usulan tombolnya bernama sesuai
             akibatnya — sama dengan tombol per barisnya. --}}
        <button form="bulk" name="action" value="extract" data-confirm="{{ $f === 'suggestions' ? 'Setujui & baca' : 'Baca ulang' }}"
                data-catatan="{{ $f === 'suggestions' ? 'Akun pengusulnya ikut disetujui dan postnya dibaca AI.' : 'Blok penolakan dilepas, paket lamanya dibikin ulang, dan modelnya dibayar lagi.' }}"
                class="rounded border border-stone-300 bg-white px-2 py-1 hover:bg-stone-100">{{ $f === 'suggestions' ? 'Setujui & baca' : 'Baca ulang AI' }}</button>
        {{-- Vonis manusia: baru di sini file rawnya dibuang. Import sengaja
             menahannya untuk `bukan_paket` — itu vonis mesin dan paling sering salah. --}}
        <button form="bulk" name="action" value="block" data-confirm="Blokir"
                data-catatan="Gambar + hasil ekstraksinya dibuang permanen; barisnya di excluded_posts tinggal sebagai penahan fetch."
                class="rounded border border-red-300 bg-white px-2 py-1 text-red-700 hover:bg-red-50">Blokir terpilih</button>
        {{-- Di tab usulan tidak ada yang diblokir, jadi "hapus blokir" di situ cuma
             tombol yang tidak pernah mengerjakan apa pun. Yang masuk akal di sana
             kebalikan dari setujui: buang kirimannya. Bukan blokir — kiriman yang
             salah permalink/tanggal harus boleh dikirim ulang, dan barisnya
             `excluded_posts` justru yang menahannya. --}}
        @if ($f === 'suggestions')
            <button form="bulk" name="action" value="delete" data-confirm="Hapus usulan"
                    data-catatan="Gambar + hasil ekstraksinya dibuang. Postnya TIDAK diblokir — boleh dikirim ulang."
                    class="rounded border border-red-300 bg-white px-2 py-1 text-red-700 hover:bg-red-50">Hapus terpilih</button>
        @else
            {{-- Kebalikan blokir: barisnya `excluded_posts` dibuang, tidak ada file
                 yang disentuh. Gambarnya baru ada lagi setelah scrap berikutnya. --}}
            <button form="bulk" name="action" value="unblock"
                    title="buang barisnya di excluded_posts; gambarnya baru ada setelah scrap berikutnya"
                    class="rounded border border-stone-300 bg-white px-2 py-1 hover:bg-stone-100">Hapus blokir</button>
        @endif
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
                            {{-- Slide pertama saja; sisanya di balik <details> (gambar di
                                 elemen tertutup tidak di-fetch sampai dibuka). `src`-nya
                                 sengaja kosong — JS di bawah yang mengisinya dari
                                 `data-src` kalau centang "tampilkan gambar" menyala.
                                 `display:none` tidak cukup: browser tetap mengunduhnya. --}}
                            <div data-thumb class="flex gap-1">
                                <a href="{{ $post['images'][0] }}" target="_blank" rel="noopener">
                                    <img data-src="{{ $post['images'][0] }}" alt="slide 0" loading="lazy"
                                         class="h-10 w-10 rounded border border-stone-100 bg-stone-50 object-cover">
                                </a>
                            </div>
                            @if (count($post['images']) > 1)
                                <details data-thumb class="mt-1">
                                    <summary class="cursor-pointer text-stone-500">+{{ count($post['images']) - 1 }}</summary>
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        @foreach (array_slice($post['images'], 1) as $i => $src)
                                            <a href="{{ $src }}" target="_blank" rel="noopener">
                                                <img data-src="{{ $src }}" alt="slide {{ $i + 1 }}" loading="lazy"
                                                     class="h-10 w-10 rounded border border-stone-100 bg-stone-50 object-cover">
                                            </a>
                                        @endforeach
                                    </div>
                                </details>
                            @endif
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
                        @include('partials.post-status')
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
                                {{-- Tanpa `block`: utility itu dirender SESUDAH `line-clamp-2`
                                     di bundel, jadi `display:block` menimpa `-webkit-box`
                                     dan clamp-nya mati — captionnya tampil penuh. --}}
                                <summary class="line-clamp-2 cursor-pointer list-none whitespace-pre-line text-stone-600 group-open:line-clamp-none">{{ $post['caption'] }}</summary>
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
                        {{-- Asal post: null = hasil scrap, terisi = dimasukkan tangan. --}}
                        <div class="text-xs" title="{{ $post['oleh'] ? 'dimasukkan tangan oleh '.$post['oleh'] : 'hasil scrap otomatis' }}">
                            {{ $post['oleh'] ?? 'scrap' }}
                        </div>
                    </td>
                    <td class="px-2 py-1.5">
                        {{-- Baca ulang: blok `excluded_posts` dilepas, hasil bacaan lama
                             dibuang, lalu gambar + caption dikirim lagi ke AI. Status
                             hasil review manusia untuk post ini ikut hilang. --}}
                        <form method="POST" action="{{ route('posts.reextract', $post['media_id']) }}"
                              onsubmit="return confirm('{{ $post['usulan'] ? 'Setujui usulan ini dan baca pakai AI?' : 'Baca ulang post ini pakai AI? Blok penolakan dilepas dan paket lamanya dibikin ulang.' }}')">
                            @csrf
                            <button type="submit" class="whitespace-nowrap rounded border border-stone-300 px-2 py-1 text-stone-600 hover:bg-stone-50"
                                    title="lepas blok, kirim gambar + caption ke AI lagi, lalu bikin paketnya">{{ $post['usulan'] ? 'setujui & baca' : 'baca ulang AI' }}</button>
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

{{-- Gambar mati = benar-benar tidak diunduh, bukan cuma disembunyikan: `img`
     ber-`loading="lazy"` di dalam elemen `display:none` tidak pernah di-fetch
     browser. Jadi saklarnya cukup satu kelas di <body>, tanpa data-src dan tanpa
     mengubah markupnya. Nilainya di localStorage — preferensi, bukan filter. --}}
<style>body.tanpa-gambar [data-thumb] { display: none }</style>
<script>
    (() => {
        const kotak = document.querySelector('[data-gambar]')
        const pasang = (on) => document.body.classList.toggle('tanpa-gambar', !on)

        kotak.checked = localStorage.getItem('posts-gambar') === '1'
        pasang(kotak.checked)

        kotak.addEventListener('change', () => {
            localStorage.setItem('posts-gambar', kotak.checked ? '1' : '0')
            pasang(kotak.checked)
        })
    })()
</script>

{{-- Centang -> bar aksi, dan select status -> PATCH tanpa reload. Dua partial
     yang sama dipakai /accounts dan `/`. --}}
@include('partials.bulk-select', ['satuan' => 'post', 'catatan' => ''])
@include('partials.status-patch')

{{-- Centang gambar. IIFE + pilihannya di localStorage: halaman ini sudah punya dua
     <script> lain (bulk-select, status-patch), jadi nama di scope global bisa kembar. --}}
<script>
(() => {
    const kotak = document.querySelector('[data-gambar]');
    if (! kotak) return;

    const pasang = on => document.querySelectorAll('img[data-src]').forEach(img => {
        // Kalau on: isi sekali (browser yang menunda fetch lewat loading=lazy).
        // Kalau off: buang src — yang terlanjur diunduh tetap ada di cache HTTP.
        if (on) { if (! img.getAttribute('src')) img.src = img.dataset.src; }
        else img.removeAttribute('src');
    });

    kotak.checked = localStorage.getItem('posts.gambar') === '1';
    pasang(kotak.checked);
    kotak.addEventListener('change', () => {
        localStorage.setItem('posts.gambar', kotak.checked ? '1' : '0');
        pasang(kotak.checked);
    });
})();
</script>
@endsection
