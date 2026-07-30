<?php

namespace App\Http\Controllers;

use App\Jobs\ExtractPending;
use App\Models\Package;
use App\Models\SourceAccount;
use App\Support\PipelineLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Tombol + progress pipeline, biar tidak perlu bolak-balik ke terminal.
 * Tetap lewat queue: tombolnya cuma melempar job, worker (`php artisan queue:work`)
 * yang mengerjakan. Dikunci ke env local — ini alat kerja, bukan fitur publik.
 */
class PipelineController extends Controller
{
    public function start(): JsonResponse
    {
        abort_unless(app()->isLocal(), 404);

        PipelineLog::write('run', '== tombol pipeline: antrikan fetch semua akun approved');

        Artisan::call('packages:crawl', array_filter([
            'file' => is_file(base_path('accounts.txt')) ? base_path('accounts.txt') : null,
            '--limit' => 9,
        ]));

        foreach (preg_split('/\r?\n/', Artisan::output()) as $line) {
            PipelineLog::write('run', $line);
        }

        // Pemindaian ekstraksi tidak menunggu fetch selesai: post yang sudah ada di
        // storage/raw dari putaran sebelumnya langsung masuk antrian ai.
        ExtractPending::dispatch();

        return response()->json($this->numbers());
    }

    public function status(): JsonResponse
    {
        abort_unless(app()->isLocal(), 404);

        return response()->json($this->numbers());
    }

    /**
     * Semua angka dihitung dari sumbernya langsung — tidak ada tabel progress
     * yang perlu dijaga tetap sinkron.
     *
     * @return array<string, int|string|bool|null>
     */
    private function numbers(): array
    {
        $accounts = SourceAccount::approved()->count();
        $fetched = SourceAccount::approved()->whereNotNull('last_fetched_at')->count();
        $antrian = DB::table('jobs')->count();

        return [
            // Baris terakhir per antrian: ig sedang fetch siapa, ai sedang mengekstrak
            // apa, db sedang mengimpor apa. Sumbernya satu — storage/pipeline.jsonl.
            'sekarang' => PipelineLog::current(),
            // Jejak detail: request ke graph.facebook.com, tiap gambar yang di-download,
            // request ke model vision & penyusun, nama file yang ditulis.
            'log' => PipelineLog::tail(80),
            'akun' => $accounts,
            'terfetch' => $fetched,
            'antrian' => $antrian,
            'raw' => count(glob(storage_path('raw/*/*/post.json')) ?: []),
            'extracted' => count(glob(storage_path('extracted/*.json')) ?: []),
            'dibanned' => DB::table('banned_posts')->count(),
            // Ditolak gerbang vision. Tanpa angka ini, post yang lenyap dari raw
            // kelihatan seperti hilang, padahal dibuang dengan sengaja.
            'trash' => count(glob(storage_path('trash/*/*'), GLOB_ONLYDIR) ?: []),
            'paket' => Package::count(),
            'jalan' => $antrian > 0,
        ];
    }
}
