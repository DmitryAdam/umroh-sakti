@extends('layout')
@section('title', 'Akun Sumber')

@section('content')
<div class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-1 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900">
    <strong>Daftar akun (lokal).</strong>
    <span>Akun yang dimasukkan di sini langsung <code>approved</code> — ikut di putaran pipeline berikutnya.</span>
    <a href="{{ route('search') }}" class="ml-auto underline">ke daftar paket</a>
</div>

@if (session('status'))
    <p class="mb-3 rounded border border-emerald-300 bg-emerald-50 px-3 py-2 text-xs text-emerald-900">{{ session('status') }}</p>
@endif

@error('usernames')
    <p class="mb-3 rounded border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-900">{{ $message }}</p>
@enderror

<form method="POST" action="{{ route('accounts.store') }}" class="mb-4 rounded border border-stone-200 bg-white p-3 text-xs">
    @csrf
    <label for="usernames" class="block text-stone-600">
        Username, <code>@handle</code>, atau URL Instagram — satu per baris. Baris yang diawali <code>#</code> dilewat.
    </label>
    <textarea id="usernames" name="usernames" rows="4" required
              placeholder="hamdantour&#10;https://www.instagram.com/sunnatravel.id"
              class="mt-2 w-full rounded border-stone-300 p-2 font-mono text-xs"></textarea>
    <button class="mt-2 rounded bg-stone-900 px-3 py-1 text-xs text-white">Tambah akun</button>
</form>

<div class="mb-2 flex flex-wrap items-center gap-3 text-xs text-stone-600">
    <span>
        {{ $accounts->count() }} akun ·
        {{ $accounts->whereNull('last_fetched_at')->count() }} belum pernah di-scrap
    </span>
    @if ($accounts->where('status', 'approved')->isNotEmpty())
        <form method="POST" action="{{ route('accounts.fetch_all') }}" class="ml-auto"
              onsubmit="return confirm('Antrikan fetch untuk {{ $accounts->where('status', 'approved')->count() }} akun approved?')">
            @csrf
            <button class="rounded bg-stone-900 px-2 py-1 text-xs text-white">Scrap semua</button>
        </form>
    @endif
</div>

<div class="overflow-x-auto rounded border border-stone-200 bg-white">
    <table class="w-full text-left text-xs">
        <thead class="border-b border-stone-200 text-stone-500">
            <tr>
                <th class="px-3 py-2 font-medium">akun</th>
                <th class="px-3 py-2 font-medium">status</th>
                <th class="px-3 py-2 font-medium">terakhir scrap</th>
                <th class="px-3 py-2 text-right font-medium">post</th>
                <th class="px-3 py-2 text-right font-medium">paket</th>
                <th class="px-3 py-2 text-right font-medium">dibanned</th>
                <th class="px-3 py-2"></th>
            </tr>
        </thead>
        <tbody>
        @forelse ($accounts as $account)
            <tr class="border-b border-stone-100 last:border-0">
                <td class="px-3 py-2">
                    <a href="https://www.instagram.com/{{ $account->username }}" target="_blank" rel="noopener"
                       class="font-medium underline">{{ '@'.$account->username }}</a>
                </td>
                <td class="px-3 py-2 text-stone-500">{{ $account->status }}</td>
                <td class="px-3 py-2">
                    @if ($account->last_fetched_at)
                        <span title="{{ $account->last_fetched_at }}">{{ $account->last_fetched_at->diffForHumans() }}</span>
                    @else
                        <span class="rounded bg-amber-100 px-1.5 py-0.5 text-amber-900">belum pernah</span>
                    @endif
                </td>
                <td class="px-3 py-2 text-right text-stone-600">{{ $posts[$account->username] ?? 0 }}</td>
                <td class="px-3 py-2 text-right text-stone-600">{{ $packages[$account->username] ?? 0 }}</td>
                <td class="px-3 py-2 text-right text-stone-400">{{ $dibanned[$account->username] ?? 0 }}</td>
                <td class="px-3 py-2 text-right whitespace-nowrap">
                    <form method="POST" action="{{ route('accounts.fetch', $account) }}" class="inline">
                        @csrf
                        <button class="rounded border border-stone-300 px-2 py-0.5 hover:bg-stone-100">scrap</button>
                    </form>
                    <form method="POST" action="{{ route('accounts.destroy', $account) }}" class="inline"
                          onsubmit="return confirm('Hapus {{ '@'.$account->username }} dari daftar akun?')">
                        @csrf @method('DELETE')
                        <button title="hapus dari daftar" class="rounded px-1.5 py-0.5 text-stone-400 hover:bg-red-600 hover:text-white">&times;</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="px-3 py-4 text-stone-500">Belum ada akun. Tambah di atas, atau <code>php artisan packages:crawl accounts.txt</code>.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
