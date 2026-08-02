<?php

namespace App\Console\Commands;

use App\Jobs\ExtractPending;
use App\Support\PipelineLog;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Queue\Console\WorkCommand;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
 *   ai  1 worker  — default 1: call vision paralel ke router malah saling
 *                   menggantung. Naikkan lewat --ai=N kalau routernya sanggup.
 *   db  1 worker  — pemindai + import, cepat, dan SQLite cuma boleh satu penulis
 */
class QueueWork extends WorkCommand
{
    /**
     * Jumlah worker per antrian, bisa diubah sambil jalan dari /pipeline. Nilainya
     * di cache seperti flag STOP — satu map kecil, dan `config` tidak bisa diubah
     * dari UI. Loop induk membacanya sekali per detik lalu menambah/mengurangi anak;
     * yang dikurangi kena SIGTERM, jadi job yang dipegang tetap selesai.
     *
     * `cache:clear` mengembalikannya ke 1 per antrian, dan itu default yang aman.
     */
    public const WORKERS = 'worker-jumlah';

    /** Batas atas per antrian. Di atas ini yang jebol bukan CPU-nya: Graph #4 dan router. */
    public const MAX_WORKERS = 8;

    /**
     * Setelan sekarang, sudah dijepit 0..MAX_WORKERS. 0 = antriannya sengaja
     * dipause (jobnya tetap antri, cuma tidak ada yang mengambil).
     *
     * @return array<string, int>
     */
    public static function jumlah(): array
    {
        $mau = Cache::get(self::WORKERS, []);

        return collect(['ig', 'ai', 'db'])
            ->mapWithKeys(fn ($q) => [$q => max(0, min(self::MAX_WORKERS, (int) ($mau[$q] ?? 1)))])
            ->all();
    }

    protected $description = 'Jalankan worker semua antrian pipeline sekaligus (ig + ai + db)';

    /**
     * Kunci cache "berhenti sesudah job yang sedang jalan selesai" — dipasang tombol
     * stop di /pipeline, dibaca loop induk. Kenapa lewat cache dan bukan pkill dari
     * web: argv anak (`php artisan queue:work --queue=ig`) identik lintas project di
     * server yang sama, jadi `pkill -f` juga membunuh worker app tetangga. Ini juga
     * yang dipakai `queue:restart` bawaan — bedanya `restart` bikin induk menyalakan
     * anaknya lagi, jadi tidak bisa dipakai untuk berhenti.
     */
    public const STOP = 'worker-stop';

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
            'ai', null, InputOption::VALUE_REQUIRED, 'Jumlah worker ekstraksi paralel', '1',
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

        // Worker yang di-kill di tengah job meninggalkan `reserved_at` terisi. Job itu
        // baru diklaim ulang setelah DB_QUEUE_RETRY_AFTER lewat (1200 detik) — jadi
        // sesudah restart antrian kelihatan "3 jalan" selama 20 menit padahal tidak ada
        // proses yang mengerjakannya. Jangan turunkan retry_after-nya: itu jaring untuk
        // job yang memang lama (satu carousel ke vision + penyusun), dan menurunkannya
        // bikin job yang masih jalan diambil worker kedua.
        //
        // Guard pgrep di atas sudah memastikan tidak ada worker lain yang hidup, jadi
        // setiap reservasi yang tersisa di sini pasti yatim. `cache_locks` sengaja tidak
        // disentuh: lock `unique` kedaluwarsa sendiri dalam 120 detik, dan menghapusnya
        // lebih awal justru membuka dispatch dobel untuk job yang masih antri.
        $yatim = DB::table('jobs')->whereNotNull('reserved_at')->update(['reserved_at' => null]);
        if ($yatim > 0) {
            PipelineLog::write('run', "$yatim job reservasi yatim dilepas");
        }

        // --ai=N tetap ada, tapi sekarang cuma cara lain menulis setelan yang sama —
        // dan cuma kalau memang diketik, supaya start biasa tidak menimpa angka yang
        // barusan diatur dari panel.
        if ($this->input->hasParameterOption('--ai')) {
            Cache::forever(self::WORKERS, [...self::jumlah(), 'ai' => max(1, (int) $this->option('ai'))]);
        }

        // Flag yang menentukan kapan worker berhenti wajib diteruskan ke anak —
        // kalau tidak, `queue:work --stop-when-empty` menggantung selamanya.
        $berhenti = array_values(array_filter([
            $this->option('once') ? '--once' : null,
            $this->option('stop-when-empty') ? '--stop-when-empty' : null,
        ]));

        // Kurung kurawal wajib: `×` itu byte ≥0x80, jadi "$q×$n" diparse PHP sebagai
        // variabel bernama `$q×` — bukan `$q` diikuti tanda kali.
        $rangkum = fn (array $m) => collect($m)->map(fn ($n, $q) => "{$q}×{$n}")->implode(' · ');
        $this->info('Worker jalan: '.$rangkum(self::jumlah()).'. Ctrl+C untuk berhenti.');
        PipelineLog::write('run', 'worker start: '.$rangkum(self::jumlah()));

        // Flag sisa dari sesi sebelumnya dibuang di sini, bukan dibiarkan kedaluwarsa:
        // kalau tidak, tombol stop yang ditekan saat tidak ada worker akan mematikan
        // worker berikutnya yang start.
        Cache::forget(self::STOP);

        // Nama anak selalu berakhiran nomor (`ig1`, `ai2`) — antriannya dibaca balik
        // dari namanya, jadi tidak perlu peta kedua yang bisa basi tiap jumlahnya
        // diubah. Nomornya juga yang menentukan siapa yang dimatikan saat diturunkan.
        $jalan = [];
        $mati = [];   // anak yang sengaja di-SIGTERM: jangan dinyalakan lagi

