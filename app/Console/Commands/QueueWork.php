<?php

namespace App\Console\Commands;

use App\Support\PipelineLog;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Queue\Console\WorkCommand;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Input\InputOption;

/**
 * `php artisan queue:work` — satu perintah, semua antrian jalan paralel.
 *
 * Menimpa command bawaan Laravel dengan cara meng-extend-nya, bukan menggantinya:
 *
 *   tanpa --queue  -> induk. Spawn satu proses anak per antrian.
 *   dengan --queue -> anak. `parent::handle()`, worker Laravel apa adanya.
 *
 * Anak dipanggil dengan `--queue=...`, jadi tidak mungkin memanggil induk lagi —
 * tidak ada risiko spawn beranak-pinak. Semua opsi bawaan (--tries, --memory,
 * --max-time, dst) tetap jalan karena signature-nya diwarisi.
 *
 * Tiga antrian karena tiga batasan yang berbeda:
 *
 *   ig  1 worker  — rate limit Graph API tingkat app, paralel cuma bikin kena #4
 *   ai  N worker  — bagian paling lama; ini yang dibikin paralel
 *   db  1 worker  — pemindai + import, cepat, dan SQLite cuma boleh satu penulis
 */
class QueueWork extends WorkCommand
{
    protected $description = 'Jalankan worker semua antrian pipeline sekaligus (ig + ai + db)';

    /**
     * Worker-nya diambil dari container yang sudah dirakit framework
     * (`new WorkCommand($app['queue.worker'], $app['cache.store'])`) — autowire
     * biasa gagal karena Worker punya parameter callable.
     */
    public function __construct()
    {
        parent::__construct(app('queue.worker'), app('cache.store'));
    }

    protected function configure(): void
    {
        parent::configure();

        $this->getDefinition()->addOption(new InputOption(
            'ai', null, InputOption::VALUE_REQUIRED, 'Jumlah worker ekstraksi paralel', '3',
        ));
    }

    public function handle()
    {
        // Anak: worker Laravel biasa untuk satu antrian.
        if ($this->option('queue')) {
            return parent::handle();
        }

        // Induk kedua = worker dobel. Anak yatim dari induk yang di-Ctrl+C tetap
        // polling, jadi `ig` rebutan lock sampai kehabisan percobaan dan `db`
        // saling tabrak insert. Cek proses, bukan lock — lock basi bikin macet baru.
        // `--queue[=]`: pgrep hanya mengecualikan PID-nya sendiri, bukan pgrep lain
        // yang jalan bersamaan — dan `--queue=` ada di argv pgrep itu sendiri, jadi
        // dua induk yang start serentak saling melaporkan pgrep-nya. Kelas karakter
        // bikin polanya tidak cocok dengan argv-nya sendiri.
        $lain = trim(Process::run('pgrep -f "artisan queue:work --queue[=]"')->output());
        if ($lain !== '') {
            $this->error('Worker lama masih jalan: PID '.str_replace("\n", ' ', $lain));
            $this->line('Hentikan dulu: pkill -f "artisan queue:work"');

            return self::FAILURE;
        }

        $ai = max(1, (int) $this->option('ai'));
        $workers = ['ig' => 1, 'ai' => $ai, 'db' => 1];

        // Flag yang menentukan kapan worker berhenti wajib diteruskan ke anak —
        // kalau tidak, `queue:work --stop-when-empty` menggantung selamanya.
        $berhenti = array_values(array_filter([
            $this->option('once') ? '--once' : null,
            $this->option('stop-when-empty') ? '--stop-when-empty' : null,
        ]));

        $this->info("Worker jalan: ig×1 · ai×$ai · db×1. Ctrl+C untuk berhenti.");
        PipelineLog::write('run', "worker start: ig×1 ai×$ai db×1");

        // Nama anak -> antriannya. Nama dipakai ulang saat menyalakan ulang supaya
        // baris log tetap bisa dilacak ke worker yang sama.
        $anak = [];
        foreach ($workers as $queue => $jumlah) {
            for ($i = 1; $i <= $jumlah; $i++) {
                $anak[$jumlah > 1 ? "$queue$i" : $queue] = $queue;
            }
        }

        $jalan = [];
        foreach ($anak as $nama => $queue) {
            $jalan[$nama] = $this->spawn($nama, $queue, $berhenti);
        }

        // JANGAN pakai Process::pool()->wait(): itu `->map->wait()`, jadi induk
        // mengantre di anak pertama (`ig`) yang tidak pernah selesai dan tidak pernah
        // membaca pipe anak lain. Buffer stdout `ai`/`db` penuh (64 KB), anaknya
        // kebentur `write()`, dan antrian kelihatan mati padahal jobnya tersedia.
        // `running()` memanggil `isRunning()` -> `readPipes()` -> callback di spawn(),
        // jadi semua pipe ikut terkuras.
        //
        // Anak yang keluar dinyalakan lagi. `--max-time=3600` itu maunya restart
        // berkala, tapi tidak ada yang menyalakan ulang: sejam sekali seluruh worker
        // mati diam-diam, dan sampai mati itu mereka menahan kode lama di memori —
        // pernah kejadian, import dari antrian mem-prune pakai aturan yang sudah
        // diperbaiki 20 menit sebelumnya.
        while ($jalan !== []) {
            foreach ($jalan as $nama => $proses) {
                if ($proses->running()) {
                    continue;
                }

                // --once / --stop-when-empty: anak memang disuruh berhenti, jangan
                // dihidupkan lagi — kalau tidak, perintahnya tidak pernah selesai.
                if ($berhenti !== []) {
                    unset($jalan[$nama]);

                    continue;
                }

                PipelineLog::write('run', "worker $nama keluar, nyalakan lagi");
                $jalan[$nama] = $this->spawn($nama, $anak[$nama], $berhenti);
            }

            usleep(200_000);
        }

        return self::SUCCESS;
    }

    /** Satu worker antrian, output-nya diteruskan ke pipeline.jsonl + stdout induk. */
    private function spawn(string $nama, string $queue, array $berhenti): InvokedProcess
    {
        return Process::path(base_path())
            // timeout(0): worker memang jalan terus sampai dihentikan.
            ->timeout(0)
            ->start([
                PHP_BINARY, 'artisan', 'queue:work',
                "--queue=$queue",
                "--name=$nama",
                '--sleep=2',
                '--tries=3',
                // Restart berkala: worker PHP yang hidup berjam-jam menahan memori
                // dan kode lama setelah file diubah. Yang menyalakan lagi: loop di handle().
                '--max-time=3600',
                ...$berhenti,
            ], function (string $type, string $chunk) use ($nama) {
                foreach (preg_split('/\r?\n/', $chunk) as $line) {
                    if (trim($line) !== '') {
                        PipelineLog::write($nama, $line);
                        $this->line("[$nama] ".trim($line));
                    }
                }
            });
    }
}
