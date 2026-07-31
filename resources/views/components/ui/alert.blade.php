{{-- Kotak pesan: hasil aksi (`session('status')`), galat validasi, atau catatan.
     Satu tempat supaya tiga halaman alat kerja tidak punya tiga versi warna. --}}
@props(['variant' => 'ok'])

{{-- role: dibaca screen reader, dan jadi pengait satu-satunya yang stabil buat
     chrome extension menandai "kiriman ini selamat" (kelasnya berubah tiap ganti tema). --}}
<div {{ $attributes->merge(['role' => $variant === 'error' ? 'alert' : 'status'])->class(['mb-3 rounded border px-3 py-2 text-xs', match ($variant) {
    'error' => 'border-red-300 bg-red-50 text-red-900',
    'warn' => 'border-amber-300 bg-amber-50 text-amber-900',
    default => 'border-emerald-300 bg-emerald-50 text-emerald-900',
}]) }}>{{ $slot }}</div>
