<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Daftar post yang tidak perlu di-scrap lagi.
 *
 * Tanpa model: cuma insert idempoten + satu SELECT. probe.php membaca tabel yang
 * sama langsung lewat PDO (`excludedIds()`), jadi fetch tidak men-download ulang dan
 * extract tidak membayar model untuk post yang sudah dibuang.
 *
 * Alasan: `bukan_paket` (gerbang vision / saringan struktural), `haji` (haji khusus,
 * bukan umroh), `bukan_umroh` (wisata halal ke Korea/Jepang/Eropa — bentuknya paket
 * lengkap, tujuannya bukan tanah suci), `sebelum_ambang`
 * (keberangkatan di bawah config `umroh.min_departure`), `manual` (tombol × di
 * halaman review).
 */
class ExcludedPost
{
    public static function add(?string $mediaId, ?string $account, string $reason, ?string $note = null): void
    {
        if ($mediaId === null || $mediaId === '') {
            return;
        }

        DB::table('excluded_posts')->updateOrInsert(
            ['media_id' => $mediaId],
            [
                'source_account' => $account,
                'reason' => $reason,
                'note' => $note,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
}
