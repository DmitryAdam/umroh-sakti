const $ = (s) => document.querySelector(s)
const portal = async () => (await chrome.storage.sync.get('portal')).portal || 'https://umrohsakti.my.id'

let panen

const kabar = (teks, kelas = '') => {
  $('#status').textContent = teks
  $('#status').className = kelas
}

;(async () => {
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true })

  // Sengaja tidak menyaring tab.url dulu: post yang dibuka sebagai overlay di atas
  // grid profil tidak selalu mengubah URL, jadi gerbang URL menolak post yang
  // sebenarnya terbuka. Yang memutuskan hasil panennya.
  if (!/instagram\.com/.test(tab.url || '')) {
    return kabar('Buka Instagram dulu, lalu buka satu postnya.', 'gagal')
  }

  kabar('Membaca post — carousel butuh beberapa detik…')
  panen = await chrome.runtime.sendMessage({ panen: tab.id })

  if (!panen?.permalink) {
    return kabar('Tidak ada post yang terbuka di tab ini. Buka satu postnya dulu.', 'gagal')
  }

  if (!panen.images.length) {
    return kabar(panen?.video
      ? 'Post ini video/reel — tidak diproses. Pipeline cuma membaca flyer, dan satu frame video belum tentu memuatnya.'
      : 'Gambar tidak terbaca. Tunggu postnya termuat penuh, lalu buka lagi.', 'gagal')
  }

  $('[name=account]').value = panen.account
  $('[name=posted_at]').value = panen.posted_at
  $('[name=caption]').value = panen.caption
  $('#kandidat').append(...panen.kandidat.map((u) =>
    Object.assign(document.createElement('option'), { value: u })))
  $('#thumbs').append(...panen.images.map((src, i) =>
    Object.assign(new Image(), { src, title: `slide ${i}` })))

  kabar(`${panen.images.length} gambar${panen.kandidat.length > 1 ? ' · post kolaborasi, periksa akunnya' : ''}`)
  $('#kirim').hidden = false
})()

$('#kirim').addEventListener('submit', async (e) => {
  e.preventDefault()
  e.target.querySelector('button').disabled = true
  kabar('Mengirim…')

  const isian = Object.fromEntries(new FormData(e.target))
  const jawab = await chrome.runtime.sendMessage({ kirim: { ...panen, ...isian } })

  // Berhasil = tidak ada lagi yang perlu dilihat di sini, popupnya menutup diri.
  // Jedanya cuma supaya angka gambarnya sempat kebaca; gagal tidak pernah menutup.
  if (jawab?.ok) {
    kabar(`Tersimpan, ${jawab.gambar} gambar. Masuk antrian ai.`, 'oke')

    return setTimeout(window.close, 800)
  }

  e.target.querySelector('button').disabled = false

  // Alamat portal belum diisi/diizinkan: halaman options yang menanyakannya, dan
  // izin host cuma boleh diminta dari sana (butuh gestur klik).
  if (jawab?.pesan === 'belum-diatur') {
    kabar('Alamat portal belum diatur. Membuka pengaturan…', 'gagal')

    return chrome.runtime.openOptionsPage()
  }

  // Sesi portalnya yang dipakai, jadi "login" di sini artinya login sekali di
  // browser — bukan kredensial kedua yang disimpan extension.
  if (jawab?.pesan === 'belum-login') {
    kabar('Belum login di portal. Membuka halaman login…', 'gagal')

    return chrome.tabs.create({ url: `${await portal()}/login` })
  }

  kabar(jawab?.pesan || 'Gagal, dan portalnya tidak menjelaskan kenapa.', 'gagal')
})
