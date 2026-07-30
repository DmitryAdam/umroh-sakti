<?php

namespace App\Console\Commands;

use App\Support\PipelineLog;
use Illuminate\Process\Pool;
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

        Process::pool(function (Pool $pool) use ($workers, $berhenti) {
            foreach ($workers as $queue => $jumlah) {
                for ($i = 1; $i <= $jumlah; $i++) {
                    $nama = $jumlah > 1 ? "$queue$i" : $queue;

                    $pool->as($nama)
                        ->path(base_path())
                        // timeout(0): worker memang jalan terus sampai dihentikan.
                        ->timeout(0)
                        ->command([
                            PHP_BINARY, 'artisan', 'queue:work',
                            "--queue=$queue",
                            "--name=$nama",
                            '--sleep=2',
                            '--tries=3',
                            // Restart berkala: worker PHP yang hidup berjam-jam menahan
                            // memori dan kode lama setelah file diubah.
                            '--max-time=3600',
                            ...$berhenti,
                        ]);
                }
            }
        })->start(function (string $type, string $chunk, string $key) {
            foreach (preg_split('/\r?\n/', $chunk) as $line) {
                if (trim($line) !== '') {
                    PipelineLog::write((string) $key, $line);
                    $this->line("[$key] ".trim($line));
                }
            }
        })->wait();

        return self::SUCCESS;
    }
}
