<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Pindahan sekali jalan dari laptop ke produksi: baris SQLite -> MySQL, dan
 * `storage/flyers` -> disk `flyers` (R2/S3).
 *
 * Sumbernya connection `sqlite_legacy` (pathnya dipatok di config/database.php),
 * tujuannya connection default — jadi urutan pemakaiannya: isi DB_* + AWS_* di
 * .env dulu, `DB_CONNECTION=mysql`, baru jalankan ini. Sesudah selesai SQLite
 * tidak dipakai lagi sama sekali.
 *
 * Idempoten dua-duanya: tabel yang disalin dikosongkan dulu (isinya diganti,
 * bukan ditumpuk), dan file yang sudah ada di bucket dilewat — jadi upload yang
 * putus di tengah tinggal dijalankan lagi.
 */
class MigrateProduction extends Command
{
    protected $signature = 'migrate:production
                            {--only= : db atau files saja (default dua-duanya)}
                            {--pretend : tunjukkan rencananya, jangan tulis apa pun}';

    protected $description = 'Pindahkan data SQLite ke DB produksi dan flyer lokal ke S3/R2';

    /**
     * Tabel yang isinya nganggur sesudah pindah: antrian, cache, sesi, dan catatan
     * migrasi (yang terakhir diisi `migrate` sendiri). Menyalinnya cuma memindahkan
     * job yatim + lock basi ke server baru.
     */
    private const SKIP = [
        'migrations', 'cache', 'cache_locks', 'sessions',
        'jobs', 'job_batches', 'failed_jobs',
    ];

    private const CHUNK = 500;

    public function handle(): int
    {
        $only = $this->option('only');
        if ($only !== null && ! in_array($only, ['db', 'files'], true)) {
            $this->error('--only cuma menerima "db" atau "files".');

            return self::FAILURE;
        }

        $target = config('database.default');
        if ($target === 'sqlite') {
            $this->error('DB_CONNECTION masih sqlite — tujuannya sama dengan sumbernya. Isi DB_* di .env dulu.');

            return self::FAILURE;
        }

        $this->line(sprintf(
            '  DB     %s -> %s (%s@%s:%s/%s)',
            config('database.connections.sqlite_legacy.database'),
            $target,
            config("database.connections.$target.username"),
            config("database.connections.$target.host"),
            config("database.connections.$target.port"),
            config("database.connections.$target.database"),
        ));
        $this->line(sprintf(
            '  Flyer  %s -> disk flyers (%s%s)',
            storage_path('flyers'),
            config('filesystems.disks.flyers.driver'),
            config('filesystems.disks.flyers.driver') === 's3'
                ? ': '.config('filesystems.disks.flyers.bucket')
                : '',
        ));
        $this->newLine();

        if ($only !== 'files' && ($code = $this->pindahDb($target)) !== self::SUCCESS) {
            return $code;
        }

        if ($only !== 'db' && ($code = $this->pindahFlyer()) !== self::SUCCESS) {
            return $code;
        }

        $this->newLine();
        $this->info('Selesai. SQLite dan storage/flyers lokal tidak dibaca lagi — boleh diarsipkan.');

        return self::SUCCESS;
    }

    private function pindahDb(string $target): int
    {
        $this->components->info('1/2 · Database');

        $source = DB::connection('sqlite_legacy');

        try {
            // SQLite mengembalikannya berkualifikasi schema ("main.packages").
            $tables = collect($source->getSchemaBuilder()->getTableListing())
                ->map(fn ($t) => str_contains($t, '.') ? explode('.', $t, 2)[1] : $t)
                ->reject(fn ($t) => in_array($t, self::SKIP, true))
                ->values();
        } catch (\Throwable $e) {
            $this->error('SQLite tidak terbaca: '.$e->getMessage());

            return self::FAILURE;
        }

        // Schema dibangun oleh migrasi, bukan disalin: tipe kolom SQLite terlalu
        // longgar untuk diterjemahkan balik (semuanya praktis TEXT/NUMERIC).
        if (! $this->option('pretend')) {
            $this->call('migrate', ['--force' => true]);
        }

        $total = 0;
        foreach ($tables as $table) {
            $rows = $source->table($table)->count();

            if (! Schema::connection($target)->hasTable($table)) {
                $this->components->warn("$table dilewat: tidak ada di tujuan (migrasinya belum jalan?)");

                continue;
            }

            if ($this->option('pretend')) {
                $this->components->twoColumnDetail($table, "$rows baris");

                continue;
            }

            $bar = $this->output->createProgressBar($rows);
            $bar->setFormat("  %message:-16s% %current%/%max% [%bar%] %percent:3s%%\n");
            $bar->setMessage($table);
            $bar->start();

            // FK dimatikan supaya urutan tabel tidak perlu diurus: paket menunjuk
            // akun, dan salah satunya pasti disalin duluan.
            Schema::connection($target)->withoutForeignKeyConstraints(function () use ($source, $target, $table, $bar) {
                DB::connection($target)->table($table)->delete();

                $source->table($table)->orderBy(
                    $source->getSchemaBuilder()->hasColumn($table, 'id') ? 'id' : $this->kolomPertama($source, $table)
                )->chunk(self::CHUNK, function ($chunk) use ($target, $table, $bar) {
                    DB::connection($target)->table($table)->insert(
                        array_map(fn ($row) => (array) $row, $chunk->all())
                    );
                    $bar->advance($chunk->count());
                });
            });

            $bar->finish();
            $total += $rows;
        }

        $this->newLine();
        $this->components->info("$total baris disalin.");

        return self::SUCCESS;
    }

    private function pindahFlyer(): int
    {
        $this->components->info('2/2 · Flyer');

        $root = storage_path('flyers');
        if (! is_dir($root)) {
            $this->components->warn('storage/flyers tidak ada, dilewat.');

            return self::SUCCESS;
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() !== '.gitignore') {
                $files[substr($file->getPathname(), strlen($root) + 1)] = $file->getPathname();
            }
        }

        $disk = Storage::disk('flyers');

        // Satu listing untuk seluruh bucket, bukan exists() per file: yang kedua
        // itu 450 HEAD request untuk pertanyaan yang sama.
        $sudah = array_flip($disk->allFiles());
        $sisa = array_diff_key($files, $sudah);

        $this->components->twoColumnDetail(
            'lokal '.count($files).' file',
            'di tujuan '.count($sudah).' · perlu diunggah '.count($sisa),
        );

        if ($this->option('pretend') || $sisa === []) {
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar(count($sisa));
        $bar->setFormat("  %current%/%max% [%bar%] %percent:3s%% %message%\n");
        $bar->start();

        $gagal = [];
        foreach ($sisa as $key => $path) {
            $bar->setMessage($key);
            $stream = fopen($path, 'rb');
            if ($stream === false || ! $disk->writeStream($key, $stream)) {
                $gagal[] = $key;
            }
            if (is_resource($stream)) {
                fclose($stream);
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        if ($gagal !== []) {
            $this->components->warn(count($gagal).' file gagal diunggah, jalankan lagi untuk mencoba sisanya:');
            foreach (array_slice($gagal, 0, 10) as $key) {
                $this->line("    $key");
            }

            return self::FAILURE;
        }

        $this->components->info(count($sisa).' flyer terunggah.');

        return self::SUCCESS;
    }

    /**
     * Kolom apa saja untuk `orderBy` — chunk() butuh urutan yang stabil, dan tabel
     * pivot seperti excluded_posts tidak punya `id`.
     */
    private function kolomPertama($connection, string $table): string
    {
        return $connection->getSchemaBuilder()->getColumnListing($table)[0];
    }
}
