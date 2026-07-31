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
        {--new : cuma akun yang belum pernah berhasil di-scrap}
        {--failed : cuma akun yang gagal dan belum pernah berhasil di-scrap}
        {--cooldown=0 : lewati akun yang berhasil di-scrap dalam N jam terakhir}';

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

        // `--new` dan `--cooldown` sama-sama pakai last_fetched_at (penanda terakhir
        // BERHASIL), jadi akun yang percobaan terakhirnya gagal ikut lagi — itu memang
        // yang mau diulang. Cooldown menjaga kuota Graph: yang mengikat `total_time`,
        // dan fetch ulang akun yang barusan di-scrap membakarnya tanpa post baru.
        $jam = (int) $this->option('cooldown');

        $accounts = SourceAccount::approved()
            ->when($this->option('new'), fn ($q) => $q->whereNull('last_fetched_at'))
            // `--failed` = definisi gagal di SourceAccount::gagal() minus cek isi —
            // itu hitungan dari disk + tabel lain, tidak ada kolomnya untuk di-WHERE.
            // Yang kelebihan cuma akun yang postnya kadung terunduh lalu fetch-nya
            // putus, dan itu memang yang mau diulang.
            ->when($this->option('failed'), fn ($q) => $q
                ->whereNull('last_fetched_at')->whereNotNull('last_error'))
            ->when($jam > 0, fn ($q) => $q->where(fn ($w) => $w
                ->whereNull('last_fetched_at')
                ->orWhere('last_fetched_at', '<', now()->subHours($jam))))
            // Yang paling lama tidak disentuh duluan: antrian `ig` cuma satu worker
            // dan kuota Graph bisa habis di tengah jalan, jadi urutan menentukan
            // siapa yang kebagian.
            ->orderByRaw('last_fetched_at is null desc, last_fetched_at asc')
            ->get();

        if ($accounts->isEmpty()) {
            $this->error(match (true) {
                (bool) $this->option('new') => 'Semua akun approved sudah pernah berhasil di-scrap.',
                (bool) $this->option('failed') => 'Tidak ada akun approved yang gagal dan masih kosong.',
                $jam > 0 => "Semua akun approved sudah di-scrap dalam $jam jam terakhir.",
                default => 'Belum ada akun approved. Jalankan: php artisan packages:crawl accounts.txt',
            });

            return self::FAILURE;
        }

        foreach ($accounts as $account) {
            FetchAccount::dispatch($account, (int) $this->option('limit'));
        }

        $this->info($accounts->count().' akun masuk antrian. Jalankan worker: php artisan queue:work');

        return self::SUCCESS;
    }
}
