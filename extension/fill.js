// Jalan di /suggestions. Mengisi form yang sudah ada — tidak ada endpoint baru,
// tidak ada token, tidak ada CORS: sesi login operator dan token CSRF halaman ini
// dipakai apa adanya.
//
// Dua mode. `auto` (dari popup, tab tersembunyi): isi lalu submit sendiri, hasilnya
// dilaporkan balik dan tabnya ditutup. Tanpa `auto`: cuma isi, manusia yang menekan
// kirim — itu jalur kalau halamannya dibuka sendiri.
;(async () => {
  const { pending } = await chrome.storage.local.get('pending')

  // Muatan sesudah submit manual: form yang diisi extension sudah menandai
  // sessionStorage, jadi begitu halamannya balik membawa flash sukses tabnya
  // ditutup. Penandanya per-tab dan sekali pakai — tab yang dibuka sendiri
  // operator tidak akan ikut tertutup.
  if (!pending) {
    if (sessionStorage.getItem('umroh-ext') && document.querySelector('[role="status"]')) {
      sessionStorage.removeItem('umroh-ext')
      chrome.runtime.sendMessage({ tutup: true })
    }

    return
  }

  // Mode auto tidak punya mata: tabnya tersembunyi dan popupnya menunggu satu
  // laporan. Exception apa pun harus tetap jadi laporan, kalau tidak tabnya
  // tinggal terbuka dan popupnya menggantung sampai timeout background.
  if (pending.auto) {
    addEventListener('error', (e) => chrome.runtime.sendMessage({ hasil: { ok: false, pesan: String(e.message) } }))
    addEventListener('unhandledrejection', (e) => chrome.runtime.sendMessage({ hasil: { ok: false, pesan: String(e.reason) } }))
  }

  // Belum login = middleware `auth` melempar ke /login dan formnya tidak ada di
  // sini. Payloadnya ditahan, bukan dibuang: `intended()` mengembalikan operator
  // ke /suggestions sesudah login dan pengisiannya jalan di situ.
  const form = document.querySelector('form[enctype="multipart/form-data"]')
  if (!form) return

  await chrome.storage.local.remove('pending')

  for (const key of ['permalink', 'account', 'posted_at', 'caption']) {
    const el = form.querySelector(`[name="${key}"]`)
    if (el && pending[key]) el.value = pending[key]
  }

  // Post kolaborasi: penulis kedua dinaikkan ke atas datalist yang sudah ada,
  // jadi kalau tebakan "penulis pertama = pemilik" meleset tinggal dipilih.
  const daftar = document.getElementById('accounts')
  for (const u of (pending.kandidat || []).slice(1).reverse()) {
    daftar?.prepend(Object.assign(document.createElement('option'), { value: u }))
  }

  const input = form.querySelector('input[type="file"]')
  const dt = new DataTransfer()
  const gagal = []

  for (const [i, url] of pending.images.entries()) {
    const dataUrl = await chrome.runtime.sendMessage({ image: url })
    if (!dataUrl) {
      gagal.push(url)
      continue
    }
    const blob = await (await fetch(dataUrl)).blob()
    dt.items.add(new File([blob], `${i}.jpg`, { type: 'image/jpeg' }))
  }

  input.files = dt.files
  input.dispatchEvent(new Event('change', { bubbles: true }))

  if (!pending.auto) {
    sessionStorage.setItem('umroh-ext', '1')

    const catatan = document.createElement('p')
    catatan.className = 'text-sm text-muted-foreground'
    catatan.textContent = `Terisi dari extension: ${dt.files.length} gambar`
      + (gagal.length ? `, ${gagal.length} gagal diunduh` : '')
      + '. Periksa tanggal posting sebelum kirim.'
    input.after(catatan)

    return
  }

  if (!dt.files.length) {
    return chrome.runtime.sendMessage({ hasil: { ok: false, pesan: 'Semua gambar gagal diunduh dari CDN.' } })
  }

  // X-Requested-With bikin ValidationException dibalas 422 JSON, bukan redirect
  // balik ke HTML — itu satu-satunya cara tahu field mana yang ditolak tanpa
  // mengurai markup halamannya.
  // overwrite=0: post yang sudah ada ditolak, bukan ditimpa. Submitnya otomatis
  // dan tabnya tersembunyi — menimpa di sini berarti paket yang sudah di-review
  // lenyap tanpa ada yang sempat membaca konfirmasinya.
  const isian = new FormData(form)
  isian.set('overwrite', '0')

  const res = await fetch(form.action, {
    method: 'POST',
    body: isian,
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  })

  if (res.status === 422) {
    const { errors } = await res.json().catch(() => ({ errors: {} }))

    return chrome.runtime.sendMessage({ hasil: { ok: false, pesan: Object.values(errors).flat().join(' ') || 'Ditolak validasi.' } })
  }

  // Sesi mati di tengah jalan: POST-nya ikut dilempar ke /login dan fetch
  // mengikutinya jadi 200 — tanpa cek ini kelihatan seperti berhasil.
  if (res.url.includes('/login')) {
    return chrome.runtime.sendMessage({ hasil: { ok: false, pesan: 'belum-login' } })
  }

  chrome.runtime.sendMessage({
    hasil: res.ok
      ? { ok: true, gambar: dt.files.length }
      : { ok: false, pesan: `Portal balas HTTP ${res.status}.` },
  })
})()
