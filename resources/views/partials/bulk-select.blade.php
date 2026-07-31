{{-- Centang per baris -> bar aksi. Dipakai /accounts dan /posts: keduanya punya
     tabel + `<form id="bulk">` di luar tabel (checkbox-nya menitip lewat atribut
     HTML5 `form=`), jadi JS di sini cuma tampilan + konfirmasi — submitnya form
     biasa. Variabel: $satuan ('akun'/'post'), $catatan (akibat default di konfirmasi).

     Dibungkus IIFE: halaman akun punya <script> lain di file yang sama, dan
     `const pilih` yang sama namanya di dua blok top-level itu SyntaxError yang
     mematikan dua-duanya. --}}
<script>
(() => {
    const pilih = () => [...document.querySelectorAll('[data-pilih]')];
    const bulkBar = document.querySelector('[data-bulk]');
    const terpilih = () => pilih().filter((c) => c.checked);
    if (!bulkBar) return;
    // Listener di document, bukan di `querySelector('table')`: halaman akun punya
    // tabel kedua (usulan yang menunggu) dan yang pertama ketemu belum tentu tabel
    // yang punya checkbox. Delegasinya toh lewat data-attribute.
    const tabel = document;

    const sinkron = () => {
        const n = terpilih().length;
        bulkBar.classList.toggle('hidden', n === 0);
        bulkBar.classList.toggle('flex', n > 0);
        bulkBar.querySelector('[data-terpilih]').textContent = n;
        document.querySelector('[data-semua]').checked = n > 0 && n === pilih().length;
    };

    tabel.addEventListener('change', (e) => {
        if (e.target.matches('[data-semua]')) pilih().forEach((c) => { c.checked = e.target.checked; });
        if (e.target.matches('[data-pilih],[data-semua]')) sinkron();
    });

    // Shift-klik = pilih serentetan. Tidak ada bawaannya di HTML: checkbox tidak
    // saling kenal, jadi jangkarnya (baris yang diklik terakhir) disimpan sendiri.
    // `pilih()` dibaca ulang tiap klik supaya ikut urutan `?sort=` yang tampil.
    let jangkar = null;
    tabel.addEventListener('click', (e) => {
        const c = e.target.closest('[data-pilih]');
        if (!c) return;
        const baris = pilih();
        const i = baris.indexOf(c);
        if (e.shiftKey && jangkar !== null) {
            // Shift di dalam tabel menyorot teks; sorotannya menutupi baris yang
            // baru saja ikut tercentang.
            window.getSelection().removeAllRanges();
            for (let k = Math.min(jangkar, i); k <= Math.max(jangkar, i); k++) baris[k].checked = c.checked;
        }
        jangkar = i;
        sinkron();
    });

    bulkBar.querySelector('[data-batal-pilih]').addEventListener('click', () => {
        pilih().forEach((c) => { c.checked = false; });
        sinkron();
    });

    // Tombol yang akibatnya tidak bisa dibatalkan (buang data / bayar model)
    // konfirmasi dulu, menyebut jumlah + akibatnya. `data-catatan` menimpa defaultnya.
    bulkBar.addEventListener('click', (e) => {
        const b = e.target.closest('[data-confirm]');
        const catatan = b?.dataset.catatan ?? @json($catatan ?? '');
        if (b && !confirm(`${b.dataset.confirm} ${terpilih().length} {{ $satuan }} terpilih? ${catatan}`)) e.preventDefault();
    });
})();
</script>
