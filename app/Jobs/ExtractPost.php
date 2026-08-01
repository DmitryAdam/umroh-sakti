<?php

namespace App\Jobs;

use App\Support\PipelineLog;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessSignaledException;

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

    // Satu post = 1 call vision + N call penyusun (satu per gambar penawaran), dan
    // tiap call boleh diulang sekali lalu pindah model. 300s tidak muat: carousel
    // 3 penawaran yang modelnya lagi lambat kena TimeoutExceededException persis di
    // tengah slide terakhir, dan percobaan kedua mengulang dari vision lagi — mati
    // di tempat yang sama. Anggaran per call diatur AI_TIMEOUT (default 60s).
    public int $timeout = 600;

    // Disamakan dengan $timeout, bukan lebih lama. Worker yang di-kill di tengah job
    // meninggalkan lock ini di cache_locks sampai habis, dan selama itu dispatch
    // berikutnya untuk media_id yang sama hilang diam-diam. `ai` tidak kena rate limit
    // tingkat app seperti `ig`, jadi tidak ada alasan menahannya lebih lama dari
    // durasi maksimal job itu sendiri.
    public int $uniqueFor = 600;

    // Tidak ada saklar "lewati gerbang vision": cuma vision yang melihat pixelnya,
    // jadi cuma dia yang bisa memastikan sebuah post benar-benar penawaran umroh.
    // `--no-gate` di probe.php tinggal alat uji manual, bukan jalur yang dipakai job.
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
        // Hasilnya sudah ada = tidak ada yang perlu diminta ke model. probe.php
        // memang melewatinya juga, tapi itu sesudah satu proses PHP di-spawn — dan
        // job begini menumpuk: lock unique kadaluwarsa selagi job masih antri, jadi
        // pemindaian berikutnya melempar media yang sama lagi. Guard-nya di sini,
        // bukan di pemanggil, supaya tombol baca ulang & pemindai kena aturan sama.
        // Baca ulang menghapus hasil lamanya dulu, jadi tidak ikut terhalang.
        if (glob(storage_path("extracted/{$this->mediaId}{.json,-*.json}"), GLOB_BRACE) !== []) {
            PipelineLog::write('ai', "extract {$this->mediaId}: sudah ada hasilnya, dilewat");

            return;
        }

        PipelineLog::write('ai', "== extract {$this->mediaId}");

        // stdout probe.php diteruskan apa adanya: request ke model vision, request ke
        // penyusun per gambar penawaran, dan nama file hasilnya.
        try {
            // Sengaja lebih pendek dari $timeout: yang duluan habis harus Process,
            // bukan alarm queue. TimeoutExceededException membunuh proses worker-nya
            // (job berikutnya baru jalan setelah worker di-spawn ulang, dan lock
            // unique-nya tertinggal di cache_locks), sementara Process yang kehabisan
            // waktu cuma melempar ProcessTimedOutException dari job ini — worker tetap
            // hidup, pesannya kelihatan di panel, dan retry-nya jalur normal.
            $result = Process::timeout($this->timeout - 30)->run([
                PHP_BINARY,
                base_path('probe.php'),
                'extract',
                "--only={$this->mediaId}",
            ], PipelineLog::stream('ai'));
        } catch (ProcessSignaledException $e) {
            // Ctrl+C / pkill kena satu grup proses, jadi probe.php ikut mati di tengah
            // jalan. Itu bukan kegagalan ekstraksi: kalau dilempar, tiap kali worker
            // dihentikan manual job ini mendarat di failed_jobs dan panel menampilkan
            // "N gagal" yang sebenarnya cuma "gw tekan Ctrl+C". Kembalikan ke antrian,
            // biar dikerjakan lagi sesudah worker start.
            PipelineLog::write('ai', "extract {$this->mediaId}: dihentikan (signal {$e->getSignal()}), antri lagi");
            $this->release();

            return;
        }

        if ($result->failed()) {
            throw new RuntimeException("extract {$this->mediaId}: ".trim($result->errorOutput() ?: $result->output()));
        }

        // Unique-until-processing di ImportPackages yang menggabungkan 500 dispatch
        // ini jadi beberapa kali jalan — paket muncul di review queue sambil jalan,
        // bukan setelah semuanya kelar.
        ImportPackages::dispatch();
    }
}
