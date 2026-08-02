// Alamat portalnya diatur di halaman options, bukan ditulis di sini: portalnya
// dipasang sendiri-sendiri, jadi satu alamat tetap cuma benar buat satu orang.
// Yang di bawah cuma nilai awal untuk pasangan baru — begitu options disimpan,
// chrome.storage yang menang dan nilai ini tidak pernah dibaca lagi.
const portal = async () => (await chrome.storage.sync.get('portal')).portal || 'https://umrohsakti.my.id'

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

  if (msg.grid) {
    chrome.scripting
      .executeScript({ target: { tabId: msg.grid }, func: kumpulLink })
      .then(([{ result }]) => balas(result))
      .catch(() => balas([]))

    return true
  }

  if (msg.statusGrid) {
    balas(antre)

    return
  }

  // Berhenti di sela post, bukan di tengahnya: post yang sedang dikirim sudah
  // menulis raw di portal, dan membatalkannya di tengah jalan berarti folder
  // setengah jadi yang tidak ada yang membereskan.
  if (msg.stopGrid) {
    if (antre) antre.batal = true
    balas(antre)

    return
  }

  if (msg.mulaiGrid) {
    if (antre?.jalan) return balas(antre)

    jalanGrid(msg.mulaiGrid.tabId, msg.mulaiGrid.links)
    balas(antre)

    return
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

// Mode grid: satu tab dipakai ulang untuk membuka post satu per satu, lalu tiap
// post lewat panen() + kirim() yang sama persis dengan tombol satuan — tidak ada
// jalur kirim kedua yang bisa menyimpang. Loopnya di service worker, bukan di
// popup: satu putaran 9 post makan menit-menitan dan popup Chrome menutup diri
// begitu operator mengklik apa pun di luarnya.
//
// Post dibuka lewat navigasi tab, bukan klik thumbnail: dialog overlay bergantung
// pada markup grid yang class-nya diacak, sementara URL permalinknya sudah ada di
// href tiap tile.
let antre = null

async function jalanGrid(tabId, links) {
  const asal = (await chrome.tabs.get(tabId).catch(() => ({}))).url
  antre = { total: links.length, ke: 0, ok: 0, lewat: 0, gagal: [], jalan: true }

  // Dicek sekali di depan, bukan per post: syarat yang sama untuk seluruh putaran,
  // dan kalau tidak, sembilan post dibuka satu-satu cuma untuk gagal dengan sebab
  // yang identik — belasan page load IG terbuang untuk galat yang sudah pasti.
  if (!await chrome.permissions.contains({ origins: [`${await portal()}/*`] })) {
    antre.jalan = false
    antre.gagal.push('Alamat portal belum diatur. Buka pengaturan extension, isi alamatnya, lalu izinkan.')
    lapor()

    return
  }

  for (const url of links) {
    if (antre.batal) break

    antre.ke++
    lapor()

    try {
      await chrome.tabs.update(tabId, { url })
      await tungguMuat(tabId)
      // IG merender isi postnya sesudah `complete`; tanpa jeda ini panen() membaca
      // kerangka kosong dan postnya terhitung "gambar tidak terbaca".
      await new Promise((r) => setTimeout(r, 1500))

      const [{ result: data }] = await chrome.scripting.executeScript({ target: { tabId }, func: panen })

      // Video/reel dan post yang gambarnya tidak terbaca dilewat, bukan digagalkan:
      // pipeline memang tidak memprosesnya.
      if (!data?.images?.length) {
        antre.lewat++
        continue
      }

      const hasil = await kirim(data)

      // Post yang sudah ada ditolak portal, dan itu jawaban yang benar — bukan
      // kegagalan. Tanpa dipisah, putaran kedua atas profil yang sama tampil
      // sebagai sembilan galat merah.
      if (!hasil?.ok && /sudah ada di sistem/.test(hasil?.pesan || '')) antre.lewat++
      else hasil?.ok ? antre.ok++ : antre.gagal.push(`${kode(url)}: ${hasil?.pesan || 'gagal'}`)
    } catch (e) {
      antre.gagal.push(`${kode(url)}: ${e.message}`)
    }
  }

  antre.jalan = false
  lapor()
  if (asal) chrome.tabs.update(tabId, { url: asal }).catch(() => {})
}

const kode = (url) => url.match(/\/(?:p|reel|tv)\/([\w-]+)/)?.[1] || url

// Kemajuannya menempel di badge ikonnya, bukan cuma di popup: popup Chrome menutup
// diri begitu operator mengklik apa pun di luarnya, sementara putarannya jalan
// menit-menitan — tanpa badge satu-satunya cara tahu masih jalan atau tidak itu
// membuka popupnya lagi. Badge cuma muat ~4 karakter, jadi yang dipasang sisa
// postnya (selalu pendek); kalimat penuhnya di tooltip.
const lapor = () => {
  const sisa = antre.total - antre.ke
  const kepala = antre.jalan
    ? `Post ${antre.ke}/${antre.total}…`
    : (antre.batal ? `Dihentikan di post ${antre.ke}/${antre.total}.` : `Selesai, ${antre.total} post.`)

  chrome.action.setBadgeText({ text: antre.jalan ? String(sisa + 1) : (antre.gagal.length ? '!' : '✓') })
  chrome.action.setBadgeBackgroundColor({ color: antre.jalan ? '#2563eb' : (antre.gagal.length ? '#dc2626' : '#16a34a') })
  chrome.action.setTitle({
    title: `${kepala} ${antre.ok} terkirim, ${antre.lewat} dilewat, ${antre.gagal.length} gagal.`,
  })

  // Popup boleh tidak ada — pesannya dilempar ke ruang kosong dan itu memang wajar.
  chrome.runtime.sendMessage({ kabarGrid: antre }).catch(() => {})
}

function tungguMuat(tabId) {
  return new Promise((selesai) => {
    const habis = setTimeout(beres, 30_000)

    function beres() {
      clearTimeout(habis)
      chrome.tabs.onUpdated.removeListener(cek)
      selesai()
    }

    function cek(id, info) {
      if (id === tabId && info.status === 'complete') beres()
    }

    chrome.tabs.onUpdated.addListener(cek)
  })
}

// Jalan di halaman grid profil. Cuma `/p/` — reel dan tv itu video, dan satu
// halaman IG yang dimuat percuma jauh lebih mahal daripada satu href yang dibuang.
function kumpulLink() {
  return [...new Set(
    [...document.querySelectorAll('a[href*="/p/"]')]
      .map((a) => a.getAttribute('href'))
      .filter((h) => /^\/(?:[\w.]+\/)?p\/[\w-]+/.test(h || ''))
      .map((h) => new URL(h, location.origin).href)
  )]
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
  const anchor = [...(art.querySelectorAll('a[href^="/"]').length ? art : document).querySelectorAll('a[href^="/"]')]
    .map((a) => ({ a, u: a.getAttribute('href').match(/^\/([A-Za-z0-9._]+)\/?$/)?.[1] }))
    .filter((x) => x.u && !rute.has(x.u))

  // Urut DOM saja tidak cukup: di halaman post penuh `art` jatuh ke body, dan link
  // profil PERTAMA di dokumen itu nav kiri — akun yang sedang login, bukan penulis
  // postnya. (Kejadian: semua usulan tercatat dari akun operatornya sendiri.)
  // Jangkarnya `time` postingan: baris penulis dan jam posting bertetangga di DOM,
  // sementara nav dan sidebar saran jauh dari keduanya.
  const jam = art.querySelector('time[datetime]')
  if (jam) {
    const urutan = new Map([...document.querySelectorAll('*')].map((el, i) => [el, i]))
    const jarak = (el) => Math.abs((urutan.get(el) ?? 1e9) - (urutan.get(jam) ?? 0))
    anchor.sort((x, y) => jarak(x.a) - jarak(y.a))
  }

  const kandidat = [...new Set(anchor.map((x) => x.u))]

  // Slide carousel baru dimuat setelah tombol next diklik — tanpa loop ini yang
  // kebawa cuma slide pertama. 20 = batas `images` di PostController::store.
  const urls = new Map()
  let dilihat = 0
  let kekecilan = 0
  const pungut = () => {
    for (const img of art.querySelectorAll('img[src*="scontent"], img[src*="cdninstagram"]')) {
      dilihat++

      // Avatar dan thumbnail sidebar ikut kejaring kalau tidak disaring ukuran.
      // `naturalWidth` 0 = belum selesai decode, bukan gambar kecil — popup dibuka
      // seketika sesudah halaman muncul, jadi tanpa fallback ke lebar layout SEMUA
      // gambarnya kebuang dan postnya kelihatan "tidak terbaca".
      if ((img.naturalWidth || img.width) < 320) {
        kekecilan++
        continue
      }

      // Video dilewat, aturan yang sama dengan probe.php: thumbnail-nya cuma satu
      // frame yang belum tentu memuat flyer, rawan jadi ekstraksi ngawur. Yang
      // disaring elemennya, bukan URL-nya — poster video juga <img> dari scontent.
      // Slide carousel itu <li>, jadi di carousel campuran child videonya saja
      // yang dibuang dan child gambarnya tetap diambil.
      if ((img.closest('li') || art).querySelector('video')) continue

      urls.set(img.src.split('?')[0], img.src)
    }
  }

  // Gambar yang belum termuat tidak punya ukuran apa pun. Ditunggu sebentar, bukan
  // sampai selesai: satu gambar yang gagal dari CDN tidak boleh menahan panen.
  await Promise.race([
    Promise.all([...art.querySelectorAll('img')].filter((i) => !i.complete)
      .map((i) => new Promise((r) => i.addEventListener('load', r, { once: true })))),
    tunggu(3000),
  ])

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
    // Cuma untuk pesan galat: "0 gambar" saja tidak membedakan markup IG yang
    // berubah (nol kandidat) dari saringan kita yang kelewat galak (semua kebuang).
    jejak: `${dilihat} kandidat, ${kekecilan} kekecilan`,
  }
}
