<?php

namespace Tests\Feature;

use App\Jobs\ExtractPost;
use App\Jobs\FetchAccount;
use App\Models\Package;
use App\Models\SourceAccount;
use App\Support\ExcludedPost;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tombol "baca ulang" + "ambil ulang" di kartu paket. Dua-duanya alat kerja
 * operator, sama seperti tombol × — grup `auth` di routes/web.php.
 */
class PackageActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsOperator();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Queue::fake();
        Storage::fake(Package::FLYER_DISK);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('raw/uji'));
        File::delete(storage_path('extracted/9001.json'));

        parent::tearDown();
    }

    private function paket(): Package
    {
        File::ensureDirectoryExists(storage_path('raw/uji/9001'));
        File::put(storage_path('raw/uji/9001/post.json'), '{"id":"9001"}');
        File::put(storage_path('extracted/9001.json'), '{}');
        Storage::disk(Package::FLYER_DISK)->put('9001/1.jpg', 'flyerbytes');

        return Package::create([
            'source_account' => 'uji',
            'media_id' => '9001',
            'flyer_index' => 1,
            'status' => 'review',
        ]);
    }

    public function test_baca_ulang_mengembalikan_flyer_ke_raw_dan_menghapus_barisnya(): void
    {
        $package = $this->paket();

        $this->post(route('package.reextract', $package))->assertOk();

        // Extract cuma membaca storage/raw — tanpa langkah ini vision tidak dapat flyernya.
        $this->assertSame('flyerbytes', File::get(storage_path('raw/uji/9001/1.jpg')));
        // Hasil lama dibuang + barisnya dihapus, kalau tidak importOne melewatinya.
        $this->assertFileDoesNotExist(storage_path('extracted/9001.json'));
        $this->assertModelMissing($package);
        // Postnya TIDAK dikecualikan: ini bukan vonis "bukan paket".
        $this->assertDatabaseCount('excluded_posts', 0);
        $this->assertFileExists(storage_path('raw/uji/9001/post.json'));

        Queue::assertPushed(ExtractPost::class, fn ($job) => $job->mediaId === '9001');
    }

    public function test_baca_ulang_menolak_kalau_raw_postnya_sudah_hilang(): void
    {
        $package = $this->paket();
        File::deleteDirectory(storage_path('raw/uji/9001'));

        $this->post(route('package.reextract', $package))->assertStatus(409);

        $this->assertModelExists($package);
        Queue::assertNothingPushed();
    }

    public function test_ambil_ulang_membuang_jejaknya_lalu_mengantrikan_fetch(): void
    {
        $package = $this->paket();
        $account = SourceAccount::create(['username' => 'uji', 'status' => 'approved']);

        $this->post(route('package.refetch', $package))->assertOk();

        // Fetch melewati post yang rawnya masih ada, jadi raw-nya wajib dibuang dulu.
        $this->assertDirectoryDoesNotExist(storage_path('raw/uji/9001'));
        // Hasil ekstraksi lama + barisnya ikut dibuang, kalau tidak downloadnya sia-sia:
        // ExtractPending melewati post yang sudah punya file di storage/extracted, dan
        // importOne tidak pernah menimpa baris yang sudah ada.
        $this->assertFileDoesNotExist(storage_path('extracted/9001.json'));
        $this->assertModelMissing($package);
        // Postnya TIDAK dikecualikan: ini bukan vonis "bukan paket".
        $this->assertDatabaseCount('excluded_posts', 0);

        Queue::assertPushed(FetchAccount::class, fn ($job) => $job->account->is($account));
    }

    public function test_ambil_ulang_membuang_semua_slide_se_media_id(): void
    {
        $package = $this->paket();
        $saudara = Package::create(['source_account' => 'uji', 'media_id' => '9001', 'flyer_index' => 2, 'status' => 'review']);
        SourceAccount::create(['username' => 'uji', 'status' => 'approved']);

        $this->post(route('package.refetch', $package))->assertOk();

        // Postnya di-download utuh: nomor slide dari vision menunjuk gambar ke-N yang
        // dikirim, jadi sisa baris lama bikin penomorannya tabrakan.
        $this->assertModelMissing($saudara);
    }

    public function test_status_bisa_diubah_ke_published_dan_menolak_nilai_asing(): void
    {
        $package = $this->paket();

        $this->patchJson(route('package.status', $package), ['status' => 'published'])->assertOk();
        $this->assertSame('published', $package->fresh()->status);

        // `rejected` ada di komentar migrasi tapi bukan status yang dipakai —
        // paket yang ditolak dihapus barisnya, bukan distempel.
        $this->patchJson(route('package.status', $package), ['status' => 'rejected'])->assertStatus(422);
        $this->assertSame('published', $package->fresh()->status);
    }

    public function test_ambil_ulang_menolak_kalau_akunnya_tidak_terdaftar(): void
    {
        $this->post(route('package.refetch', $this->paket()))->assertStatus(409);

        $this->assertFileExists(storage_path('raw/uji/9001/post.json'));
        Queue::assertNothingPushed();
    }

    public function test_baca_ulang_post_melepas_blok_penolakan_lalu_extract(): void
    {
        $package = $this->paket();
        $account = SourceAccount::create(['username' => 'uji', 'status' => 'approved']);
        ExcludedPost::add('9001', 'uji', 'bukan_paket');

        $this->post(route('posts.reextract', '9001'))->assertRedirect();

        // Blok itu yang bikin extract melewati postnya — tanpa dilepas, tombolnya no-op.
        $this->assertDatabaseCount('excluded_posts', 0);
        $this->assertSame('flyerbytes', File::get(storage_path('raw/uji/9001/1.jpg')));
        $this->assertFileDoesNotExist(storage_path('extracted/9001.json'));
        $this->assertModelMissing($package);

        // Gerbangnya TETAP jalan: cuma vision yang melihat pixelnya, jadi cuma dia
        // yang bisa memastikan postnya benar-benar penawaran umroh.
        Queue::assertPushed(ExtractPost::class, fn ($job) => $job->mediaId === '9001');
    }

    public function test_baca_ulang_post_cuma_menyentuh_postnya_bukan_scrap_akun(): void
    {
        $account = SourceAccount::create(['username' => 'uji', 'status' => 'approved']);
        ExcludedPost::add('9001', 'uji', 'haji');

        $this->post(route('posts.reextract', '9001'))->assertRedirect();

        // Rawnya sudah dihapus -> tidak ada yang bisa dibaca. Yang TIDAK boleh terjadi:
        // men-scrap ulang seluruh akun, itu tombolnya sendiri di /accounts.
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('excluded_posts', 1);
    }
}
