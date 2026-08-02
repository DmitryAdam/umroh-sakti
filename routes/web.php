<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\FlyerThumbController;
use App\Http\Controllers\PackageSearchController;
use App\Http\Controllers\PipelineController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
 * Tiga lapis: publik, user yang login, admin.
 *
 * Publik = pencarian paket published + thumbnailnya.
 * `auth` = usulan (akun/post yang belum kita lacak) — tidak menjalankan apa pun,
 * cuma menyimpan dan menunggu approval.
 * `auth` + `can:admin` = seluruh alat kerja: daftar akun, panel pipeline, review
 * paket, approval usulan.
 *
 * Kuncinya di route, bukan di dalam tiap controller: satu tempat untuk dilihat,
 * dan method baru di controller yang sudah ada ikut terkunci tanpa perlu ingat
 * menambahkan `abort_unless`. Gerbangnya login + peran, bukan `app()->isLocal()`
 * seperti dulu — env sebagai kunci berarti alat kerjanya ikut mati begitu di-deploy.
 */

/*
 * URI + nama route + query param semuanya bahasa Inggris: itu permukaan yang
 * dibaca browser, bookmark, log, dan orang lain. Label UI, pesan, dan komentar
 * tetap Indonesia — itu bahasa produknya.
 */

Route::get('/', [PackageSearchController::class, 'index'])->name('search');
Route::get('/packages/{package}', [PackageSearchController::class, 'show'])->name('package.show');
Route::get('/flyers/{media}/{index}.jpg', FlyerThumbController::class)
    ->whereNumber(['media', 'index'])
    ->name('flyer');

/*
 * Login cuma satu jalur: Google SSO. Tidak ada form sandi, tidak ada pendaftaran
 * terpisah — masuk pertama kali sekaligus membuat barisnya sebagai peran `user`.
 *
 * Namanya wajib `login`: itu yang dituju middleware `auth` saat menolak tamu.
 */
Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
// Throttle di sini, bukan di dalam controller: 5 percobaan per menit per IP.
Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:5,1');
// Google mengembalikan orangnya ke sini lewat GET; `state` di sesi yang menahan
// permintaan yang bukan berasal dari tombol di /login.
Route::get('/login/callback', [AuthenticatedSessionController::class, 'callback'])->name('login.callback');
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

/*
 * Usulan: satu halaman, dua form (akun + post), dipakai kedua peran. Bedanya cuma
 * akibatnya — kiriman admin langsung jalan, kiriman user menunggu approval. Itu
 * ditentukan di controllernya, bukan di route kedua: jalur ganda berarti dua
 * tempat yang bisa menyimpang.
 */
Route::middleware('auth')->group(function () {
    Route::get('/suggestions', [PostController::class, 'create'])->name('suggestions');
    Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::post('/posts', [PostController::class, 'store'])->name('posts.store');
    // Gambar mentah: dipakai daftar "usulan saya" untuk menampilkan kiriman sendiri.
    Route::get('/posts/{media}/{index}.jpg', [PostController::class, 'raw'])
        ->whereNumber(['media', 'index'])->name('posts.raw');
    // Hapus kiriman sendiri. Di `auth`, bukan `can:admin`: yang mengusulkan harus
    // bisa menarik kirimannya sendiri. Batasannya di controller (pemilik + belum
    // di-approve), bukan di route — perannya sama, hakikat barisnya yang beda.
    Route::delete('/posts/{media}', [PostController::class, 'destroy'])
        ->whereNumber('media')->name('posts.destroy');
    // Chrome extension, dirakit dari folder `extension/` saat diunduh. Di `auth`
    // dan bukan `can:admin`: peran `user` juga mengirim post lewat alat ini.
    //
    // Halamannya sendiri, bukan cuma tautan unduh: paketnya zip yang harus di-Load
    // unpacked, dan langkahnya tidak bisa ditebak oleh yang mengunduh.
    Route::view('/extension', 'extension')->name('extension');
    Route::get('/extension.zip', [PostController::class, 'extension'])->name('extension.download');
});

