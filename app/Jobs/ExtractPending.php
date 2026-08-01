<?php

namespace App\Jobs;

use App\Support\PipelineLog;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Pemindai, bukan pengekstrak: melempar satu ExtractPost per post yang belum
 * punya hasil, lalu selesai dalam hitungan milidetik. Kerja beratnya di
 * ExtractPost supaya fetch tidak tertahan di belakang satu job panjang.
 *
 * Unique-until-processing: fetch yang selesai di tengah pemindaian tetap dapat
 * pemindaian berikutnya, tidak ditelan lock.
 */
class ExtractPending implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    // Pemindaian cuma hitungan milidetik; TTL panjang malah menahan pemindaian
    // berikutnya kalau worker mati di tengah.
    public int $uniqueFor = 120;

    public function __construct()
    {
        $this->onQueue('db');
    }

    public function handle(): void
    {
        $antri = 0;

        // Yang sudah punya baris di `jobs` tidak dilempar lagi. Lock unique-nya
        // ExtractPost cuma hidup 600 detik sementara backlog `ai` bisa berjam-jam,
        // jadi lock itu kadaluwarsa selagi jobnya masih antri dan pemindaian
        // berikutnya melempar media yang sama — terukur 179 baris untuk 81 media.
        // Tabel jobs itu kebenarannya, bukan cache_locks (yang juga dihapus tiap
        // tombol batal ditekan).
        $sudahAntri = DB::table('jobs')->where('queue', 'ai')->pluck('payload')
            ->flatMap(fn ($p) => preg_match('/mediaId";s:\d+:"(\d+)"/',
                json_decode($p, true)['data']['command'] ?? '', $m) ? [$m[1]] : [])
            ->flip()->all();

        foreach (glob(storage_path('raw/*/*/post.json')) ?: [] as $file) {
            $mediaId = basename(dirname($file));

            if (isset($sudahAntri[$mediaId])) {
                continue;
            }

            // Sudah ada hasilnya = tidak dikirim ulang ke model. ExtractPost juga
            // unique per media_id, jadi dua pemindaian beruntun tidak bikin dobel.
            // Satu post bisa menghasilkan beberapa file (carousel dipecah per gambar),
            // jadi yang dicek pola namanya, bukan satu file.
            if (glob(storage_path("extracted/$mediaId{.json,-*.json}"), GLOB_BRACE) !== []) {
                continue;
            }

            // Usulan dari peran `user` menunggu approval admin: rawnya sudah ada,
            // tapi belum boleh dibayar ke model. Penandanya `_suggested_by` di
            // post.json, dan tombol "setujui" yang membuangnya. Dibaca cuma di
            // cabang ini — post yang sudah punya hasil tidak perlu dibuka filenya.
            if (str_contains((string) @file_get_contents($file), '"_suggested_by"')) {
                continue;
            }

            ExtractPost::dispatch($mediaId);
            $antri++;
        }

        PipelineLog::write('db', "pemindaian: $antri post belum diekstrak, masuk antrian ai");
    }
}
