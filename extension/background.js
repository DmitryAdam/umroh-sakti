// Alamat portalnya diatur di halaman options, bukan ditulis di sini: portalnya
// dipasang sendiri-sendiri, jadi satu alamat tetap cuma benar buat satu orang.
const portal = async () => (await chrome.storage.sync.get('portal')).portal || 'http://localhost:8000'

// fill.js didaftarkan saat jalan, bukan lewat `content_scripts` di manifest:
// pola matches-nya baru diketahui sesudah alamat portalnya diisi. Dipasang ulang
// tiap browser start — pendaftaran dinamis tidak selalu selamat dari restart.
async function pasang() {
  // Seluruhnya dibungkus: alamat tersimpan yang bukan pola sah (tanpa skema,
  // ada path) bikin permissions.contains melempar, dan lemparan di listener
  // onInstalled/onStartup tidak punya siapa-siapa yang menangkapnya — yang
  // kelihatan cuma galat tanpa pesan di chrome://extensions.
  try {
    const url = await portal()

    await chrome.scripting.unregisterContentScripts({ ids: ['fill'] }).catch(() => {})

    if (!await chrome.permissions.contains({ origins: [`${url}/*`] })) return

    await chrome.scripting.registerContentScripts([{
      id: 'fill',
      matches: [`${url}/suggestions*`],
      js: ['fill.js'],
      runAt: 'document_idle',
    }])
  } catch (e) {
    console.warn('pendaftaran fill.js dilewat:', e.message)
  }
}

chrome.runtime.onInstalled.addListener(pasang)
chrome.runtime.onStartup.addListener(pasang)

chrome.runtime.onMessage.addListener((msg, sender, balas) => {
  if (msg.pasang) {
    pasang().then(() => balas(true))

    return true
  }

  // Gambar diambil di sini, bukan di content script: service worker punya
  // host_permissions ke scontent jadi CORS tidak berlaku. fetch dari halaman
  // localhost akan kena preflight CDN.
  if (msg.image) {
    fetch(msg.image)
      .then((r) => r.arrayBuffer())
      .then((buf) => {
        // btoa butuh binary string. FileReader tidak ada di service worker.
        const bytes = new Uint8Array(buf)
        let s = ''
        for (let i = 0; i < bytes.length; i += 8192) {
          s += String.fromCharCode.apply(null, bytes.subarray(i, i + 8192))
        }
        balas('data:image/jpeg;base64,' + btoa(s))
      })
      .catch(() => balas(null))

    return true
  }

  if (msg.panen) {
    chrome.scripting
      .executeScript({ target: { tabId: msg.panen }, func: panen })
      .then(([{ result }]) => balas(result))
      .catch(() => balas(null))

    return true
  }

  // Tab tidak bisa menutup dirinya sendiri: window.close() cuma jalan untuk tab
  // yang dibuka script. Yang punya wewenangnya chrome.tabs, dan itu di sini.
  if (msg.tutup && sender.tab) {
    chrome.tabs.remove(sender.tab.id).catch(() => {})

    return
  }

  if (msg.kirim) {
    kirim(msg.kirim).then(balas)

    return true
  }
})

// Kirim lewat tab tersembunyi ke /suggestions, bukan fetch langsung dari sini:
// yang dibutuhkan cookie sesi + token CSRF halaman itu, dan dua-duanya cuma ada
// di origin portalnya. Konsekuensinya tidak ada kredensial kedua yang perlu
// disimpan extension — syarat "harus login" ditegakkan middleware `auth` yang
// sudah ada, dan gagalnya kelihatan sebagai lemparan ke /login.
async function kirim(data) {
  const url = await portal()

  if (!await chrome.permissions.contains({ origins: [`${url}/*`] })) {
    return { ok: false, pesan: 'belum-diatur' }
  }

  await chrome.storage.local.set({ pending: { ...data, auto: true } })

  const tab = await chrome.tabs.create({ url: `${url}/suggestions`, active: false })

  return new Promise((selesai) => {
    const tutup = (hasil) => {
      chrome.runtime.onMessage.removeListener(dengar)
      chrome.tabs.onUpdated.removeListener(cek)
      chrome.tabs.remove(tab.id).catch(() => {})
      selesai(hasil)
    }

    const dengar = (m, pengirim) => {
      if (pengirim.tab?.id === tab.id && m.hasil) tutup(m.hasil)
    }

    // Belum login = middleware melempar ke /login, dan fill.js tidak pernah jalan
    // di sana — tanpa cek ini popupnya menggantung selamanya.
    const cek = (id, info, t) => {
      if (id === tab.id && info.status === 'complete' && t.url.includes('/login')) {
        chrome.storage.local.remove('pending')
        tutup({ ok: false, pesan: 'belum-login' })
      }
    }

    chrome.runtime.onMessage.addListener(dengar)
    chrome.tabs.onUpdated.addListener(cek)

    // Jaring terakhir. Apa pun yang bikin fill.js mati sebelum sempat lapor
    // (exception, halaman berubah, unduhan gambar menggantung) meninggalkan tab
    // yatim yang harus ditutup tangan dan popup yang menunggu selamanya.
    setTimeout(() => tutup({ ok: false, pesan: 'Portal tidak menjawab. Coba lagi, atau kirim manual lewat /suggestions.' }), 90_000)
  })
}