        // Rombongan awal dispawn di sini, bukan diserahkan ke rekonsiliasi di loop:
        // rekonsiliasinya dilewat kalau ada --once/--stop-when-empty, dan tanpa baris
        // ini perintah itu keluar seketika tanpa pernah punya anak.
        foreach (self::jumlah() as $queue => $mau) {
            for ($i = 1; $i <= $mau; $i++) {
                $jalan["$queue$i"] = $this->spawn("$queue$i", $queue, $berhenti);
            }
        }

        // Detak pemindai. `ExtractPending` sebelumnya cuma dilempar oleh FetchAccount
        // dan tombol scrap, jadi post yang raw-nya sudah ada tapi ekstraksinya gagal
        // (semua model timeout = "0 hasil ditulis", tidak ada file ditulis) tidak
        // punya siapa pun yang memindainya lagi: ia duduk di storage/raw selamanya
        // sampai kebetulan ada fetch berikutnya. Sama untuk job yang dibuang tombol
        // "batalkan antrian". Terukur 2026-07-31: 1 post nyangkut 30 menit dengan
        // antrian kosong, failed_jobs kosong, dan 5 worker nganggur.
        //
        // Induknya memang sudah looping, jadi detaknya numpang di sini — tidak perlu
        // `schedule:work` sebagai proses keempat. Aman diulang: ExtractPending itu
        // unique-until-processing dan melewati post yang sudah punya hasil.
        //
        // ponytail: post yang gagalnya permanen dibayar ulang ke model tiap 15 menit;
        // kalau itu jadi masalah, hitung percobaannya per media_id sebelum dispatch.
        $detak = 0;

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
        $stop = false;
        $cek = 0;

        // Induk tetap hidup walau tidak punya anak: jumlah 0 itu "antriannya dipause",
        // bukan "matikan pipeline" — kalau induknya ikut keluar, menaikkannya lagi cuma
        // bisa dari terminal. Yang benar-benar mengakhiri: tombol stop atau --once.
        while ($jalan !== [] || (! $stop && $berhenti === [])) {
            // Sekali per detik, bukan tiap putaran: loopnya 200 ms dan cache-nya di
            // SQLite yang sama dengan antrian.
            if (! $stop && time() > $cek) {
                $cek = time();

                // Rekonsiliasi jumlah worker: kurang -> spawn, lebih -> SIGTERM yang
                // nomornya di atas jatah. SIGTERM, bukan kill, jadi job yang sedang
                // dipegang tetap selesai (satu carousel ke vision bisa menit-menitan)
                // dan panelnya memang tidak langsung sunyi.
                if ($berhenti === []) {
                    foreach (self::jumlah() as $queue => $mau) {
                        for ($i = 1; $i <= $mau; $i++) {
                            $jalan["$queue$i"] ??= $this->spawn("$queue$i", $queue, $berhenti);
                        }
                        for ($i = $mau + 1; $i <= self::MAX_WORKERS; $i++) {
                            if (isset($jalan["$queue$i"]) && ! isset($mati["$queue$i"])) {
                                $jalan["$queue$i"]->signal(SIGTERM);
                                $mati["$queue$i"] = true;
                                PipelineLog::write('run', "worker $queue$i dihentikan (jumlah $queue diturunkan jadi $mau)");
                            }
                        }
                    }
                }

                if (Cache::pull(self::STOP, false)) {
                    $stop = true;
                    // SIGTERM, bukan SIGKILL: worker Laravel menyelesaikan job yang
                    // sedang dipegang dulu baru keluar. Job yang belum diambil tetap
                    // di `jobs` dan dikerjakan begitu worker dinyalakan lagi.
                    foreach ($jalan as $proses) {
                        $proses->signal(SIGTERM);
                    }
                    $this->info('Stop diminta — menunggu job yang sedang jalan selesai.');
                    PipelineLog::write('run', 'stop diminta dari panel: worker berhenti sesudah job sekarang selesai');
                }
            }

            if (! $stop && $berhenti === [] && time() >= $detak) {
                ExtractPending::dispatch();
                $detak = time() + 900;
            }

            foreach ($jalan as $nama => $proses) {
                if ($proses->running()) {
                    continue;
                }

                // --once / --stop-when-empty / stop / jumlahnya diturunkan: anak memang
                // disuruh berhenti, jangan dihidupkan lagi — kalau tidak, perintahnya
                // tidak pernah selesai dan angka yang diturunkan naik lagi sendiri.
                if ($stop || $berhenti !== [] || isset($mati[$nama])) {
                    unset($jalan[$nama], $mati[$nama]);

                    continue;
                }

                // Kode keluarnya ikut dicatat: worker Laravel keluar diam-diam untuk
                // beberapa sebab yang beda penanganannya (12 = --memory habis,
                // 1 = exception, 0 = --max-time). Tanpa ini restart yang tiap 5 menit
                // tidak bisa dibedakan dari restart sejam sekali yang memang disengaja.
                // wait() di sini TIDAK memblokir: prosesnya sudah keluar (running()
                // barusan false), jadi ini cuma cara mengambil ProcessResult-nya —
                // InvokedProcess sendiri tidak punya exitCode().
                PipelineLog::write('run', "worker $nama keluar (exit {$proses->wait()->exitCode()}), nyalakan lagi");
                $jalan[$nama] = $this->spawn($nama, rtrim($nama, '0123456789'), $berhenti);
            }

            usleep(200_000);
        }

        if ($stop) {
            PipelineLog::write('run', 'worker berhenti — nyalakan lagi dengan `php artisan queue:work`');
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
