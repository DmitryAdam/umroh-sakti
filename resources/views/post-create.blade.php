@extends('layout')
@section('title', 'Tambah post manual')

@section('content')
{{-- Jalan masuk untuk post yang TIDAK bisa dijangkau fetch: pinned lama, di luar
     --limit, atau akun yang belum pernah di-scrap. Sesudah tersimpan tidak ada jalur
     khusus — ExtractPost membacanya seperti post hasil scrap, gerbang vision tetap
     menilai, dan hasilnya tetap mendarat sebagai draft/review. --}}
<div class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
    <a href="{{ route('posts') }}" class="underline">&larr; semua post</a>
    <strong class="text-sm">Tambah post manual</strong>
</div>

@if (session('status'))
    <p class="mb-3 rounded border border-emerald-300 bg-emerald-50 px-3 py-2 text-xs text-emerald-900">{{ session('status') }}</p>
@endif

<x-ui.card class="max-w-2xl p-4">
    <p class="mb-4 text-xs leading-5 text-muted-foreground">
        API Instagram mengembalikan post urut tanggal turun — pinned post tidak diangkat
        ke atas seperti di tampilan web, jadi flyer lama yang di-pin tidak pernah kena
        <code>--limit</code>. Isi formulir ini untuk memasukkannya tanpa menyentuh kuota
        Graph. Paketnya tetap masuk sebagai <strong>draft/review</strong>, bukan langsung
        tampil ke pengunjung.
    </p>

    @if ($errors->any())
        <ul class="mb-4 list-inside list-disc rounded border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-900">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('posts.store') }}" enctype="multipart/form-data" class="grid gap-4">
        @csrf

        <x-ui.field label="Permalink post">
            <x-ui.input name="permalink" required value="{{ old('permalink') }}"
                        placeholder="https://www.instagram.com/p/DV-tyQIkuw5/" />
            <span class="text-[11px] text-muted-foreground">
                Kode di URL-nya yang jadi id post — kirim ulang URL yang sama akan menimpa,
                bukan menambah baris kedua.
            </span>
        </x-ui.field>

        {{-- datalist: akun yang sudah ada jadi saran, tapi username baru tetap boleh
             diketik — contributor sering menemukan travel yang belum kita lacak. Akun
             baru langsung dibuat sebagai `approved`, sama seperti textarea di /accounts. --}}
        <x-ui.field label="Akun sumber">
            <x-ui.input name="account" required list="accounts" value="{{ old('account') }}"
                        placeholder="mahyaatourtravel" />
            <span class="text-[11px] text-muted-foreground">
                Handle, @-handle, atau URL profil. Kalau belum terdaftar, akunnya dibuat sekalian.
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

        <div>
            <x-ui.button>Simpan &amp; baca</x-ui.button>
        </div>
    </form>
</x-ui.card>
@endsection