// Jalan di dalam halaman Instagram.
async function panen() {
  const tunggu = (ms) => new Promise((r) => setTimeout(r, ms))
  // Dialog dulu. Post yang dibuka dari grid profil (URL /{handle}/p/{kode}/)
  // tampil sebagai overlay di atas halaman profil, dan `article` pertama di
  // dokumen itu grid-nya — memanennya berarti puluhan thumbnail post lain.
  const art = document.querySelector('div[role="dialog"] article')
    || document.querySelector('div[role="dialog"]')
    || document.querySelector('article')
    || document.body

  // Caption dipotong di DOM juga, bukan cuma secara visual — tombol "more"
  // harus diklik dulu kalau tidak yang terkirim cuma dua barisnya.
  for (const b of art.querySelectorAll('span[role="button"], button, div[role="button"]')) {
    if (/^(…\s*)?(more|lainnya|selengkapnya)$/i.test(b.textContent.trim())) {
      b.click()
      await tunggu(200)
    }
  }

  const waktu = art.querySelector('time[datetime]')?.getAttribute('datetime') || ''

  // Username diambil dari bentuk href-nya, bukan dari posisi di DOM: Instagram
  // tidak lagi membungkus baris penulis dengan <header> dan semua class-nya
  // diacak. Link profil selalu `/<handle>/` satu segmen — sisanya rute internal.
  const rute = new Set(['p', 'reel', 'reels', 'explore', 'stories', 'direct', 'accounts', 'tv', 'about', 'legal'])
  const kandidat = [...new Set(
    [...(art.querySelectorAll('a[href^="/"]').length ? art : document).querySelectorAll('a[href^="/"]')]
      .map((a) => a.getAttribute('href').match(/^\/([A-Za-z0-9._]+)\/?$/)?.[1])
      .filter((u) => u && !rute.has(u))
  )]

  // Slide carousel baru dimuat setelah tombol next diklik — tanpa loop ini yang
  // kebawa cuma slide pertama. 20 = batas `images` di PostController::store.
  const urls = new Map()
  const pungut = () => {
    for (const img of art.querySelectorAll('img[src*="scontent"], img[src*="cdninstagram"]')) {
      // Avatar dan thumbnail sidebar ikut kejaring kalau tidak disaring ukuran.
      if (img.naturalWidth < 320) continue

      // Video dilewat, aturan yang sama dengan probe.php: thumbnail-nya cuma satu
      // frame yang belum tentu memuat flyer, rawan jadi ekstraksi ngawur. Yang
      // disaring elemennya, bukan URL-nya — poster video juga <img> dari scontent.
      // Slide carousel itu <li>, jadi di carousel campuran child videonya saja
      // yang dibuang dan child gambarnya tetap diambil.
      if ((img.closest('li') || art).querySelector('video')) continue

      urls.set(img.src.split('?')[0], img.src)
    }
  }

  pungut()
  for (let i = 0; i < 20; i++) {
    const next = art.querySelector('button[aria-label="Next"], button[aria-label="Berikutnya"]')
    if (!next) break
    next.click()
    await tunggu(700)
    pungut()
  }

  // Permalink dari URL kalau memang halaman post; kalau postnya dibuka sebagai
  // overlay di atas grid profil, URL-nya belum tentu ikut berubah — link tanggal
  // di dalam dialog yang menyimpannya. Bentuk berhandel (/{handle}/p/{kode}/)
  // dan bentuk pendek sama-sama sah, mediaIdOf() menerima dua-duanya.
  const pola = /instagram\.com\/(?:[\w.]+\/)?(?:p|reel|tv)\/[\w-]+/
  const permalink = location.href.match(pola)?.[0]
    || [...art.querySelectorAll('a[href]')].map((a) => a.href).find((h) => pola.test(h))
    || ''

  return {
    permalink,
    // Post kolaborasi punya dua penulis. Yang pertama pemiliknya — itu yang jadi
    // `source_account` (penunjuk folder raw + asal ekstraksi). Sisanya cuma
    // ditawarkan sebagai pilihan; tempat mereka yang benar kolom `reposts`,
    // terisi sendiri kalau akun itu ikut di-scrap.
    account: kandidat[0] || '',
    kandidat,
    caption: art.querySelector('h1')?.innerText
      || document.querySelector('meta[property="og:description"]')?.content
      || '',
    // Input formnya type=date. datetime IG itu UTC; pakai tanggal lokal supaya
    // post malam hari tidak mundur sehari.
    posted_at: waktu ? new Date(waktu).toLocaleDateString('sv-SE') : '',
    images: [...urls.values()].slice(0, 20),
    video: !!art.querySelector('video'),
  }
}
