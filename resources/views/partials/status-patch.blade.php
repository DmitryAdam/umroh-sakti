{{-- Select status paket -> satu PATCH, tanpa reload. Dipakai kartu di `/` dan
     kolom status di /posts. Tanpa reload karena halamannya sedang difilter:
     `?status=review` membuang kartu yang baru dipublish dari layar dan sisanya
     bergeser persis di tengah kerja. Listener di document, jadi baris yang lahir
     belakangan ikut kena. --}}
<script>
document.addEventListener('change', async (event) => {
    const select = event.target.closest('[data-status]');
    if (!select) return;

    select.disabled = true;
    try {
        const res = await fetch(select.dataset.status, {
            method: 'PATCH',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
            },
            body: JSON.stringify({ status: select.value }),
        });
        if (!res.ok) throw new Error(res.status);
        select.classList.remove('border-destructive');
        select.title = 'tersimpan: ' + select.value;
    } catch (e) {
        select.classList.add('border-destructive');
        select.title = 'gagal simpan: ' + e.message;
    }
    select.disabled = false;
});
</script>
