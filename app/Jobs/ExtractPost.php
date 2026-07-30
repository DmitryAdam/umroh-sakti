<?php

namespace App\Jobs;

use App\Support\PipelineLog;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Satu post = satu job. Sengaja tidak digumpalkan jadi satu job panjang: job
 * 40 menit menahan semua fetch di belakangnya, dan progresnya tidak kelihatan
 * sampai selesai. Per post, worker mencetak satu baris tiap selesai dan angka
 * di /pipeline/status ikut naik.
 *
 * Retry-nya juga jadi per post — satu flyer yang bikin model timeout tidak
 * membatalkan 500 post lainnya.
 */
class ExtractPost implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    // Disamakan dengan $timeout, bukan lebih lama. Worker yang di-kill di tengah job
    // meninggalkan lock ini di cache_locks sampai habis, dan selama itu dispatch
    // berikutnya untuk media_id yang sama hilang diam-diam. `ai` tidak kena rate limit
    // tingkat app seperti `ig`, jadi tidak ada alasan menahannya lebih lama dari
    // durasi maksimal job itu sendiri.
    public int $uniqueFor = 300;

    public function __construct(public string $mediaId)
    {
        // Antrian `ai` boleh dijalankan beberapa worker sekaligus — ini bagian yang
        // paling lama dan tidak dibatasi rate limit tingkat app seperti Graph API.
        $this->onQueue('ai');
    }

    public function uniqueId(): string
    {
        return $this->mediaId;
    }

    public function handle(): void
    {
        PipelineLog::write('ai', "== extract {$this->mediaId}");

        // stdout probe.php diteruskan apa adanya: request ke model vision, request ke
        // penyusun per gambar penawaran, dan nama file hasilnya.
        $result = Process::timeout($this->timeout)->run([
            PHP_BINARY,
            base_path('probe.php'),
            'extract',
            "--only={$this->mediaId}",
        ], PipelineLog::stream('ai'));

        if ($result->failed()) {
            throw new RuntimeException("extract {$this->mediaId}: ".trim($result->errorOutput() ?: $result->output()));
        }

        // Unique-until-processing di ImportPackages yang menggabungkan 500 dispatch
        // ini jadi beberapa kali jalan — paket muncul di review queue sambil jalan,
        // bukan setelah semuanya kelar.
        ImportPackages::dispatch();
    }
}
