const $ = (s) => document.querySelector(s)

const kabar = (teks, kelas = '') => {
  $('#status').textContent = teks
  $('#status').className = kelas
}

chrome.storage.sync.get('portal').then(({ portal }) => {
  $('[name=portal]').value = portal || 'http://localhost:8000'
})

$('#simpan').addEventListener('submit', async (e) => {
  e.preventDefault()

  // Trailing slash dibuang di sini, sekali. Kalau tidak, tiap pemakainya harus
  // ingat menulis `${portal}/suggestions` tanpa slash ganda.
  const portal = $('[name=portal]').value.trim().replace(/\/+$/, '')

  // Izin host diminta saat disimpan, bukan dituntut di manifest: alamat portalnya
  // beda per orang, jadi tidak ada satu pola yang bisa ditulis di depan. Harus
  // dipicu klik — chrome.permissions.request menolak panggilan tanpa gestur.
  if (!await chrome.permissions.request({ origins: [`${portal}/*`] })) {
    return kabar('Tanpa izin itu extension tidak bisa mengirim ke portalnya.', 'gagal')
  }

  await chrome.storage.sync.set({ portal })
  await chrome.runtime.sendMessage({ pasang: true })

  kabar(`Tersimpan. Kiriman diarahkan ke ${portal}/suggestions`, 'oke')
})
