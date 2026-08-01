@extends('layout')
@section('title', 'Masuk')

@section('content')
<x-ui.card class="mx-auto mt-10 w-full max-w-sm p-6">
    <h1 class="text-lg font-semibold tracking-tight">Masuk</h1>
    <p class="mt-1 text-sm text-muted-foreground">
       Assalamualaikum,
    </p>

    {{-- Penolakan login (state kedaluwarsa, email belum terverifikasi, akun
         ditangguhkan) datang lewat session('status'), sama seperti hasil aksi di
         halaman alat kerja — jangan bikin kotak pesan versi keempat. --}}
    <div class="mt-4">@include('partials.flash')</div>

    {{-- POST, bukan tautan: rutenya ber-CSRF dan throttle:5,1, jadi tidak ada yang
         bisa memancing orang lain memulai login dari halaman lain. --}}
    <form method="POST" action="{{ route('login') }}" class="mt-1">
        @csrf
        <x-ui.button variant="outline" class="w-full">
            {{-- Logo Google apa adanya (4 warna, bukan stroke) — x-ui.icon isinya
                 lucide satu garis, dan mengganti lambangnya melanggar syarat merek. --}}
            <svg viewBox="0 0 48 48" class="!size-4" aria-hidden="true">
                <path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9 3.6l6.7-6.7C35.6 2.6 30.2 0 24 0 14.6 0 6.5 5.4 2.6 13.2l7.8 6.1C12.3 13.2 17.7 9.5 24 9.5z"/>
                <path fill="#4285F4" d="M46.1 24.6c0-1.6-.1-3.1-.4-4.6H24v9.1h12.4c-.5 2.9-2.2 5.3-4.6 7l7.6 5.9c4.4-4.1 6.7-10.1 6.7-17.4z"/>
                <path fill="#FBBC05" d="M10.4 28.6c-.5-1.4-.8-2.9-.8-4.6s.3-3.2.8-4.6l-7.8-6.1C.9 16.4 0 20.1 0 24s.9 7.6 2.6 10.7l7.8-6.1z"/>
                <path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.6-5.9c-2.1 1.4-4.8 2.3-8.3 2.3-6.3 0-11.7-3.7-13.6-9.8l-7.8 6.1C6.5 42.6 14.6 48 24 48z"/>
            </svg>
            Masuk dengan Google
        </x-ui.button>
    </form>

   
</x-ui.card>
@endsection
