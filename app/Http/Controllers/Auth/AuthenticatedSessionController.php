<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Satu pintu masuk: Google SSO. Tidak ada form sandi, tidak ada reset lewat email,
 * tidak ada gerbang kedua — kalau Google-nya mati, tidak ada yang masuk, dan itu
 * memang pilihan sadar. Alternatifnya (sandi cadangan) berarti satu jalur lagi yang
 * bisa ditebak, dan itu justru gerbang yang paling gampang jebol.
 *
 * Dikerjakan tangan, bukan Socialite: authorization code flow untuk confidential
 * client itu tiga request (authorize -> token -> userinfo), dan satu paket lagi buat
 * tiga request adalah dependency yang tidak dibayar. Yang tidak boleh dilewat cuma
 * `state` (kalau tidak, siapa pun bisa memaksa korban login ke akun penyerang) dan
 * `email_verified`.
 *
 * ID token-nya sengaja tidak diverifikasi tanda tangannya: kodenya ditukar langsung
 * ke oauth2.googleapis.com lewat TLS dengan client_secret kita, jadi balasannya
 * sudah terpercaya tanpa cek JWT lagi. Verifikasi JWT baru perlu kalau tokennya
 * datang lewat browser (implicit flow), dan itu tidak dipakai di sini.
 *
 * Nama kelas + methodnya ikut konvensi Laravel (Breeze): sesi login itu resource
 * biasa — `create` menampilkan pintunya, `store` membukanya, `destroy` mengakhirinya.
 */
class AuthenticatedSessionController extends Controller
{
    private const AUTHORIZE = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN = 'https://oauth2.googleapis.com/token';

    private const USERINFO = 'https://www.googleapis.com/oauth2/v3/userinfo';

    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Berangkat ke Google. POST, bukan GET: rutenya sudah ber-CSRF dan
     * `throttle:5,1`, jadi tidak ada yang bisa memancing orang lain memulai
     * login lewat sebuah <img> atau tautan.
     */
    public function store(Request $request): RedirectResponse
    {
        if (! config('services.google.client_id')) {
            return back()->with('status', 'Login Google belum dikonfigurasi (GOOGLE_CLIENT_ID kosong).');
        }

        $request->session()->put('google_state', $state = Str::random(40));

        return redirect()->away(self::AUTHORIZE.'?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            // Google menampilkan pemilih akun; tanpa ini sesi Google yang sudah ada
            // langsung dipakai dan orang yang punya dua akun tidak pernah bisa pindah.
            'prompt' => 'select_account',
        ]));
    }

    public function callback(Request $request): RedirectResponse
    {
        // `pull`, bukan `get`: state sekali pakai. Kalau ditinggal, kode yang bocor
        // dari log/referer masih bisa diputar ulang selama sesinya hidup.
        $state = $request->session()->pull('google_state');

        if (! $request->filled('code') || ! $state || ! hash_equals($state, (string) $request->query('state'))) {
            return $this->tolak('Login dibatalkan atau kedaluwarsa. Coba lagi.');
        }

        $token = Http::asForm()->post(self::TOKEN, [
            'code' => $request->query('code'),
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code',
        ]);

        $profil = $token->successful()
            ? Http::withToken($token->json('access_token'))->get(self::USERINFO)
            : null;

        if (! $profil?->successful() || ! $profil->json('email')) {
            return $this->tolak('Google tidak membalas dengan benar. Coba lagi sebentar lagi.');
        }

        // Email yang belum diverifikasi = siapa pun bisa mendaftarkan alamat orang
        // lain di Google Workspace-nya sendiri lalu masuk sebagai dia.
        if (! $profil->json('email_verified')) {
            return $this->tolak('Email Google ini belum terverifikasi.');
        }

        $user = $this->tautkan($profil->json());

        if ($user->isSuspended()) {
            return $this->tolak('Akun ini ditangguhkan. Hubungi admin.');
        }

        Auth::login($user, remember: true);
        // Wajib: tanpa ini session id sebelum login tetap dipakai (session fixation).
        $request->session()->regenerate();

        return redirect()->intended(route($user->isAdmin() ? 'accounts' : 'suggestions'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('search');
    }

    /**
     * Baris dicari lewat email, bukan `google_id`: baris yang dibuat migrasi (admin
     * pertama) dan operator lama belum pernah punya `sub`, jadi cari lewat id-nya
     * saja bikin mereka lahir kembar dan kehilangan peran adminnya.
     *
     * Pendaftar baru selalu `user`. Peran tidak pernah ditulis ulang untuk baris
     * yang sudah ada — kalau tidak, login berikutnya menurunkan adminnya sendiri.
     */
    private function tautkan(array $profil): User
    {
        $user = User::firstOrNew(['email' => $profil['email']]);

        $user->fill([
            'google_id' => $profil['sub'] ?? null,
            'name' => $profil['name'] ?? null,
        ]);
        $user->role ??= 'user';
        $user->save();

        return $user;
    }

    private function tolak(string $pesan): RedirectResponse
    {
        return redirect()->route('login')->with('status', $pesan);
    }

    private function redirectUri(): string
    {
        return config('services.google.redirect') ?: route('login.callback');
    }
}
