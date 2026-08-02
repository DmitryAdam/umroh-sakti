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
        <p class="mt-1 text-sm leading-6 text-muted-foreground">
            Kirim postingan paket umroh yang belum ada di sini. Semua kiriman dicek admin dulu.
            Kalau sering mengirim, pakai
            <a href="{{ route('extension') }}" class="underline underline-offset-4">extension Chrome</a>
            supaya tidak perlu mengetik ulang.
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
                <h2 class="text-sm font-medium">Kirim postingan Instagram</h2>
                <p class="mt-1 text-sm leading-6 text-muted-foreground">
                    Buka postingannya di Instagram, lalu isi datanya di bawah. Paling berguna untuk
                    postingan lama yang tidak terambil otomatis.
                </p>
            </div>

            <x-ui.field label="Link postingan">
                <x-ui.input name="permalink" required value="{{ old('permalink') }}"
                            placeholder="https://www.instagram.com/p/DV-tyQIkuw5/" />
                <span class="text-xs leading-5 text-muted-foreground">
                    Salin dari tombol bagikan di Instagram. Postingan yang sudah ada di sini akan ditolak.
                </span>
            </x-ui.field>

            {{-- datalist: akun yang sudah ada jadi saran, tapi username baru tetap boleh
                 diketik — yang mengirim sering menemukan travel yang belum kita lacak. --}}
            <x-ui.field label="Akun travel">
                <x-ui.input name="account" required list="accounts" value="{{ old('account') }}"
                            placeholder="mahyaatourtravel" />
                <span class="text-xs leading-5 text-muted-foreground">
                    Nama akunnya, boleh pakai &#64; atau link profil. Akun baru otomatis ditambahkan.
                </span>
            </x-ui.field>
            <datalist id="accounts">
                @foreach ($accounts as $username)
                    <option value="{{ $username }}"></option>
                @endforeach
            </datalist>

            <x-ui.field label="Tanggal postingan">
                <x-ui.input type="date" name="posted_at" required value="{{ old('posted_at') }}" />
                <span class="text-xs leading-5 text-muted-foreground">
                    Lihat tanggalnya di postingan, jangan dikira-kira. Flyer sering menulis
                    &ldquo;14 Maret&rdquo; tanpa tahun, dan tanggal ini yang dipakai menebak tahunnya.
                </span>
            </x-ui.field>

            <x-ui.field label="Gambar flyer">
                <input type="file" name="images[]" required multiple accept="image/jpeg,image/png,image/webp"
                       class="w-full max-w-full text-sm file:mr-3 file:cursor-pointer file:rounded-md file:border file:border-input
                              file:bg-background file:px-3 file:py-1.5 file:text-xs file:font-medium">
                <div id="flyer-preview" class="flex flex-wrap gap-2 empty:hidden"></div>
                <span class="text-xs leading-5 text-muted-foreground">
                    Kalau postingannya berisi beberapa gambar, pilih semuanya sekaligus dan urut.
                </span>
            </x-ui.field>

            <x-ui.field label="Caption (boleh dikosongkan)">
                <textarea name="caption" rows="6"
                          class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs
                                 outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                          placeholder="Tempel tulisan di bawah postingannya">{{ old('caption') }}</textarea>
                <span class="text-xs leading-5 text-muted-foreground">
                    Membantu membaca tulisan yang buram atau disingkat di flyer.
                </span>
            </x-ui.field>

            <div><x-ui.button class="w-full sm:w-auto">Kirim</x-ui.button></div>
        </form>
    </x-ui.card>

    {{-- Kiriman sendiri + statusnya, untuk kedua peran. Disaring dengan `_created_by`
         (jejak permanen), bukan `_suggested_by` yang dibuang saat disetujui — kalau
         tidak, kiriman menghilang dari daftar pengirimnya justru pada saat statusnya
         mulai menarik. Badge statusnya partial yang sama dengan kolom status /posts. --}}
    <x-ui.card class="p-4">
        <h2 class="text-sm font-medium">Kiriman saya</h2>
        <p class="mb-3 mt-1 text-sm leading-6 text-muted-foreground">
            Setelah disetujui admin, postingannya dibaca otomatis lalu jadi paket &mdash; atau
            ditolak dengan alasannya. Paketnya baru muncul di pencarian setelah dipublikasikan admin.
        </p>

        @forelse ($akunSaya->concat($postSaya) as $item)
            {{-- Dua bentuk di satu daftar: usulan akun (model SourceAccount) dan usulan
                 post (array dari kumpulkan()). Dibedakan di sini, bukan dua daftar —
                 yang mengirim tidak memikirkan bedanya, dia cuma mau lihat kirimannya. --}}
            {{-- flex-wrap: di layar HP satu baris ini (badge + gambar + handle + jumlah)
                 lebih lebar dari layarnya dan bikin halamannya bisa digeser ke samping. --}}
            <div class="flex flex-wrap items-center gap-2 border-t py-2 text-xs first:border-t-0">
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
                    {{-- Cuma selama masih usulan. Sesudah disetujui barisnya sudah jadi
                         paket yang di-review orang lain, dan menariknya dari sini sama
                         dengan tombol hapus paket yang terbuka untuk semua yang login —
                         controllernya menolak dengan aturan yang sama. Hapus, bukan
                         blokir: kiriman yang salah tanggal/permalink harus boleh
                         dikirim ulang. --}}
                    @if ($item['usulan'])
                        <form method="POST" action="{{ route('posts.destroy', $item['media_id']) }}" class="ml-auto"
                              onsubmit="return confirm('Hapus kiriman ini? Gambarnya dibuang, dan postnya boleh dikirim ulang.')">
                            @csrf
                            @method('DELETE')
                            <button class="text-destructive underline underline-offset-4">hapus</button>
                        </form>
                    @endif
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
