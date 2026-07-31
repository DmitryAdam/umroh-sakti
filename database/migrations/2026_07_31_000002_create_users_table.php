<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator, bukan "user" dalam arti pengunjung. Portalnya tetap tanpa pendaftaran:
 * yang publik cuma pencarian paket published, dan baris di sini cuma buat membuka
 * alat kerjanya (/akun, panel pipeline, tombol aksi per kartu).
 *
 * Tanpa reset password: `MAIL_MAILER=log`, tidak ada email yang benar-benar keluar.
 * Lupa sandi = `php artisan user:buat <email>` lagi, itu sekalian menggantinya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
