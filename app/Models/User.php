<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Dua peran, kolom `role`:
 *
 * - `admin` — semua alat kerja: daftar akun, pipeline, review paket, approval usulan,
 *   daftar pengguna.
 * - `user`  — cuma /suggestions: mengusulkan akun sumber atau post yang tidak
 *   terjangkau fetch. Usulannya tidak menjalankan apa pun sampai admin menyetujui,
 *   jadi peran ini tidak bisa membakar kuota Graph maupun membayar model.
 *
 * Yang mendaftar lewat Google SELALU lahir sebagai `user`. Naik jadi `admin` cuma
 * lewat SQLite/tinker — sengaja tidak ada tombolnya, karena satu klik salah di
 * halaman pengguna berarti menyerahkan kuota Graph dan tagihan model.
 *
 * Tidak ada peran ketiga dan tidak ada tabel izin: gerbangnya satu gate (`admin`)
 * yang dipasang di routes/web.php, bukan cek per method.
 */
class User extends Authenticatable
{
    public const ROLES = ['user', 'admin'];

    protected $fillable = ['email', 'google_id', 'name', 'role', 'suspended_at'];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = ['suspended_at' => 'datetime'];

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }
}
