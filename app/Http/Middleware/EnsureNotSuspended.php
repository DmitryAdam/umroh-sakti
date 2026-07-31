<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Penangguhan harus digigit tiap request, bukan cuma saat login: sesi hidup 120
 * menit dan `remember` memperpanjangnya berbulan-bulan, jadi cek yang cuma ada di
 * callback berarti orang yang ditangguhkan tetap jalan sampai cookienya mati.
 *
 * Dipasang di grup `web` (bootstrap/app.php), bukan di grup route `auth`: ada dua
 * grup `auth` di routes/web.php dan halaman publik pun merender menu operator kalau
 * yang membuka sedang login. Satu tempat, tidak ada yang bisa kelewat.
 */
class EnsureNotSuspended
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::user()?->isSuspended()) {
            return $next($request);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Akun ini ditangguhkan. Hubungi admin.');
    }
}
