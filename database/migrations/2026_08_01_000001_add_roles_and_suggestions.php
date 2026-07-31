<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dua peran, bukan satu: `admin` (semua alat kerja + approval) dan `user` (cuma
 * boleh mengusulkan akun/post, tidak boleh menjalankan apa pun yang membakar
 * kuota Graph atau membayar model).
 *
 * Default kolomnya `user` — yang paling tidak berbahaya kalau ada baris yang
 * lahir tanpa peran. Baris yang sudah ada dinaikkan jadi `admin`: sampai migrasi
 * ini satu-satunya jalan bikin user adalah `user:create`, dan itu memang operator.
 *
 * `suggested_by` di akun = email pengusulnya, isinya cuma jejak "siapa yang minta"
 * buat halaman /suggestions. Yang menahan akun usulan supaya tidak ikut di-scrap
 * itu `status` = `pending` (semua jalur crawl menyaring `approved`), bukan kolom ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('user');
        });

        DB::table('users')->update(['role' => 'admin']);

        Schema::table('source_accounts', function (Blueprint $table) {
            $table->string('suggested_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('role'));
        Schema::table('source_accounts', fn (Blueprint $table) => $table->dropColumn('suggested_by'));
    }
};
