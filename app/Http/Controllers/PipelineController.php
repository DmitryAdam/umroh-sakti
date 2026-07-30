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
     * Dua satuan yang sengaja dipisah, karena kalau dicampur corongnya bohong:
     * **post** (satu postingan IG) dan **paket** (satu gambar penawaran). Satu
     * carousel = satu post tapi bisa jadi beberapa hasil ekstraksi dan beberapa
     * paket, jadi `paket > post` itu wajar dan bukan tanda dobel.
     *
     * @return array<string, mixed>
     */
    private function numbers(): array
    {
        $antrianPer = DB::table('jobs')->selectRaw('queue, count(*) as total')
            ->groupBy('queue')->pluck('total', 'queue');
        $antrian = (int) $antrianPer->sum();

        // Satu glob per direktori, lalu dibandingkan sebagai himpunan. Cek per-post
        // (glob di dalam loop) berarti ratusan syscall tiap polling 2 detik.
        $raw = array_map('basename', array_map('dirname', glob(storage_path('raw/*/*/post.json')) ?: []));
        $hasil = glob(storage_path('extracted/*.json')) ?: [];

        // Post yang ditolak filenya dihapus, jadi yang mengingat "pernah diunduh"
        // tinggal barisnya di excluded_posts. Sekalian lebih jujur dari hitungan
        // folder: barisnya ikut hilang kalau DB di-reset, foldernya tidak.
        $dikecualikan = DB::table('excluded_posts')->count();

        // "17890-2.json" dan "17890.json" sama-sama post 17890: carousel dipecah
        // per gambar, jadi nama filenya bukan satuan post.
        $dibaca = array_unique(array_map(
            fn ($f) => explode('-', pathinfo($f, PATHINFO_FILENAME))[0], $hasil,
        ));

        $status = Package::query()->selectRaw('status, count(*) as total')
            ->groupBy('status')->pluck('total', 'status');

        return [
            // Baris terakhir per antrian: ig sedang fetch siapa, ai sedang mengekstrak
            // apa, db sedang mengimpor apa. Sumbernya satu — storage/pipeline.jsonl.
            'sekarang' => PipelineLog::current(),
            // Jejak detail: request ke graph.facebook.com, tiap gambar yang di-download,
            // request ke model vision & penyusun, nama file yang ditulis.
            'log' => PipelineLog::tail(80),

            'akun' => SourceAccount::approved()->count(),
            'terfetch' => SourceAccount::approved()->whereNotNull('last_fetched_at')->count(),
            'akun_gagal' => SourceAccount::approved()->whereNotNull('last_error')->count(),

            // Corong satuan POST.
            // Diunduh = yang masih di raw + yang sudah dikecualikan. Tanpa yang kedua,
            // angkanya turun tiap import (file post buangan dihapus) dan kelihatan
            // seperti post hilang.
            'post_diunduh' => count($raw) + $dikecualikan,
            'post_menunggu' => count(array_diff($raw, $dibaca)),
            'post_dibaca' => count(array_intersect($raw, $dibaca)) + $dikecualikan,
            'post_dikecualikan' => $dikecualikan,
            'alasan' => DB::table('excluded_posts')->selectRaw('reason, count(*) as total')
                ->groupBy('reason')->pluck('total', 'reason'),

            // Corong satuan PAKET.
            'hasil_ekstraksi' => count($hasil),
            'paket' => (int) $status->sum(),
            'draft' => (int) ($status['draft'] ?? 0),
            'review' => (int) ($status['review'] ?? 0),
            'published' => (int) ($status['published'] ?? 0),

            'antrian' => $antrian,
            'antri_ig' => (int) ($antrianPer['ig'] ?? 0),
            'antri_ai' => (int) ($antrianPer['ai'] ?? 0),
            'antri_db' => (int) ($antrianPer['db'] ?? 0),
            'gagal' => DB::table('failed_jobs')->count(),
            'jalan' => $antrian > 0,
        ];
    }
}
