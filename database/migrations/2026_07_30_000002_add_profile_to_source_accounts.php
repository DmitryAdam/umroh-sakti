<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profil akun dari business_discovery, diisi tiap fetch berhasil.
 *
 * Yang TIDAK ada di API dan karena itu tidak ada kolomnya: status verified dan
 * tanggal bergabung — dua-duanya dijawab `#100 nonexisting field`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_accounts', function (Blueprint $table) {
            $table->string('full_name')->nullable();
            $table->unsignedInteger('followers_count')->nullable();
            $table->unsignedInteger('follows_count')->nullable();
            $table->unsignedInteger('media_count')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('source_accounts', function (Blueprint $table) {
            $table->dropColumn(['full_name', 'followers_count', 'follows_count', 'media_count']);
        });
    }
};
