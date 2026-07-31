@extends('layout')
@section('title', 'Masuk')

@section('content')
<x-ui.card class="mx-auto mt-10 w-full max-w-sm p-6">
    <h1 class="text-lg font-semibold tracking-tight">Masuk</h1>
    <p class="mt-1 text-sm text-muted-foreground">
        Halaman operator. Pencarian paket tetap terbuka tanpa login.
    </p>

    <form method="POST" action="{{ route('login') }}" class="mt-5 grid gap-3">
        @csrf

        @error('email')
            <p class="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{{ $message }}</p>
        @enderror

        <x-ui.field label="Email">
            <x-ui.input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" />
        </x-ui.field>

        <x-ui.field label="Sandi">
            <x-ui.input type="password" name="password" required autocomplete="current-password" />
        </x-ui.field>

        <label class="flex items-center gap-2 text-xs text-muted-foreground">
            <input type="checkbox" name="remember" value="1" class="size-3.5"> ingat saya
        </label>

        <x-ui.button class="mt-1 w-full">Masuk</x-ui.button>
    </form>

    <p class="mt-4 text-xs text-muted-foreground">
        Belum punya akun? Tidak ada halaman daftar —
        <code class="rounded bg-muted px-1">php artisan user:create &lt;email&gt;</code>.
    </p>
</x-ui.card>
@endsection
