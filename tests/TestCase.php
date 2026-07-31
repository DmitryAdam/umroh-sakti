<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Login sebagai operator. Semua alat kerja (daftar akun, panel pipeline, tombol
     * aksi per kartu) ada di grup `auth` — dulu kuncinya env local, jadi test-nya
     * memalsukan env; sekarang cukup punya satu baris user.
     */
    protected function actingAsOperator(): static
    {
        return $this->actingAs(User::create([
            'email' => 'operator@umroh.test',
            'password' => 'rahasia-uji',
        ]));
    }
}
