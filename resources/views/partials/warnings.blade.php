{{-- Peringatan regulasi. Sengaja ditampilkan, tidak menyembunyikan paket. --}}
@if ($package->isBelowReferencePrice())
    <p class="mt-2 rounded bg-red-50 px-2 py-1 text-xs text-red-800 ring-1 ring-red-200">
        Harga di bawah BPIU Referensi Kemenag (Rp{{ number_format($reference, 0, ',', '.') }}).
        Waspadai paket yang terlalu murah.
    </p>
@endif
