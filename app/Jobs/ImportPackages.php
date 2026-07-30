<?php

namespace App\Jobs;

use App\Support\PipelineLog;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

/**
 * Pilah + masuk DB. `--prune` memindahkan post yang bukan penawaran paket ke
 * storage/trash, jadi storage/raw isinya cuma yang lanjut.
 *
 * Import-nya idempoten (dedup_key + updateOrCreate per media_id), jadi aman
 * dijalankan berkali-kali atas backlog yang sama.
 */
class ImportPackages implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    // Pendek: job ini cuma hitungan detik. Worker yang mati di tengah menahan lock
    // sampai TTL habis — 10 menit bikin import berikutnya kelihatan hilang diam-diam.
    public int $uniqueFor = 120;

    public function __construct()
    {
        $this->onQueue('db');
    }

    public function handle(): void
    {
        Artisan::call('packages:import', ['--prune' => true]);

        foreach (preg_split('/\r?\n/', Artisan::output()) as $line) {
            PipelineLog::write('db', $line);
        }
    }
}
