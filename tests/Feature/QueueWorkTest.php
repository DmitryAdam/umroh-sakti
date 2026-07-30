<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class QueueWorkTest extends TestCase
{
    /**
     * Induk menunggu anaknya lewat `running()`, bukan `wait()`.
     *
     * `InvokedProcessPool::wait()` itu `->map->wait()`: induk mengantre di anak
     * pertama (`ig`) yang tidak pernah selesai, jadi pipe anak lain tidak pernah
     * dibaca. Begitu buffer stdout 64 KB penuh, `ai`/`db` kebentur `write()` dan
     * antrian kelihatan mati padahal jobnya tersedia — pernah kejadian, worker `db`
     * beku 45 menit. `running()` memaksa `readPipes()` di tiap anak.
     */
    public function test_induk_menguras_pipe_anak_yang_bukan_giliran_pertama(): void
    {
        $keluar = [];

        // Anak pertama hidup lebih lama dari anak kedua — persis urutan
        // ig-lalu-ai/db yang bikin deadlock-nya.
        $lama = Process::timeout(0)->start([PHP_BINARY, '-r', 'sleep(30);']);
        // 300 KB: jauh di atas buffer pipe 64 KB, jadi tanpa pengurasan
        // prosesnya menggantung di write() dan tidak pernah selesai.
        $cerewet = Process::timeout(0)->start(
            [PHP_BINARY, '-r', 'echo str_repeat("x", 300000);'],
            function (string $type, string $chunk) use (&$keluar) {
                $keluar['cerewet'] = ($keluar['cerewet'] ?? 0) + strlen($chunk);
            },
        );

        $batas = microtime(true) + 15;
        while ($cerewet->running() && microtime(true) < $batas) {
            $lama->running();   // anak pertama ikut dicek, persis seperti di handle()
            usleep(50_000);
        }

        $lama->stop();

        $this->assertSame(300000, $keluar['cerewet'] ?? 0,
            'anak kedua nyangkut di write(): pipe-nya tidak dikuras selagi anak pertama masih jalan');
    }

    /**
     * Anak yang keluar dinyalakan lagi.
     *
     * `--max-time=3600` maunya restart berkala, tapi tidak ada yang menyalakan
     * ulang: sejam sekali seluruh worker mati diam-diam, dan sampai mati itu mereka
     * menahan kode lama di memori — pernah kejadian, import dari antrian mem-prune
     * pakai aturan yang sudah diperbaiki 20 menit sebelumnya.
     */
    public function test_anak_yang_keluar_dinyalakan_lagi(): void
    {
        $spawn = fn () => Process::timeout(0)->start([PHP_BINARY, '-r', 'usleep(200000);']);

        $proses = $spawn();
        $awal = $proses->id();
        $nyala = 0;

        $batas = microtime(true) + 15;
        while ($nyala < 2 && microtime(true) < $batas) {
            if (! $proses->running()) {
                $proses = $spawn();
                $nyala++;
            }
            usleep(20_000);
        }

        $proses->stop();

        $this->assertSame(2, $nyala, 'anak yang keluar harus dinyalakan lagi, bukan dibiarkan mati');
        $this->assertNotSame($awal, $proses->id(), 'yang jalan sekarang proses baru');
    }
}
