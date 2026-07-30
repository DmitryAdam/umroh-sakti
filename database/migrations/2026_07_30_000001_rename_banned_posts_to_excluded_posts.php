<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Ganti istilah: "banned" -> "dikecualikan". DB yang sudah jalan direname supaya
 * 157 baris pengecualiannya tidak hilang; DB baru sudah lahir dengan nama benar
 * dari migrasi schema, jadi migrasi ini tidak ada kerjaan di sana.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('banned_posts') && !Schema::hasTable('excluded_posts')) {
            Schema::rename('banned_posts', 'excluded_posts');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('excluded_posts') && !Schema::hasTable('banned_posts')) {
            Schema::rename('excluded_posts', 'banned_posts');
        }
    }
};
