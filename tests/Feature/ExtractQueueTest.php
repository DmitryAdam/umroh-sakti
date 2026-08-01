<?php

namespace Tests\Feature;

use App\Jobs\ExtractPending;
use App\Jobs\ExtractPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Antrian `ai` = post yang gambarnya sudah ada tapi hasilnya belum. Satu baris
 * per post: bukan yang sudah punya hasil, bukan dua kali untuk post yang sama.
 *
 * Storage-nya dipindah ke direktori sementara lewat useStoragePath(). Wajib:
 * phpunit.xml cuma memindahkan DB ke :memory:, jadi storage_path() di dalam test
 * menunjuk storage/ yang asli — dan test yang membersihkan direktorinya sendiri
 * akan menghapus storage/raw beneran. Sudah kejadian: 473 post hilang.
 */
class ExtractQueueTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = sys_get_temp_dir().'/umroh-test-'.getmypid();
        File::ensureDirectoryExists($this->storage.'/extracted');
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    private function rawPost(string $media): void
    {
        File::ensureDirectoryExists(storage_path("raw/travel/$media"));
        File::put(storage_path("raw/travel/$media/post.json"), '{}');
    }

    public function test_pemindai_melewati_yang_sudah_antri(): void
    {
        Queue::fake();
        $this->rawPost('111');

        (new ExtractPending)->handle();
        Queue::assertPushed(ExtractPost::class, 1);

        // Baris `jobs`-nya yang jadi kebenaran, bukan lock unique yang umurnya
        // cuma 600 detik sementara backlog `ai` bisa berjam-jam.
        DB::table('jobs')->insert([
            'queue' => 'ai', 'attempts' => 0, 'available_at' => 0, 'created_at' => 0,
            'payload' => json_encode(['data' => ['command' => 'O:19:"App\Jobs\ExtractPost":1:{s:7:"mediaId";s:3:"111";}']]),
        ]);

        (new ExtractPending)->handle();
        Queue::assertPushed(ExtractPost::class, 1);
    }

    public function test_post_yang_sudah_punya_hasil_tidak_memanggil_model(): void
    {
        $this->rawPost('222');
        File::put(storage_path('extracted/222-1.json'), '{}');

        // Kalau guard-nya jebol, handle() men-spawn probe.php dan tesnya lama.
        (new ExtractPost('222'))->handle();

        $this->assertSame([], glob(storage_path('extracted/222.json')));
    }
}
