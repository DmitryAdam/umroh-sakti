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

        $this->get(route('suggestions'))
            ->assertOk()
            ->assertSee('name="permalink"', false)
            ->assertSee('name="images[]"', false)
            // Akun yang sudah ada jadi saran <datalist>, tapi tetap boleh diketik baru.
            ->assertSee(self::AKUN);
    }

    /**
     * Kiriman admin jalannya sama persis dengan kiriman peran `user`: raw ditulis,
     * ditandai usulan, TIDAK diantrikan. Dulu admin punya jalur cepat — itu cabang
     * `if ($admin)` yang bikin satu formulir berperilaku dua macam.
     */
    public function test_post_manual_masuk_raw_sebagai_usulan(): void
    {
        $this->kirim()->assertRedirect(route('suggestions'));

        $dir = storage_path('raw/'.self::AKUN.'/'.self::MEDIA);
        $this->assertFileExists("$dir/0.jpg");

        $post = json_decode((string) file_get_contents("$dir/post.json"), true);
        $this->assertSame(self::MEDIA, $post['id']);
        $this->assertSame(self::AKUN, $post['_source_account']);
        $this->assertSame(['0.jpg'], $post['_images']);
        $this->assertTrue($post['_manual']);
        // Jejak pengirim yang permanen — beda dengan `_suggested_by` yang dibuang
        // saat disetujui. Ini yang menyusun daftar "kiriman saya".
        $this->assertSame('operator@umroh.test', $post['_created_by']);
        // Penanda "belum di-approve", ditulis untuk admin juga.
        $this->assertSame('operator@umroh.test', $post['_suggested_by']);
        // Jangkar tahun buat penyusun — tanpa ini "14 Maret" dibaca sebagai tahun berjalan.
        $this->assertStringStartsWith('2026-03-14T', $post['timestamp']);
        $this->assertSame(self::PERMALINK, $post['permalink']);

        // Akunnya juga menunggu approval, termasuk yang dikirim admin.
        $this->assertSame('pending', SourceAccount::where('username', self::AKUN)->value('status'));

        // Tidak ada yang dibayar ke model sampai admin menekan "setujui & baca".
        Queue::assertNotPushed(ExtractPost::class);
    }

    /**
     * Auto-approve nyala: kiriman siapa pun lewat jalur "setujui & baca" begitu masuk
     * — `_suggested_by` dibuang, akunnya naik `approved`, ExtractPost dilempar.
     * Saklarnya di tabel `cache`, jadi worker & CLI ikut membacanya.
     */
    public function test_auto_approve_langsung_mengantrikan_kiriman(): void
    {
        $this->post(route('posts.auto-approve'), ['on' => 1])->assertRedirect();

        $admin = auth()->user();
        $this->actingAsPengusul();
        $this->kirim()->assertRedirect(route('suggestions'));

        $post = json_decode((string) file_get_contents(
            storage_path('raw/'.self::AKUN.'/'.self::MEDIA.'/post.json')), true);
        $this->assertArrayNotHasKey('_suggested_by', $post);
        // Jejak pengirim tetap: approval bukan penghapus asal-usul.
        $this->assertSame('pengusul@umroh.test', $post['_created_by']);
        $this->assertSame('approved', SourceAccount::where('username', self::AKUN)->value('status'));
        Queue::assertPushed(ExtractPost::class);

        // Dimatikan lagi = perilaku lamanya balik, tanpa restart apa pun.
        $this->actingAs($admin);
        $this->post(route('posts.auto-approve'), ['on' => 0])->assertRedirect();
        $this->get(route('posts', ['filter' => 'suggestions']))->assertOk()->assertSee('Auto-approve mati');
    }

    /** Saklarnya milik admin: peran `user` tidak boleh menyetujui kirimannya sendiri. */
    public function test_pengusul_tidak_bisa_menyalakan_auto_approve(): void
    {
        $this->actingAsPengusul();

        $this->post(route('posts.auto-approve'), ['on' => 1])->assertForbidden();
    }

    /** Daftar "kiriman saya" memakai `_created_by`, jadi kirimannya sendiri kelihatan. */
    public function test_kiriman_sendiri_tampil_di_daftar(): void
    {
        $this->kirim();

        $this->get(route('suggestions'))
            ->assertOk()
            ->assertSee('Kiriman saya')
            ->assertSee('@'.self::AKUN)
            ->assertSee('menunggu admin');
    }

    /**
     * Hapus kiriman sendiri: raw dibuang, `excluded_posts` TIDAK ditulis — itu yang
     * membedakannya dari blokir, dan itu yang bikin kiriman yang salah tanggal boleh
     * dikirim ulang.
     */
    public function test_pengusul_bisa_menghapus_kiriman_sendiri(): void
    {
        $this->actingAsPengusul();
        $this->kirim();

        $this->delete(route('posts.destroy', self::MEDIA))->assertRedirect();

        $this->assertDirectoryDoesNotExist(storage_path('raw/'.self::AKUN.'/'.self::MEDIA));
        $this->assertDatabaseMissing('excluded_posts', ['media_id' => self::MEDIA]);
    }

    /**
     * Kiriman orang lain tidak boleh dihapus peran `user` — route-nya cuma `auth`,
     * jadi yang menahannya cek pemilik di controller. Tanpa itu siapa pun yang bisa
     * login memegang tombol hapus atas kiriman semua orang.
     */
    public function test_pengusul_tidak_bisa_menghapus_kiriman_orang_lain(): void
    {
        $this->kirim();

        $this->actingAsPengusul()
            ->delete(route('posts.destroy', self::MEDIA))
            ->assertForbidden();

        $this->assertDirectoryExists(storage_path('raw/'.self::AKUN.'/'.self::MEDIA));
    }

    /**
     * Kiriman ulang DITOLAK, siapa pun yang mengirim. Menimpa berarti membuang raw +
     * hasil ekstraksi + baris paket se-media_id — tombol hapus paket yang sudah
     * di-review, cuma lewat pintu lain. Betulkan dari /posts, bukan dari sini.
     */
    public function test_kiriman_ulang_ditolak_bukan_menimpa(): void
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
            ->assertSessionHasErrors('permalink');

        // Semua jejaknya utuh: tidak ada yang dihapus lewat pintu ini.
        $this->assertDatabaseHas('excluded_posts', ['media_id' => self::MEDIA]);
        $this->assertSame(1, Package::where('media_id', self::MEDIA)->count());
        $this->assertFileExists(storage_path('extracted/'.self::MEDIA.'-0.json'));
    }

    public function test_permalink_bukan_post_ditolak(): void
    {
        $this->kirim(['permalink' => 'https://www.instagram.com/'.self::AKUN])
            ->assertSessionHasErrors('permalink');

        $this->assertDirectoryDoesNotExist(storage_path('raw/'.self::AKUN));
        Queue::assertNothingPushed();
    }

    /**
     * Instagram melayani post yang sama di dua URL. Kodenya sama, jadi media_id-nya
     * wajib sama — kalau tidak, kiriman lewat URL berhandel lahir sebagai post kedua
     * yang menggandakan paketnya alih-alih menimpa.
     */
    public function test_permalink_berhandel_media_id_sama(): void
    {
        $this->kirim(['permalink' => 'https://www.instagram.com/'.self::AKUN.'/p/DV-tyQIkuw5/'])
            ->assertSessionHasNoErrors();

        $this->assertFileExists(storage_path('raw/'.self::AKUN.'/'.self::MEDIA.'/post.json'));
    }

    /** Zip dirakit saat diunduh — kalau folder `extension/` pindah, ini yang jatuh. */
    public function test_extension_bisa_diunduh(): void
    {
        // Halaman pasangnya benar-benar dirender: view yang tidak pernah di-compile
        // tidak menangkap komponen void yang lupa ditutup `/>`.
        $this->get(route('extension'))->assertOk()->assertSee('Load unpacked');

        $this->get(route('extension.download'))
            ->assertOk()
            ->assertDownload('umroh-sakti-extension.zip');
    }

    public function test_tanggal_posting_wajib(): void
    {
        $this->kirim(['posted_at' => ''])->assertSessionHasErrors('posted_at');

        Queue::assertNothingPushed();
    }

    public function test_tamu_tidak_bisa_menambah_post(): void
    {
        auth()->logout();

        $this->get(route('suggestions'))->assertRedirect(route('login'));
        $this->kirim()->assertRedirect(route('login'));

        Queue::assertNothingPushed();
    }
}
