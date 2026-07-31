<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Satu pintu masuk operator. Tidak ada pendaftaran, tidak ada reset lewat email,
 * tidak ada peran — akunnya dibuat dari CLI (`php artisan user:create`).
 *
 * Dulu semua alat kerja dikunci `abort_unless(app()->isLocal())`. Itu cukup selama
 * portalnya cuma jalan di laptop, tapi begitu di-deploy `/akun` jadi 404 buat
 * pemiliknya sendiri. Gerbangnya sekarang login, bukan env — lihat routes/web.php.
 *
 * Nama kelas + methodnya ikut konvensi Laravel (Breeze): sesi login itu resource
 * biasa — `create` menampilkan formnya, `store` membuatnya, `destroy` mengakhirinya.
 */
class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            // Pesannya sengaja tidak membedakan "email tidak ada" dari "sandi salah":
            // yang pertama itu daftar email valid, gratis buat siapa pun yang mencoba.
            throw ValidationException::withMessages(['email' => 'Email atau sandi salah.']);
        }

        // Wajib: tanpa ini session id sebelum login tetap dipakai (session fixation).
        $request->session()->regenerate();

        return redirect()->intended(route('accounts'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('search');
    }
}
