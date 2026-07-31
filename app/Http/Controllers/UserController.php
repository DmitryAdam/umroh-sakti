<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Daftar pengguna. Sengaja cuma bisa MENANGGUHKAN — tidak ada tombol naik/turun
 * peran dan tidak ada tombol hapus.
 *
 * Alasannya: pendaftaran terbuka (siapa pun yang punya akun Google bisa masuk
 * sebagai `user`), jadi halaman ini akan panjang dan penuh nama yang tidak dikenal.
 * Satu klik salah di baris yang salah = kuota Graph dan tagihan model diserahkan ke
 * orang asing. Menaikkan seseorang jadi admin itu keputusan yang layak dibayar
 * dengan membuka SQLite:
 *
 *     php artisan tinker
 *     >>> App\Models\User::where('email','...')->update(['role' => 'admin']);
 *
 * Menghapus baris juga tidak ada gunanya: orangnya tinggal login lagi lewat Google
 * dan barisnya lahir kembali. Yang benar-benar menahan itu `suspended_at`.
 */
class UserController extends Controller
{
    public function index(): View
    {
        // Yang ditangguhkan di atas (itu yang perlu ditinjau), lalu admin, lalu
        // sisanya terbaru dulu — pendaftar baru yang perlu dinilai ada di situ.
        $users = User::orderByRaw('suspended_at is null')
            ->orderByRaw("role = 'admin' desc")
            ->orderByDesc('id')
            ->paginate(60);

        return view('users', ['users' => $users]);
    }

    /**
     * Tangguhkan / aktifkan. Diri sendiri ditolak: satu-satunya admin yang
     * menangguhkan dirinya berarti tidak ada lagi yang bisa membatalkannya —
     * perbaikannya cuma lewat SQLite, dan itu bukan hal yang boleh dicapai
     * dengan satu klik.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->with('status', 'Tidak bisa menangguhkan akun sendiri.');
        }

        $user->update(['suspended_at' => $request->boolean('suspended') ? now() : null]);

        return back()->with('status', $user->isSuspended()
            ? "{$user->email} ditangguhkan — sesinya langsung diputus."
            : "{$user->email} diaktifkan lagi.");
    }
}
