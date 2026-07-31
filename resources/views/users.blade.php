@extends('layout')
@section('title', 'Pengguna')

@section('content')
<div class="mx-auto w-full max-w-4xl">
    <h1 class="text-lg font-semibold tracking-tight">Pengguna</h1>
    <p class="mb-3 mt-1 text-sm text-muted-foreground">
        Siapa pun yang punya akun Google bisa masuk, dan selalu lahir sebagai
        <strong>pengusul</strong>: cuma boleh mengirim usulan post, tidak bisa
        menjalankan scrap maupun ekstraksi. Menaikkan seseorang jadi admin sengaja
        tidak ada tombolnya —
        <code class="rounded bg-muted px-1">php artisan tinker</code> lalu
        <code class="rounded bg-muted px-1">User::where('email',…)->update(['role'=>'admin'])</code>.
    </p>

    @include('partials.flash')

    <div class="overflow-x-auto rounded-xl border">
        <table class="w-full text-sm">
            <thead class="border-b bg-muted/50 text-left text-xs text-muted-foreground">
                <tr>
                    <th class="px-3 py-2 font-medium">email</th>
                    <th class="px-3 py-2 font-medium">nama</th>
                    <th class="px-3 py-2 font-medium">peran</th>
                    <th class="px-3 py-2 font-medium">bergabung</th>
                    <th class="px-3 py-2 font-medium text-right">aksi</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($users as $user)
                    <tr class="border-b last:border-0 {{ $user->isSuspended() ? 'bg-destructive/5' : '' }}">
                        <td class="px-3 py-2">
                            {{ $user->email }}
                            @if ($user->is(auth()->user()))
                                <span class="text-xs text-muted-foreground">(kamu)</span>
                            @endif
                            @if ($user->isSuspended())
                                <x-ui.badge variant="destructive" class="ml-1">
                                    ditangguhkan {{ $user->suspended_at->translatedFormat('j M Y') }}
                                </x-ui.badge>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-muted-foreground">{{ $user->name ?: '—' }}</td>
                        <td class="px-3 py-2">
                            <x-ui.badge :variant="$user->isAdmin() ? 'default' : 'secondary'">
                                {{ $user->isAdmin() ? 'admin' : 'pengusul' }}
                            </x-ui.badge>
                        </td>
                        <td class="px-3 py-2 text-xs text-muted-foreground">
                            {{ $user->created_at?->translatedFormat('j M Y') ?: '—' }}
                        </td>
                        <td class="px-3 py-2 text-right">
                            {{-- Diri sendiri tidak dapat tombol: controllernya juga
                                 menolak, ini cuma supaya tidak kelihatan bisa. --}}
                            @unless ($user->is(auth()->user()))
                                {{-- Dua cabang penuh, bukan satu tombol ber-ternary di
                                     dalam atribut: `{{ }}` yang memuat kutip di dalam
                                     atribut komponen x-* dikunyah compiler jadi
                                     `unexpected token "endif"` di baris terakhir file. --}}
                                <form method="POST" action="{{ route('users.update', $user) }}" class="inline">
                                    @csrf @method('PATCH')
                                    @if ($user->isSuspended())
                                        <input type="hidden" name="suspended" value="0">
                                        <x-ui.button size="sm" variant="outline">aktifkan</x-ui.button>
                                    @else
                                        <input type="hidden" name="suspended" value="1">
                                        <x-ui.button size="sm" variant="destructive"
                                                     onclick="return confirm('Tangguhkan {{ $user->email }}? Sesinya langsung diputus.')"
                                        >tangguhkan</x-ui.button>
                                    @endif
                                </form>
                            @endunless
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Prev/next saja, bukan `$users->links()`: paginator bawaan merender kelas
         Tailwind v3 yang tidak ada di build v4 kita. --}}
    @if ($users->hasPages())
        <div class="mt-2 flex items-center gap-3 text-xs text-muted-foreground">
            @if ($users->previousPageUrl())
                <a href="{{ $users->previousPageUrl() }}" class="underline">&larr; sebelumnya</a>
            @endif
            <span>halaman {{ $users->currentPage() }} dari {{ $users->lastPage() }} — {{ $users->total() }} pengguna</span>
            @if ($users->nextPageUrl())
                <a href="{{ $users->nextPageUrl() }}" class="underline">berikutnya &rarr;</a>
            @endif
        </div>
    @endif
</div>
@endsection
