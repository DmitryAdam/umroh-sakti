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
        {--limit=50 : post per akun}
        {--new : cuma akun yang belum pernah berhasil di-scrap}';

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

        // `--new` pakai last_fetched_at (penanda terakhir BERHASIL), jadi akun yang
        // percobaan terakhirnya gagal ikut lagi — itu memang yang mau diulang.
        $accounts = SourceAccount::approved()
            ->when($this->option('new'), fn ($q) => $q->whereNull('last_fetched_at'))
            ->get();

        if ($accounts->isEmpty()) {
            $this->error($this->option('new')
                ? 'Semua akun approved sudah pernah berhasil di-scrap.'
                : 'Belum ada akun approved. Jalankan: php artisan packages:crawl accounts.txt');

            return self::FAILURE;
        }

        foreach ($accounts as $account) {
            FetchAccount::dispatch($account, (int) $this->option('limit'));
        }

        $this->info($accounts->count().' akun masuk antrian. Jalankan worker: php artisan queue:work');

        return self::SUCCESS;
    }
}
