<?php

namespace Tests\Feature;

use App\Console\Commands\QueueWork;
use App\Jobs\ExtractPost;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tombol "ulangi" per antrian: job gagal kembali ke antriannya, bukan dibuang.
 *
 * Isi `failed_jobs` di sini mayoritas layak dicoba lagi (worker di-Ctrl+C,
 * `database is locked`, model timeout) — sebelum ada endpoint ini satu-satunya
 * jalan `php artisan queue:retry` dari terminal.
 */
class PipelineRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Panel pipeline itu alat kerja operator — grup `auth`.
        $this->actingAsOperator();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    /**
     * Tombol stop cuma memasang flag; job yang antri tidak ikut dibuang.
     *
     * Bedanya dengan "batalkan": itu membuang job, ini menghentikan yang
     * mengerjakan — antriannya jalan lagi begitu `queue:work` dinyalakan.
     * Yang membaca flagnya loop induk `QueueWork`, bukan `pkill` dari web (argv
     * anak identik lintas project di satu server).
     */
    public function test_stop_memasang_flag_tanpa_membuang_antrian(): void
    {
        $this->gagal('ai', '111');
        DB::table('jobs')->insert([
            'queue' => 'ai', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time(),
        ]);

        $this->postJson(route('pipeline.stop'))->assertOk();

        $this->assertTrue(Cache::get(QueueWork::STOP), 'flag stop harus terpasang');
        $this->assertSame(1, DB::table('jobs')->count(), 'job yang antri jangan ikut dibuang');
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    /**
     * Jumlah worker per antrian: setelan di cache, dijepit 0..MAX_WORKERS, dan
     * antrian yang tidak disebut tidak ikut berubah. 0 sah — itu "pause antrian ini".
     */
    public function test_jumlah_worker_disimpan_dan_dijepit(): void
    {
        $this->postJson(route('pipeline.workers', 'ai'), ['jumlah' => 4])->assertOk();
        $this->postJson(route('pipeline.workers', 'ig'), ['jumlah' => 0])->assertOk();
        $this->postJson(route('pipeline.workers', 'db'), ['jumlah' => 99])->assertOk();

        $this->assertSame(
            ['ig' => 0, 'ai' => 4, 'db' => QueueWork::MAX_WORKERS],
            QueueWork::jumlah(),
        );

        $this->postJson(route('pipeline.workers', 'redis'), ['jumlah' => 2])->assertNotFound();
    }

    /** Satu baris failed_jobs dengan payload yang bisa dibaca ulang framework. */
    private function gagal(string $queue, string $mediaId): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => $queue,
            'payload' => json_encode([
                'uuid' => (string) Str::uuid(),
                'displayName' => ExtractPost::class,
                'job' => 'Illuminate\Queue\CallQueuedHandler@call',
                'data' => ['commandName' => ExtractPost::class, 'command' => serialize(new ExtractPost($mediaId))],
            ]),
            'exception' => 'Symfony\Component\Process\Exception\ProcessSignaledException: signal "2".',
            'failed_at' => now(),
        ]);
    }

    public function test_ulangi_mengembalikan_job_gagal_ke_antriannya(): void
    {
        $this->gagal('ai', '111');
        $this->gagal('ig', '222');

        $this->postJson('/pipeline/queue/retry/ai')->assertOk();

        // Cuma antrian yang diminta: `ig` yang gagal karena sebab lain tidak ikut
        // diantrikan ulang diam-diam.
        $this->assertSame(1, DB::table('jobs')->where('queue', 'ai')->count());
        $this->assertSame(0, DB::table('jobs')->where('queue', 'ig')->count());
        $this->assertSame(['ig'], DB::table('failed_jobs')->pluck('queue')->all());
    }

    public function test_ulangi_tanpa_antrian_mengembalikan_semuanya(): void
    {
        $this->gagal('ai', '111');
        $this->gagal('ig', '222');

        $this->postJson('/pipeline/queue/retry')->assertOk();

        $this->assertSame(2, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_nama_antrian_asing_ditolak(): void
    {
        $this->gagal('ai', '111');

        $this->postJson('/pipeline/queue/retry/rm-rf')->assertNotFound();
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }
}
