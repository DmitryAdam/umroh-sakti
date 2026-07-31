<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Login sebagai admin. Semua alat kerja (daftar akun, panel pipeline, tombol
     * aksi per kartu, daftar pengguna) ada di grup `auth` + `can:admin`.
     */
    protected function actingAsOperator(): static
    {
        return $this->actingAs(User::create([
            'email' => 'operator@umroh.test',
            'role' => 'admin',
        ]));
    }

    /** Login sebagai peran `user`: cuma /suggestions, tidak ada alat kerja. */
    protected function actingAsPengusul(): static
    {
        return $this->actingAs(User::create([
            'email' => 'pengusul@umroh.test',
            'role' => 'user',
        ]));
    }
}
