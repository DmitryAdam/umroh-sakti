<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kenapa fetch terakhir gagal, melekat di akunnya.
 *
 * Sebelum ini satu-satunya jejak kegagalan ada di `failed_jobs` (payloadnya
 * serialized, tidak bisa dibaca per akun tanpa parsing) dan di
 * storage/pipeline.jsonl yang cuma 80 baris terakhir. Di /akun akun yang gagal
 * tidak bisa dibedakan dari yang berhasil.
 *
 * Diisi saat fetch gagal atau kena rate limit, dikosongkan saat berhasil — jadi
 * isinya selalu status percobaan terakhir, bukan riwayat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_accounts', function (Blueprint $table) {
            $table->text('last_error')->nullable()->after('last_fetched_at');
        });
    }

    public function down(): void
    {
        Schema::table('source_accounts', function (Blueprint $table) {
            $table->dropColumn('last_error');
        });
    }
};