Route::middleware(['auth', 'can:admin'])->group(function () {
    Route::post('/packages/{package}/feedback', [PackageSearchController::class, 'feedback'])->name('package.feedback');
    Route::delete('/packages/{package}', [PackageSearchController::class, 'destroy'])->name('package.destroy');
    Route::patch('/packages/{package}/status', [PackageSearchController::class, 'status'])->name('package.status');
    Route::post('/packages/{package}/extract', [PackageSearchController::class, 'reextract'])->name('package.reextract');
    Route::post('/packages/{package}/fetch', [PackageSearchController::class, 'refetch'])->name('package.refetch');

    Route::get('/accounts', [AccountController::class, 'index'])->name('accounts');
    Route::post('/accounts/crawl', [AccountController::class, 'fetchAll'])->name('accounts.crawl');
    Route::post('/accounts/bulk', [AccountController::class, 'bulk'])->name('accounts.bulk');
    // Halaman post, dua ruang lingkup satu controller: seluruh akun dan satu akun.
    // Aksinya (di bawah) sengaja tidak dilingkupi akun — `media_id` sudah unik dan
    // PostController mencari akunnya sendiri, jadi tombol yang sama jalan di dua
    // halaman itu tanpa jalur kedua.
    Route::get('/posts', [PostController::class, 'index'])->name('posts');
    Route::get('/accounts/{account}/posts', [PostController::class, 'index'])->name('accounts.posts');
    // Aksi kelompok per post yang dicentang: `extract` (baca ulang) / `block`
    // (vonis manusia "bukan paket", baru di sini filenya dibuang) / `unblock`.
    Route::post('/posts/bulk', [PostController::class, 'bulk'])->name('posts.bulk');
    // Saklar auto-approve usulan (tab usulan). Admin saja: ini menentukan nasib
    // kiriman semua orang, bukan setelan tampilan yang boleh per-browser.
    Route::post('/posts/auto-approve', [PostController::class, 'autoApprove'])->name('posts.auto-approve');
    // Baca ulang satu post: buang blok `excluded_posts`, hapus jejak bacaan lama,
    // lalu extract (ditolak kalau rawnya sudah dihapus).
    Route::post('/posts/{media}/extract', [PostController::class, 'reextract'])
        ->whereNumber('media')->name('posts.reextract');
    Route::post('/accounts/{account}/fetch', [AccountController::class, 'fetch'])->name('accounts.fetch');
    Route::post('/accounts/{account}/block', [AccountController::class, 'block'])->name('accounts.block');
    Route::delete('/accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');

    // Daftar pengguna. Cuma tangguhkan/aktifkan — peran diubah lewat SQLite,
    // sengaja: satu klik salah di sini = kuota Graph + tagihan model diserahkan.
    Route::get('/users', [UserController::class, 'index'])->name('users');
    Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');

    Route::delete('/pipeline/queue/{queue?}', [PipelineController::class, 'clear'])->name('pipeline.clear');
    // Parameter opsional wajib di segmen terakhir, jadi kata kerjanya di depan —
    // `queue/{queue?}/retry` tidak pernah cocok untuk bentuk tanpa antrian.
    Route::post('/pipeline/queue/retry/{queue?}', [PipelineController::class, 'retry'])->name('pipeline.retry');
    Route::get('/pipeline/status', [PipelineController::class, 'status'])->name('pipeline.status');

    // Foto profil hasil download probe.php. Di luar public/ supaya tidak ikut
    // ter-deploy sebagai aset publik — ini alat kerja, sama seperti /accounts.
    Route::get('/avatar/{username}.jpg', function (string $username) {
        abort_unless(is_file($path = storage_path("profiles/$username.jpg")), 404);

        return response()->file($path);
    })->where('username', '[A-Za-z0-9._]+')->name('avatar');
});
