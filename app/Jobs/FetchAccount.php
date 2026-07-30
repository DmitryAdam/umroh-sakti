<?php

namespace App\Jobs;

use App\Models\SourceAccount;
use App\Support\PipelineLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Fetch satu akun lewat probe.php (satu-satunya yang menyentuh Graph API).
 *
 * Rate limit Graph API itu tingkat app — semua request numpuk di satu token —
 * jadi seluruh job fetch dipaksa lewat satu lock, berapa pun jumlah workernya.
 * Ini pengganti antrian file storage/fetch_queue.json di probe.php fetchall.
 *
 * Antrian `ig` terpisah dari `ai`: fetch yang antri karena rate limit tidak boleh
 * menahan ekstraksi post yang sudah ke-download, dan akun baru yang masuk tidak
 * menghentikan konversi yang sedang jalan.
 */
class FetchAccount implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(
        public SourceAccount $account,
        public int $limit = 50,
    ) {
        $this->onQueue('ig');
    }

    public function middleware(): array
    {
        // releaseAfter = jeda antar akun. expireAfter supaya lock ga nyangkut
        // selamanya kalau workernya mati di tengah fetch.
        return [
            (new WithoutOverlapping('ig-fetch'))
                ->releaseAfter(10)
                ->expireAfter($this->timeout),
        ];
    }

    public function handle(): void
    {
        PipelineLog::write('ig', "== fetch @{$this->account->username} (limit {$this->limit})");

        // stdout probe.php diteruskan apa adanya: request ke graph.facebook.com,
        // tiap gambar yang di-download, dan post yang dilewat karena sudah dibanned.
        $result = Process::timeout($this->timeout)->run([
            PHP_BINARY,
            base_path('probe.php'),
            'fetch',
            $this->account->username,
            "--limit={$this->limit}",
        ], PipelineLog::stream('ig'));

        if ($result->failed()) {
            $error = trim($result->errorOutput() ?: $result->output());

            // Rate limit bukan kesalahan akun ini — tunggu, jangan tandai gagal.
            if (str_contains($error, 'rate limit')) {
                PipelineLog::write('ig', "@{$this->account->username}: rate limit, coba lagi 5 menit");
                $this->release(300);

                return;
            }

            throw new RuntimeException("fetch @{$this->account->username}: $error");
        }

        $this->account->update(['last_fetched_at' => now()]);

        ExtractPending::dispatch();
    }
}
