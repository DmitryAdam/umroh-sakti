<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satu gambar flyer bisa menjual banyak keberangkatan sekaligus (flyer jadwal:
 * "Edisi Agustus/September", tabel tanggal). Sampai sekarang cuma yang paling
 * menonjol yang jadi baris — sisanya hilang, padahal itu justru jadwal terbanyak.
 *
 * Identitas baris jadi `(media_id, flyer_index, offer_index)`: offer_index =
 * urutan keberangkatan di dalam gambar itu (0 = yang di field tingkat atas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->unsignedSmallInteger('offer_index')->default(0)->after('flyer_index');
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->dropUnique(['media_id', 'flyer_index']);
            $table->unique(['media_id', 'flyer_index', 'offer_index']);
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropUnique(['media_id', 'flyer_index', 'offer_index']);
            $table->unique(['media_id', 'flyer_index']);
            $table->dropColumn('offer_index');
        });
    }
};
