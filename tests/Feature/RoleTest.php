<?php

namespace Tests\Feature;

use App\Jobs\ExtractPending;
use App\Jobs\ExtractPost;
use App\Models\SourceAccount;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Dua peran. Yang dijaga di sini cuma satu kalimat: usulan peran `user` tidak
 * menjalankan apa pun — tidak masuk antrian ig, tidak dibayar ke model, dan tidak
 * bisa menimpa data yang sudah ada. Sisanya (403 di alat kerja) tinggal route.
 */
class RoleTest extends TestCase
{
    use RefreshDatabase;

    private const AKUN = 'uji_usulan_acc';

    private const PERMALINK = 'https://www.instagram.com/p/DV-tyQIkuw5/';

    private const MEDIA = '3854719696466275385';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('raw/'.self::AKUN));

        parent::tearDown();
    }

    private function kirimPost(): TestResponse
    {
        return $this->post(route('posts.store'), [
            'permalink' => self::PERMALINK,
            'account' => self::AKUN,
            'posted_at' => '2026-03-14',
            'images' => [UploadedFile::fake()->image('flyer.png', 900, 1200)],
        ]);
    }

    private function rawJson(): array
    {
        return json_decode((string) file_get_contents(
            storage_path('raw/'.self::AKUN.'/'.self::MEDIA.'/post.json'),
        ), true);
    }

    public function test_peran_user_tidak_dapat_alat_kerja(): void
    {
        $this->actingAsPengusul();

        $this->get(route('accounts'))->assertForbidden();
        $this->get(route('posts'))->assertForbidden();
        $this->get(route('pipeline.status'))->assertForbidden();
        $this->get(route('suggestions'))->assertOk();
    }

    public function test_usulan_akun_masuk_pending_bukan_approved(): void
    {
        $this->actingAsPengusul();

        $this->post(route('accounts.store'), ['usernames' => self::AKUN]);

        $account = SourceAccount::firstWhere('username', self::AKUN);
        $this->assertSame('pending', $account->status);
        $this->assertSame('pengusul@umroh.test', $account->suggested_by);
        // Yang menahan scrap itu statusnya: semua jalur crawl menyaring approved.
        $this->assertSame(0, SourceAccount::approved()->count());

        // Admin melihatnya sebagai antrean approval, lengkap dengan tombolnya.
        $this->actingAsOperator()->get(route('accounts'))
            ->assertOk()
            ->assertSee('usulan akun')
            ->assertSee('value="approve"', false);

        $this->post(route('accounts.bulk'), ['action' => 'approve', 'ids' => [$account->id]]);
        $this->assertSame('approved', $account->refresh()->status);
    }

    public function test_usulan_post_tersimpan_tapi_tidak_dibaca_ai(): void
    {
        $this->actingAsPengusul();

        $this->kirimPost()->assertRedirect(route('suggestions'));

        $this->assertSame('pengusul@umroh.test', $this->rawJson()['_suggested_by']);
        // Dilingkupi media_id: storage/raw mesin dev berisi post lain yang memang
        // layak diantrikan, dan itu bukan yang sedang diuji di sini.
        Queue::assertNotPushed(ExtractPost::class, fn (ExtractPost $job) => $job->mediaId === self::MEDIA);

        // Pemindai antrian db juga tidak boleh mengambilnya diam-diam.
        (new ExtractPending)->handle();
        // Dilingkupi media_id: storage/raw mesin dev berisi post lain yang memang
        // layak diantrikan, dan itu bukan yang sedang diuji di sini.
        Queue::assertNotPushed(ExtractPost::class, fn (ExtractPost $job) => $job->mediaId === self::MEDIA);
    }

    public function test_usulan_tidak_bisa_menimpa_post_yang_sudah_ada(): void
    {
        $this->actingAsOperator();
        $this->kirimPost();
        $rawAdmin = $this->rawJson();

        $this->actingAsPengusul();
        $this->kirimPost()->assertSessionHasErrors('permalink');

        // Isinya tidak berubah — kiriman kedua tidak menghapus raw/paketnya.
        $this->assertSame($rawAdmin, $this->rawJson());
    }

    public function test_admin_menyetujui_usulan_post(): void
    {
        $this->actingAsPengusul();
        $this->kirimPost();

        $this->actingAsOperator();
        $this->post(route('posts.reextract', self::MEDIA))->assertRedirect();

        // Penandanya dibuang, jadi pemindai berikutnya ikut mengambilnya kalau job ini gagal.
        $this->assertArrayNotHasKey('_suggested_by', $this->rawJson());
        Queue::assertPushed(ExtractPost::class);
    }
}
