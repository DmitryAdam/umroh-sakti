<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Operator portal. Satu peran saja — login berarti boleh semua alat kerja.
 * Tidak ada kolom `role`: kalau nanti perlu peran pembaca-saja, itu satu kolom
 * baru di sini, bukan tabel izin.
 */
class User extends Authenticatable
{
    protected $fillable = ['email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = ['password' => 'hashed'];
}
