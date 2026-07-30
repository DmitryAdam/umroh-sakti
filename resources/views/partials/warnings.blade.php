{{-- Peringatan regulasi. Sengaja ditampilkan, tidak menyembunyikan paket. --}}
@if ($package->isBelowReferencePrice())
    <p class="mt-2 rounded-md border border-destructive/30 bg-destructive/10 px-2.5 py-1.5 text-xs text-destructive">
        Harga di bawah BPIU Referensi Kemenag (Rp{{ number_format($reference, 0, ',', '.') }}).
        Waspadai paket yang terlalu murah.
    </p>
@endif
