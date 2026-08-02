const $ = (s) => document.querySelector(s)
const portal = async () => (await chrome.storage.sync.get('portal')).portal || 'https://umrohsakti.my.id'

let panen

// Extension unpacked tidak memuat ulang sendiri; tanpa versinya kelihatan di sini,
// "sudah kuperbaiki" dan "belum ditekan ⟳" tidak bisa dibedakan.
$('h1').append(` v${chrome.runtime.getManifest().version}`)

const kabar = (teks, kelas = '') => {
  $('#status').textContent = teks
  $('#status').className = kelas
}

;(async () => {
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true })

  if (!/instagram\.com/.test(tab.url || '')) {
    return kabar('Buka Instagram dulu: satu postnya, atau grid profil travelnya.', 'gagal')
  }

  // Putaran grid yang masih jalan menang atas apa pun yang sedang terbuka — dialah
  // yang mengemudikan tab itu.
  const berjalan = await chrome.runtime.sendMessage({ statusGrid: true })
  if (berjalan?.jalan) return render(berjalan)

  // Grid profil: URL-nya tidak memuat /p/. panen() sengaja tidak dipanggil di sini —
  // di grid, fallback permalinknya menemukan tile pertama dan hasilnya post palsu.
  if (!/\/(?:p|reel|tv)\//.test(tab.url)) return grid(tab.id)

  kabar('Membaca post — carousel butuh beberapa detik…')
  panen = await chrome.runtime.sendMessage({ panen: tab.id })

  if (!panen?.permalink) {
    return kabar('Tidak ada post yang terbuka di tab ini. Buka satu postnya dulu.', 'gagal')
  }

  if (!panen.images.length) {
    return kabar(panen?.video
      ? 'Post ini video/reel — tidak diproses. Pipeline cuma membaca flyer, dan satu frame video belum tentu memuatnya.'
      : `Gambar tidak terbaca (${panen.jejak}). Tunggu postnya termuat penuh, lalu buka lagi.`, 'gagal')
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

// Mode grid. Yang dikirim dari sini permalinknya saja; caption, tanggal posting,
// akun, dan slide carousel tetap dipanen background dari halaman postnya masing-
// masing — grid cuma punya satu gambar per post dan tidak punya tanggal.
async function grid(tabId) {
  const links = await chrome.runtime.sendMessage({ grid: tabId })

  if (!links?.length) {
    return kabar('Tidak ada post di halaman ini. Buka grid profil travelnya, atau buka satu postnya.', 'gagal')
  }

  $('[name=max]').max = links.length
  $('[name=max]').value = Math.min(9, links.length)
  $('#grid').hidden = false
  kabar(`${links.length} post di halaman ini. Yang teratas dibuka satu per satu — popup boleh ditutup, putarannya jalan terus.`)

  $('#grid').addEventListener('submit', (e) => {
    e.preventDefault()
    e.target.hidden = true
    kabar('Mulai…')
    chrome.runtime.sendMessage({
      mulaiGrid: { tabId, links: links.slice(0, +$('[name=max]').value) },
    })
  })
}

$('#stop').addEventListener('click', (e) => {
  e.target.disabled = true
  e.target.textContent = 'Berhenti sesudah post ini selesai…'
  chrome.runtime.sendMessage({ stopGrid: true })
})

function render(a) {
  if (!a) return

  const kepala = a.jalan
    ? `Post ${a.ke}/${a.total}…`
    : (a.batal ? `Dihentikan di post ${a.ke}/${a.total}.` : `Selesai, ${a.total} post.`)

  kabar(`${kepala} ${a.ok} terkirim, ${a.lewat} dilewat, ${a.gagal.length} gagal.`,
    a.jalan ? '' : (a.gagal.length ? 'gagal' : 'oke'))

  // Post yang sedang jalan tidak dibatalkan di tengah — tombolnya tetap hilang
  // begitu putarannya berhenti, biar tidak kelihatan seperti masih bisa ditekan.
  $('#stop').hidden = !a.jalan
  $('#stop').disabled = !!a.batal

  $('#gagal').replaceChildren(...a.gagal.map((t) =>
    Object.assign(document.createElement('li'), { textContent: t })))
  $('#gagal').hidden = !a.gagal.length
}

chrome.runtime.onMessage.addListener((m) => {
  if (m.kabarGrid) render(m.kabarGrid)
})

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
