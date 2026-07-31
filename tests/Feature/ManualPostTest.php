<?php

namespace Tests\Feature;

use App\Jobs\ExtractPost;
use App\Models\Package;
use App\Models\SourceAccount;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ManualPostTest extends TestCase
{
    use RefreshDatabase;

    private const AKUN = 'uji_manual_acc';

    /** Shortcode -> pk: keduanya harus tetap sepasang, itu yang bikin kiriman ulang menimpa. */
    private const PERMALINK = 'https://www.instagram.com/p/DV-tyQIkuw5/?img_index=1';

    private const MEDIA = '3854719696466275385';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsOperator();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('raw/'.self::AKUN));
        File::delete(glob(storage_path('extracted/'.self::MEDIA.'*.json')) ?: []);

        parent::tearDown();
    }

    /** @param array<string, mixed> $ganti */
    private function kirim(array $ganti = []): TestResponse
    {
        return $this->post(route('posts.store'), array_merge([
            'permalink' => self::PERMALINK,
            'account' => self::AKUN,
            'posted_at' => '2026-03-14',
            'caption' => 'Paket umroh Maret',
            'images' => [UploadedFile::fake()->image('flyer.png', 900, 1200)],
        ], $ganti));
    }

    /**
     * Formulirnya benar-benar dirender, bukan cuma route-nya ada. Blade menelan
     * `<x-ui.input …>` yang tidak ditutup `/>` jadi slot dan tumbang sebagai
     * ParseError — dan itu cuma kelihatan kalau viewnya di-compile.
     */
    public function test_formulir_terbuka_untuk_operator(): void
    {
        SourceAccount::create(['username' => self::AKUN, 'status' => 'approved']);

        $this->get(route('posts.create'))
            ->assertOk()
            ->assertSee('name="permalink"', false)
            ->assertSee('name="images[]"', false)
            // Akun yang sudah ada jadi saran <datalist>, tapi tetap boleh diketik baru.
            ->assertSee(self::AKUN);
    }

    public function test_post_manual_masuk_raw_dan_antrian_ai(): void
    {
        $this->kirim()->assertRedirect(route('posts.create'));

        $dir = storage_path('raw/'.self::AKUN.'/'.self::MEDIA);
        $this->assertFileExists("$dir/0.jpg");

        $post = json_decode((string) file_get_contents("$dir/post.json"), true);
        $this->assertSame(self::MEDIA, $post['id']);
        $this->assertSame(self::AKUN, $post['_source_account']);
        $this->assertSame(['0.jpg'], $post['_images']);
        $this->assertTrue($post['_manual']);
        // Jangkar tahun buat penyusun — tanpa ini "14 Maret" dibaca sebagai tahun berjalan.
        $this->assertStringStartsWith('2026-03-14T', $post['timestamp']);
        $this->assertSame(self::PERMALINK, $post['permalink']);

        // Akun yang belum terdaftar dibuat sekalian, langsung approved.
        $this->assertSame('approved', SourceAccount::where('username', self::AKUN)->value('status'));

        Queue::assertPushed(ExtractPost::class);
    }

    public function test_kiriman_ulang_menimpa_bukan_menumpuk(): void
    {
        $this->kirim();

        // Simulasikan hasil putaran pertama: post divonis bukan-paket dan sudah punya baris.
        DB::table('excluded_posts')->insert([
            'media_id' => self::MEDIA, 'source_account' => self::AKUN, 'reason' => 'bukan_paket',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Package::create([
            'media_id' => self::MEDIA, 'source_account' => self::AKUN, 'flyer_index' => 0,
            'offer_index' => 0, 'departure_date' => '2026-09-01', 'price_quad' => 30_000_000,
            'dedup_key' => 'uji-manual', 'status' => 'draft',
        ]);
        File::put(storage_path('extracted/'.self::MEDIA.'-0.json'), '{}');

        $this->kirim(['images' => [UploadedFile::fake()->image('flyer2.png', 900, 1200)]])
            ->assertRedirect(route('posts.create'));

        // Blok dilepas, jejak lama dibuang — kalau tidak, importOne() melihat barisnya
        // sudah ada dan kiriman kedua diam-diam tidak mengubah apa pun.
        $this->assertDatabaseMissing('excluded_posts', ['media_id' => self::MEDIA]);
        $this->assertSame(0, Package::where('media_id', self::MEDIA)->count());
        $this->assertFileDoesNotExist(storage_path('extracted/'.self::MEDIA.'-0.json'));
        $this->assertFileExists(storage_path('raw/'.self::AKUN.'/'.self::MEDIA.'/0.jpg'));
    }

    public function test_permalink_bukan_post_ditolak(): void
    {
        $this->kirim(['permalink' => 'https://www.instagram.com/'.self::AKUN])
            ->assertSessionHasErrors('permalink');

        $this->assertDirectoryDoesNotExist(storage_path('raw/'.self::AKUN));
        Queue::assertNothingPushed();
    }

    public function test_tanggal_posting_wajib(): void
    {
        $this->kirim(['posted_at' => ''])->assertSessionHasErrors('posted_at');

        Queue::assertNothingPushed();
    }

    public function test_tamu_tidak_bisa_menambah_post(): void
    {
        auth()->logout();

        $this->get(route('posts.create'))->assertRedirect(route('login'));
        $this->kirim()->assertRedirect(route('login'));

        Queue::assertNothingPushed();
    }
}
