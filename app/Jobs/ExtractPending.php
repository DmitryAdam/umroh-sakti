<?php

namespace App\Jobs;

use App\Support\PipelineLog;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pemindai, bukan pengekstrak: melempar satu ExtractPost per post yang belum
 * punya hasil, lalu selesai dalam hitungan milidetik. Kerja beratnya di
 * ExtractPost supaya fetch tidak tertahan di belakang satu job panjang.
 *
 * Unique-until-processing: fetch yang selesai di tengah pemindaian tetap dapat
 * pemindaian berikutnya, tidak ditelan lock.
 */
class ExtractPending implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    // Pemindaian cuma hitungan milidetik; TTL panjang malah menahan pemindaian
    // berikutnya kalau worker mati di tengah.
    public int $uniqueFor = 120;

    public function __construct()
    {
        $this->onQueue('db');
    }

    public function handle(): void
    {
        $antri = 0;

        foreach (glob(storage_path('raw/*/*/post.json')) ?: [] as $file) {
            $mediaId = basename(dirname($file));
            // Sudah ada hasilnya = tidak dikirim ulang ke model. ExtractPost juga
            // unique per media_id, jadi dua pemindaian beruntun tidak bikin dobel.
            // Satu post bisa menghasilkan beberapa file (carousel dipecah per gambar),
            // jadi yang dicek pola namanya, bukan satu file.
            if (glob(storage_path("extracted/$mediaId{.json,-*.json}"), GLOB_BRACE) === []) {
                ExtractPost::dispatch($mediaId);
                $antri++;
            }
        }

        PipelineLog::write('db', "pemindaian: $antri post belum diekstrak, masuk antrian ai");
    }
}
