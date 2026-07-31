<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Login pindah ke Google SSO, satu-satunya pintu. Tidak ada form sandi lagi dan
 * tidak ada `user:create` — kolom `password` tetap ada tapi nullable supaya baris
 * lama tidak perlu dihapus; isinya tidak pernah dibaca siapa pun lagi.
 *
 * Alasannya operator bukan cuma satu orang lagi: begitu ada peran `user` yang
 * mengusulkan post, "bikin akun dari CLI" berarti tiap contributor harus menunggu
 * seseorang buka terminal. Google menanggung verifikasi email + 2FA-nya, jadi yang
 * tersisa di sini cuma peran dan penangguhan.
 *
 * `google_id` (`sub` dari Google, stabil walau emailnya diganti) diisi saat login
 * pertama; barisnya dicari lewat email dulu supaya baris yang dibuat migrasi ini
 * dan baris lama ikut tertaut, bukan lahir kembar.
 *
 * Perannya sengaja TIDAK bisa diubah dari UI: pendaftar baru selalu `user`, naik
 * jadi `admin` cuma lewat SQLite/tinker. Halaman /users cuma bisa menangguhkan —
 * satu tombol yang tidak bisa dipakai untuk menaikkan hak siapa pun.
 */
return new class extends Migration
{
    private const ADMIN = 'dimitry.adam@gmail.com';

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
            $table->string('google_id')->nullable()->unique()->after('email');
            $table->string('name')->nullable()->after('google_id');
            // Penangguhan itu stempel waktu, bukan boolean: "sejak kapan" gratis
            // ikut tersimpan dan `whereNull` sama gampangnya dengan `where(false)`.
            $table->timestamp('suspended_at')->nullable();
        });

        // Admin tunggal. Sisanya diturunkan: sampai migrasi sebelumnya semua baris
        // dinaikkan jadi admin karena satu-satunya jalan bikin user adalah CLI.
        // Sekarang siapa pun yang punya akun Google bisa masuk, jadi asumsi itu mati.
        DB::table('users')->update(['role' => 'user']);
        User::updateOrCreate(['email' => self::ADMIN], ['role' => 'admin']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['google_id', 'name', 'suspended_at']);
        });
    }
};
