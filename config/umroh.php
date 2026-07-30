<?php

return [
    /*
     * BPIU Referensi Kemenag (Ditjen PHU). Paket di bawah angka ini memicu
     * warning otomatis di UI — jangan disembunyikan, cuma diberi peringatan.
     * Ambil angka terkini dari Ditjen PHU dan ubah di sini.
     */
    'bpiu_reference' => env('UMROH_BPIU_REFERENCE', 23000000),

    /* Harga dengan confidence di bawah ini wajib direview manusia. */
    'price_confidence_floor' => 0.8,

    /*
     * Sebagian travel memasang harga dalam USD ("USD 3.300 sekamar berempat").
     * Angkanya dikonversi ke IDR saat import supaya sorting harga dan warning
     * BPIU tetap satu satuan; angka asli + mata uangnya tetap ada di
     * raw_extraction. Kurs beku di baris paket — perbarui angkanya lalu
     * re-import kalau kursnya bergerak jauh.
     */
    'usd_rate' => env('UMROH_USD_RATE', 16500),

    /*
     * Keberangkatan sebelum tanggal ini tidak diambil: paketnya sudah lewat atau
     * terlalu mepet untuk dijual. Post-nya sekalian masuk excluded_posts supaya
     * tidak di-scrap lagi. Tanggal tanpa keberangkatan (null) tetap lolos —
     * belum bisa dinilai, jadi jangan dibuang.
     */
    'min_departure' => env('UMROH_MIN_DEPARTURE', '2026-08-01'),
];
