<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\FlyerThumbController;
use App\Http\Controllers\PackageSearchController;
use App\Http\Controllers\PipelineController;
use App\Http\Controllers\PostController;
use Illuminate\Support\Facades\Route;

/*
 * Dua lapis saja: publik dan operator.
 *
 * Publik = pencarian paket published + thumbnailnya. Sisanya (alat kerja: daftar
 * akun, panel pipeline, tombol aksi per kartu, foto profil akun) ada di grup
 * `auth` di bawah. Gerbangnya login, bukan `app()->isLocal()` seperti dulu —
 * env sebagai kunci berarti alat kerjanya ikut mati begitu di-deploy.
 *
 * Kuncinya di route, bukan di dalam tiap controller: satu tempat untuk dilihat,
 * dan method baru di controller yang sudah ada ikut terkunci tanpa perlu ingat
 * menambahkan `abort_unless`.
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

// Namanya wajib `login`: itu yang dituju middleware `auth` saat menolak tamu.
Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
// Throttle di sini, bukan di dalam controller: 5 percobaan per menit per IP.
// Tanpa itu form sesederhana ini jadi tebakan sandi gratis.
Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:5,1');
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

Route::middleware('auth')->group(function () {
    Route::post('/packages/{package}/feedback', [PackageSearchController::class, 'feedback'])->name('package.feedback');
    Route::delete('/packages/{package}', [PackageSearchController::class, 'destroy'])->name('package.destroy');
    Route::patch('/packages/{package}/status', [PackageSearchController::class, 'status'])->name('package.status');
    Route::post('/packages/{package}/extract', [PackageSearchController::class, 'reextract'])->name('package.reextract');
    Route::post('/packages/{package}/fetch', [PackageSearchController::class, 'refetch'])->name('package.refetch');

    Route::get('/accounts', [AccountController::class, 'index'])->name('accounts');
    Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::post('/accounts/crawl', [AccountController::class, 'fetchAll'])->name('accounts.crawl');
    Route::post('/accounts/bulk', [AccountController::class, 'bulk'])->name('accounts.bulk');
    // Halaman post, dua ruang lingkup satu controller: seluruh akun dan satu akun.
    // Aksinya (di bawah) sengaja tidak dilingkupi akun — `media_id` sudah unik dan
    // PostController mencari akunnya sendiri, jadi tombol yang sama jalan di dua
    // halaman itu tanpa jalur kedua.
    Route::get('/posts', [PostController::class, 'index'])->name('posts');
    // Tambah post manual: yang tidak bisa dijangkau `fetch` (pinned lama, di luar
    // --limit, akun yang belum di-scrap) dimasukkan lewat form — gambar + caption +
    // permalink. Sesudahnya jalur normal: ExtractPost -> ImportPackages -> review.
    Route::get('/posts/create', [PostController::class, 'create'])->name('posts.create');
    Route::post('/posts', [PostController::class, 'store'])->name('posts.store');
    Route::get('/accounts/{account}/posts', [PostController::class, 'index'])->name('accounts.posts');
    // Aksi kelompok per post yang dicentang: `extract` (baca ulang) / `block`
    // (vonis manusia "bukan paket", baru di sini filenya dibuang) / `unblock`.
    Route::post('/posts/bulk', [PostController::class, 'bulk'])->name('posts.bulk');
    // Baca ulang satu post: buang blok `excluded_posts`, hapus jejak bacaan lama,
    // lalu extract (ditolak kalau rawnya sudah dihapus).
    Route::post('/posts/{media}/extract', [PostController::class, 'reextract'])
        ->whereNumber('media')->name('posts.reextract');
    Route::get('/posts/{media}/{index}.jpg', [PostController::class, 'raw'])
        ->whereNumber(['media', 'index'])->name('posts.raw');
    Route::post('/accounts/{account}/fetch', [AccountController::class, 'fetch'])->name('accounts.fetch');
    Route::post('/accounts/{account}/block', [AccountController::class, 'block'])->name('accounts.block');
    Route::delete('/accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');

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
