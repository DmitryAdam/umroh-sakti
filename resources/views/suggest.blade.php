@extends('layout')
@section('title', 'Usulan')

@section('content')
{{-- Satu halaman, satu perilaku, dua peran. Admin TIDAK punya jalur cepat di sini:
     kirimannya jadi usulan juga, dan approval-nya satu tombol di /posts tab usulan.
     Cabang `if ($admin)` di markup ini pernah ada tiga — tiga kesempatan supaya dua
     peran diam-diam berperilaku beda atas formulir yang sama. --}}
<div class="mx-auto grid max-w-2xl gap-4">
    <div>
        <h1 class="text-lg font-semibold tracking-tight">Usulan saya</h1>
        <p class="mt-1 text-xs leading-5 text-muted-foreground">
            Kiriman disimpan dan menunggu disetujui admin. Belum ada yang di-scrap dan belum ada
            yang dibaca AI sampai itu — jadi tidak ada paket yang lahir dari sini tanpa dilihat
            orang dulu.
        </p>
    </div>

    @include('partials.flash')

    {{-- Post manual: jalan masuk untuk yang TIDAK bisa dijangkau fetch (pinned lama,
         di luar --limit, akun yang belum pernah di-scrap). Sesudah disetujui tidak ada
         jalur khusus — gerbang vision tetap menilai, hasilnya tetap draft/review. --}}
    <x-ui.card class="p-4">
        <form method="POST" action="{{ route('posts.store') }}" enctype="multipart/form-data" class="grid gap-4">
            @csrf

            <div>
                <h2 class="text-sm font-medium">Post yang tidak terjangkau fetch</h2>
                <p class="mt-1 text-xs leading-5 text-muted-foreground">
                    API Instagram mengembalikan post urut tanggal turun — pinned post tidak diangkat ke
                    atas seperti di tampilan web, jadi flyer lama yang di-pin tidak pernah kena
                    <code>--limit</code>. Formulir ini memasukkannya tanpa menyentuh kuota Graph.
                </p>
            </div>

            <x-ui.field label="Permalink post">
                <x-ui.input name="permalink" required value="{{ old('permalink') }}"
                            placeholder="https://www.instagram.com/p/DV-tyQIkuw5/" />
                <span class="text-[11px] text-muted-foreground">
                    Kode di URL-nya yang jadi id post — kirim ulang URL yang sama akan menimpa,
                    bukan menambah baris kedua.
                </span>
            </x-ui.field>

            {{-- datalist: akun yang sudah ada jadi saran, tapi username baru tetap boleh
                 diketik — yang mengirim sering menemukan travel yang belum kita lacak. --}}
            <x-ui.field label="Akun sumber">
                <x-ui.input name="account" required list="accounts" value="{{ old('account') }}"
                            placeholder="mahyaatourtravel" />
                <span class="text-[11px] text-muted-foreground">
                    Handle, &#64;-handle, atau URL profil. Kalau belum terdaftar, akunnya dibuat sekalian.
                </span>
            </x-ui.field>
            <datalist id="accounts">
                @foreach ($accounts as $username)
                    <option value="{{ $username }}"></option>
                @endforeach
            </datalist>

            <x-ui.field label="Tanggal posting">
                <x-ui.input type="date" name="posted_at" required value="{{ old('posted_at') }}" />
                <span class="text-[11px] text-muted-foreground">
                    Wajib, dan jangan dikira-kira. Ini jangkar tahun buat penyusun: flyer yang
                    menulis &ldquo;14 Maret&rdquo; tanpa tahun dibaca sebagai kejadian pertama
                    setelah tanggal ini.
                </span>
            </x-ui.field>

            <x-ui.field label="Flyer">
                <input type="file" name="images[]" required multiple accept="image/jpeg,image/png,image/webp"
                       class="text-sm file:mr-3 file:cursor-pointer file:rounded-md file:border file:border-input
                              file:bg-background file:px-3 file:py-1.5 file:text-xs file:font-medium">
                <div id="flyer-preview" class="flex flex-wrap gap-2 empty:hidden"></div>
                <span class="text-[11px] text-muted-foreground">
                    Untuk carousel, pilih semua slide sekaligus — urutannya jadi nomor slide.
                    png/webp di-encode ulang jadi jpg.
                </span>
            </x-ui.field>

            <x-ui.field label="Caption (opsional)">
                <textarea name="caption" rows="6"
                          class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs
                                 outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                          placeholder="Tempel caption aslinya">{{ old('caption') }}</textarea>
                <span class="text-[11px] text-muted-foreground">
                    Dipakai membaca yang buram atau disingkat di flyer. Kosongkan kalau tidak ada —
                    vonisnya tetap dari gambar.
                </span>
            </x-ui.field>

            <div><x-ui.button>Kirim usulan</x-ui.button></div>
        </form>
    </x-ui.card>

    {{-- Kiriman sendiri + statusnya, untuk kedua peran. Disaring dengan `_created_by`
         (jejak permanen), bukan `_suggested_by` yang dibuang saat disetujui — kalau
         tidak, kiriman menghilang dari daftar pengirimnya justru pada saat statusnya
         mulai menarik. Badge statusnya partial yang sama dengan kolom status /posts. --}}
    <x-ui.card class="p-4">
        <h2 class="text-sm font-medium">Kiriman saya</h2>
        <p class="mb-3 mt-1 text-[11px] leading-4 text-muted-foreground">
            Status paketnya menyusul sendiri sesudah disetujui: dibaca AI &rarr; jadi baris paket,
            atau ditolak dengan alasannya. Yang sudah jadi paket baru tampil di pencarian setelah
            di-<em>publish</em> admin.
        </p>

        @forelse ($akunSaya->concat($postSaya) as $item)
            {{-- Dua bentuk di satu daftar: usulan akun (model SourceAccount) dan usulan
                 post (array dari kumpulkan()). Dibedakan di sini, bukan dua daftar —
                 yang mengirim tidak memikirkan bedanya, dia cuma mau lihat kirimannya. --}}
            <div class="flex items-center gap-2 border-t py-2 text-xs first:border-t-0">
                @if ($item instanceof App\Models\SourceAccount)
                    <x-ui.badge :variant="$item->status === 'approved' ? 'default' : 'outline'">{{ $item->status }}</x-ui.badge>
                    <a href="https://www.instagram.com/{{ $item->username }}" target="_blank" rel="noopener"
                       class="underline underline-offset-4">{{ '@'.$item->username }}</a>
                    <span class="text-muted-foreground">akun</span>
                @else
                    @include('partials.post-status', ['post' => $item])
                    @if ($item['images'])
                        <img src="{{ $item['images'][0] }}" alt="" loading="lazy" class="size-8 rounded border object-cover">
                    @endif
                    <a href="{{ $item['permalink'] }}" target="_blank" rel="noopener"
                       class="underline underline-offset-4">{{ '@'.$item['account'] }}</a>
                    <span class="text-muted-foreground">{{ count($item['images']) }} gambar</span>
                @endif
            </div>
        @empty
            <p class="text-xs text-muted-foreground">Belum ada kiriman.</p>
        @endforelse
    </x-ui.card>
</div>

{{-- Pratinjau flyer + nomor slidenya. Urutan file itu yang jadi `flyer_index`,
     jadi yang perlu dilihat sebelum kirim bukan cuma "gambarnya benar" tapi juga
     "urutannya benar". Chrome extension ikut kebagian: ia men-dispatch `change`
     sesudah menyetel `input.files`. --}}
<script>
    document.querySelector('input[name="images[]"]')?.addEventListener('change', (e) => {
        const kotak = document.getElementById('flyer-preview')
        kotak.querySelectorAll('img').forEach((img) => URL.revokeObjectURL(img.src))
        kotak.replaceChildren()

        for (const [i, file] of [...e.target.files].entries()) {
            const img = new Image()
            img.src = URL.createObjectURL(file)
            img.className = 'size-20 rounded border object-cover'
            img.title = file.name

            const bingkai = document.createElement('figure')
            bingkai.className = 'grid gap-1 text-center text-[11px] text-muted-foreground'
            bingkai.append(img, Object.assign(document.createElement('figcaption'), { textContent: `slide ${i}` }))
            kotak.append(bingkai)
        }
    })
</script>
@endsection
