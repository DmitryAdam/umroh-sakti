<?php

namespace App\Console\Commands;

use App\Models\SourceAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Isi kolom profil (`full_name`, followers/follows/media count) + foto profil akun.
 *
 * Terpisah dari `FetchAccount` karena requestnya beda harga: `probe.php profile`
 * tidak meminta ekspansi `media{children{}}` sama sekali, dan itu bagian yang
 * membakar `total_time`. Jadi 200 akun bisa diisi tanpa men-scrap ulang postnya.
 *
 * Defaultnya cuma akun yang profilnya masih kosong; `--all` untuk menyegarkan
 * semuanya (angka pengikut bergerak terus).
 */
class FetchProfiles extends Command
{
    protected $signature = 'accounts:profile
                            {--all : termasuk akun yang profilnya sudah terisi}
                            {--sleep= : jeda detik antar akun (default IG_FETCH_SLEEP)}';

    protected $description = 'Ambil profil IG (pengikut/diikuti/post/foto) untuk akun sumber';

    public function handle(): int
    {
        $accounts = SourceAccount::approved()
            ->when(! $this->option('all'), fn ($q) => $q->whereNull('followers_count'))
            ->orderBy('username')
            ->get();

        if ($accounts->isEmpty()) {
            $this->info('Tidak ada akun yang perlu diisi. Pakai --all untuk menyegarkan semuanya.');

            return self::SUCCESS;
        }

        $this->info("Ambil profil {$accounts->count()} akun…");

        // Satu proses untuk seluruh daftar: jeda antar akun sudah diurus probe.php,
        // dan satu akun yang gagal tidak menghentikan sisanya.
        $result = Process::timeout(3600)->run(array_merge(
            [PHP_BINARY, base_path('probe.php'), 'profile'],
            $accounts->pluck('username')->all(),
            array_filter(['--sleep='.$this->option('sleep')], fn ($o) => $o !== '--sleep='),
        ), fn ($type, $line) => $this->output->write($line));

        // Jalan walau prosesnya gagal di tengah: yang sempat tersimpan tetap dipanen.
        $terisi = 0;
        foreach ($accounts as $account) {
            if ($profile = $account->profileFromDisk()) {
                $account->update($profile);
                $terisi++;
            }
        }

        $this->info("$terisi akun terisi profilnya.");

        return $result->successful() ? self::SUCCESS : self::FAILURE;
    }
}
