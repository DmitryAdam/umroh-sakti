<?php

namespace App\Console\Commands;

use App\Jobs\FetchAccount;
use App\Models\SourceAccount;
use Illuminate\Console\Command;

/**
 * Titik masuk pipeline: daftar akun -> queue fetch -> extract -> import.
 * Semuanya lewat queue; command ini cuma melempar job dan selesai.
 */
class CrawlAccounts extends Command
{
    protected $signature = 'packages:crawl
        {file? : daftar username/URL Instagram, satu per baris — didaftarkan sebagai approved}
        {--limit=50 : post per akun}';

    protected $description = 'Antrikan fetch untuk semua akun approved';

    public function handle(): int
    {
        if ($file = $this->argument('file')) {
            if (! is_file($file)) {
                $this->error("File $file tidak ada.");

                return self::FAILURE;
            }
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $this->info(count(SourceAccount::register($lines)).' akun baru didaftarkan.');
        }

        $accounts = SourceAccount::approved()->get();
        if ($accounts->isEmpty()) {
            $this->error('Belum ada akun approved. Jalankan: php artisan packages:crawl accounts.txt');

            return self::FAILURE;
        }

        foreach ($accounts as $account) {
            FetchAccount::dispatch($account, (int) $this->option('limit'));
        }

        $this->info($accounts->count().' akun masuk antrian. Jalankan worker: php artisan queue:work');

        return self::SUCCESS;
    }
}
