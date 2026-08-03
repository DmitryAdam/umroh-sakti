<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dua status saja: `review` (kiriman tangan) + `published` (sisanya).
 *
 * Baris lama tidak menyimpan asal-usulnya — `_manual` baru ikut ke hasil ekstraksi
 * mulai sekarang, dan `storage/raw` sudah kadung di-prune jadi post.json-nya tidak
 * bisa ditanya lagi. Jadi semuanya dipublish: usulan tangan baru ada belakangan,
 * dan yang salah tetap bisa dibuang lewat × / blokir.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('packages')->whereIn('status', ['draft', 'review'])
            ->update(['status' => 'published']);
    }

    public function down(): void
    {
        // Asal-usulnya sudah hilang sebelum migrasi ini; tidak ada yang bisa dipulihkan.
    }
};
