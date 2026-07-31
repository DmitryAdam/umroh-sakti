<?php

namespace Tests\Feature;

use App\Jobs\ExtractPost;
use App\Jobs\FetchAccount;
use App\Models\Package;
use App\Models\SourceAccount;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AccountPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Halaman akun ada di grup `auth`, sama seperti panel pipeline.
        $this->actingAsOperator();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_menerima_username_url_dan_handle_sekaligus(): void
    {
        $this->post(route('accounts.store'), ['usernames' => implode("\n", [
            'hamdantour',
            'https://www.instagram.com/sunnatravel.id/?hl=id',
            '@nakhlatour',
            '# komentar',
            'bukan username!',
        ])])->assertRedirect();

        $this->assertEqualsCanonicalizing(
            ['hamdantour', 'sunnatravel.id', 'nakhlatour'],
            SourceAccount::pluck('username')->all(),
        );
        $this->assertSame(['approved'], SourceAccount::pluck('status')->unique()->all());
    }

    public function test_akun_yang_sudah_ada_tidak_didaftarkan_dua_kali(): void
    {
        SourceAccount::create(['username' => 'hamdantour', 'status' => 'rejected']);

        $this->post(route('accounts.store'), ['usernames' => 'hamdantour']);

        $this->assertSame(1, SourceAccount::count());
        // Status manual menang: baris yang sudah ada tidak disentuh.
        $this->assertSame('rejected', SourceAccount::first()->status);
    }

    public function test_daftar_menandai_akun_yang_belum_pernah_di_scrap(): void
    {
        SourceAccount::create(['username' => 'belum', 'status' => 'approved']);
        SourceAccount::create(['username' => 'sudah', 'status' => 'approved', 'last_fetched_at' => now()->subHours(3)]);

        $this->get(route('accounts'))
            ->assertOk()
            // Yang belum pernah di-scrap naik ke atas — itu yang perlu dikerjakan.
            ->assertSeeInOrder(['@belum', 'belum pernah', '@sudah', '3 hours ago']);
    }

    public function test_kolom_bisa_diurut_dan_nilai_asing_dilewat(): void
    {
        SourceAccount::create(['username' => 'kecil', 'status' => 'approved', 'followers_count' => 10]);
        SourceAccount::create(['username' => 'besar', 'status' => 'approved', 'followers_count' => 900]);

        $this->get(route('accounts', ['sort' => 'followers']))
            ->assertSeeInOrder(['@besar', '@kecil']);
        $this->get(route('accounts', ['sort' => 'followers', 'dir' => 'asc']))
            ->assertSeeInOrder(['@kecil', '@besar']);

        // Nilai asing balik ke urutan default (belum pernah di-scrap dulu), bukan galat.
        $this->get(route('accounts', ['sort' => 'drop table']))->assertOk();
    }

    public function test_fetch_gagal_tidak_dianggap_berhasil(): void
    {
        // `fail()` tidak menghentikan handle(): tanpa `return` sesudahnya, akun yang
        // gagal ikut distempel last_fetched_at dan kelihatan baru saja di-scrap.
        Process::fake(['*' => Process::result(errorOutput: 'ERROR: Graph API HTTP 400: token mati', exitCode: 1)]);
        $account = SourceAccount::create(['username' => 'tokenmati', 'status' => 'approved']);

        (new FetchAccount($account, 2))->handle();

        $account->refresh();
        $this->assertNull($account->last_fetched_at, 'fetch gagal jangan distempel berhasil');
    }

    public function test_alasan_gagal_dicatat_untuk_exception_apa_pun(): void
    {
        // Hook failed() dipanggil framework untuk semua kegagalan, termasuk yang tidak
        // lewat cabang if di handle() — `database is locked`, timeout, exception liar.
        $account = SourceAccount::create(['username' => 'rusak', 'status' => 'approved']);

        (new FetchAccount($account, 2))->failed(new \RuntimeException('SQLSTATE[HY000]: database is locked'));

        $this->assertStringContainsString('database is locked', (string) $account->refresh()->last_error);
    }

    public function test_fetch_berhasil_menghapus_error_lama(): void
    {
        Process::fake(['*' => Process::result(output: '@lolos: 2 post baru tersimpan')]);
        $account = SourceAccount::create([
            'username' => 'lolos', 'status' => 'approved', 'last_error' => 'ERROR: token mati',
        ]);

        (new FetchAccount($account, 2))->handle();

        $account->refresh();
        $this->assertNotNull($account->last_fetched_at);
        $this->assertNull($account->last_error, 'error lama jangan nempel setelah berhasil');
    }

    public function test_profil_akun_dipanen_dari_hasil_fetch(): void
    {
        // probe.php yang menulis storage/profiles/{username}.json (Graph API cuma
        // disentuh dari sana); job-nya cuma memindahkannya ke kolom.
        Process::fake(['*' => Process::result(output: '@profilan: 0 post baru tersimpan')]);
        $path = storage_path('profiles/profilan.json');
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, json_encode([
            'full_name' => 'Travel Contoh', 'followers_count' => 246271,
            'follows_count' => 19, 'media_count' => 1445,
        ]));
        $account = SourceAccount::create(['username' => 'profilan', 'status' => 'approved']);

        try {
            (new FetchAccount($account, 2))->handle();

            $this->assertSame(246271, $account->refresh()->followers_count);
            $this->get(route('accounts'))->assertOk()->assertSee('246.271');
        } finally {
            @unlink($path);
        }
    }

    public function test_daftar_menampilkan_alasan_gagal_dan_menaikkannya(): void
    {
        SourceAccount::create(['username' => 'sehat', 'status' => 'approved', 'last_fetched_at' => now()]);
        SourceAccount::create([
            'username' => 'rusak', 'status' => 'approved',
            'last_error' => 'ERROR: Graph API HTTP 400: token mati',
        ]);

        $this->get(route('accounts'))
            ->assertOk()
            ->assertSee('1 gagal', false)
            // Yang gagal naik ke atas walaupun timestamp berhasilnya lebih tua.
            ->assertSeeInOrder(['@rusak', 'token mati', '@sehat']);
    }

    public function test_error_di_akun_yang_pernah_berhasil_tidak_dihitung_gagal(): void
    {
        // Rate limit / SQLite lock / timeout menempelkan last_error ke akun yang
        // datanya sudah ada. Menghitungnya gagal bikin angka di kepala halaman
        // (25 dari 189) menyuruh menindak yang tidak perlu ditindak.
        SourceAccount::create([
            'username' => 'pernahberhasil', 'status' => 'approved',
            'last_fetched_at' => now()->subDay(), 'last_error' => 'ERROR: rate limit',
        ]);

        $this->get(route('accounts'))->assertOk()->assertDontSee('gagal &amp; kosong', false);
    }

    public function test_tombol_scrap_mengantrikan_fetch(): void
    {
        Queue::fake();
        $account = SourceAccount::create(['username' => 'hamdantour', 'status' => 'approved']);

        $this->post(route('accounts.fetch', $account))->assertRedirect();

        Queue::assertPushed(FetchAccount::class);
    }

    public function test_scrap_paksa_melepas_post_yang_dikecualikan(): void
    {
        // Baris excluded_posts itu satu-satunya yang menahan fetch mengunduh ulang.
        // Yang dilepas cuma milik akun ini — akun lain tidak ikut kena.
        Queue::fake();
        $account = SourceAccount::create(['username' => 'binfahad_travel', 'status' => 'approved']);
        DB::table('excluded_posts')->insert([
            ['media_id' => 'x1', 'source_account' => 'binfahad_travel', 'reason' => 'bukan_paket'],
            ['media_id' => 'x2', 'source_account' => 'akunlain', 'reason' => 'haji'],
        ]);

        $this->post(route('accounts.fetch', $account), ['force' => 1])->assertRedirect();

        $this->assertDatabaseMissing('excluded_posts', ['media_id' => 'x1']);
        $this->assertDatabaseHas('excluded_posts', ['media_id' => 'x2']);
        Queue::assertPushed(FetchAccount::class);
    }

    public function test_scrap_biasa_tidak_melepas_post_yang_dikecualikan(): void
    {
        Queue::fake();
        $account = SourceAccount::create(['username' => 'binfahad_travel', 'status' => 'approved']);
        DB::table('excluded_posts')->insert(
            ['media_id' => 'x1', 'source_account' => 'binfahad_travel', 'reason' => 'bukan_paket'],
        );

        $this->post(route('accounts.fetch', $account))->assertRedirect();

        $this->assertDatabaseHas('excluded_posts', ['media_id' => 'x1']);
    }

    public function test_scrap_paksa_massal_melepas_hanya_akun_terpilih(): void
    {
        Queue::fake();
        $ikut = SourceAccount::create(['username' => 'ikut', 'status' => 'approved']);
        SourceAccount::create(['username' => 'luar', 'status' => 'approved']);
        DB::table('excluded_posts')->insert([
            ['media_id' => 'x1', 'source_account' => 'ikut', 'reason' => 'bukan_paket'],
            ['media_id' => 'x2', 'source_account' => 'luar', 'reason' => 'bukan_paket'],
        ]);

        $this->post(route('accounts.bulk'), ['action' => 'force', 'ids' => [$ikut->id]])->assertRedirect();

        $this->assertDatabaseMissing('excluded_posts', ['media_id' => 'x1']);
        $this->assertDatabaseHas('excluded_posts', ['media_id' => 'x2']);
        Queue::assertPushed(FetchAccount::class, 1);
    }

    public function test_scrap_massal_hanya_mengantrikan_akun_approved(): void
    {
        Queue::fake();
        SourceAccount::create(['username' => 'ikut', 'status' => 'approved']);
        SourceAccount::create(['username' => 'ditolak', 'status' => 'rejected']);

        $this->post(route('accounts.crawl'), ['new' => 1])->assertRedirect();

        Queue::assertPushed(FetchAccount::class, 1);
        Queue::assertPushed(fn (FetchAccount $job) => $job->account->username === 'ikut');
    }

    public function test_tidak_ada_scrap_semua(): void
    {
        // Tanpa filter, 189 akun masuk antrian ig yang cuma satu worker: kuota Graph
        // habis di tengah jalan. Request tanpa filter ditolak, bukan diartikan "semua".
        Queue::fake();
        SourceAccount::create(['username' => 'ikut', 'status' => 'approved']);

        $this->post(route('accounts.crawl'))->assertRedirect();
        $this->get(route('accounts'))->assertOk()->assertDontSee('Scrap semua');

        Queue::assertNothingPushed();
    }

    public function test_scrap_yang_belum_pernah_melewati_akun_yang_sudah_di_scrap(): void
    {
        Queue::fake();
        SourceAccount::create(['username' => 'belum', 'status' => 'approved']);
        SourceAccount::create([
            'username' => 'sudah', 'status' => 'approved', 'last_fetched_at' => now(),
        ]);

        $this->post(route('accounts.crawl'), ['new' => 1])->assertRedirect();

        Queue::assertPushed(FetchAccount::class, 1);
        Queue::assertPushed(fn (FetchAccount $job) => $job->account->username === 'belum');
    }

    public function test_scrap_yang_gagal_hanya_akun_error_yang_masih_kosong(): void
    {
        Queue::fake();
        SourceAccount::create(['username' => 'gagal', 'status' => 'approved', 'last_error' => 'ERROR: token mati']);
        SourceAccount::create(['username' => 'belum', 'status' => 'approved']);
        SourceAccount::create([
            'username' => 'pernahberhasil', 'status' => 'approved',
            'last_fetched_at' => now()->subDay(), 'last_error' => 'ERROR: rate limit',
        ]);

        $this->post(route('accounts.crawl'), ['failed' => 1])->assertRedirect();

        Queue::assertPushed(FetchAccount::class, 1);
        Queue::assertPushed(fn (FetchAccount $job) => $job->account->username === 'gagal');
    }

    public function test_bulk_scrap_melewati_akun_yang_diblokir(): void
    {
        Queue::fake();
        $ikut = SourceAccount::create(['username' => 'ikut', 'status' => 'approved']);
        $blok = SourceAccount::create(['username' => 'blok', 'status' => 'blocked']);

        $this->post(route('accounts.bulk'), ['action' => 'crawl', 'ids' => [$ikut->id, $blok->id]])
            ->assertRedirect();

        Queue::assertPushed(FetchAccount::class, 1);
        Queue::assertPushed(fn (FetchAccount $job) => $job->account->username === 'ikut');
    }

    public function test_bulk_blokir_dan_hapus(): void
    {
        $a = SourceAccount::create(['username' => 'satu', 'status' => 'approved']);
        $b = SourceAccount::create(['username' => 'dua', 'status' => 'approved']);

        $this->post(route('accounts.bulk'), ['action' => 'block', 'ids' => [$a->id, $b->id]])->assertRedirect();
        $this->assertSame(['blocked', 'blocked'], [$a->refresh()->status, $b->refresh()->status]);

        $this->post(route('accounts.bulk'), ['action' => 'delete', 'ids' => [$a->id, $b->id]])->assertRedirect();
        $this->assertSame(0, SourceAccount::count());
    }

    public function test_bulk_menolak_aksi_asing(): void
    {
        $a = SourceAccount::create(['username' => 'satu', 'status' => 'approved']);

        $this->post(route('accounts.bulk'), ['action' => 'ngawur', 'ids' => [$a->id]])
            ->assertSessionHasErrors('action');
        $this->assertSame('approved', $a->refresh()->status);
    }

    public function test_scrap_jam_melewati_akun_yang_baru_di_scrap(): void
    {
        Queue::fake();
        SourceAccount::create(['username' => 'baru', 'status' => 'approved', 'last_fetched_at' => now()->subHour()]);
        SourceAccount::create(['username' => 'lama', 'status' => 'approved', 'last_fetched_at' => now()->subDays(2)]);

        $this->post(route('accounts.crawl'), ['hours' => 6])->assertRedirect();

        Queue::assertPushed(FetchAccount::class, 1);
        Queue::assertPushed(fn (FetchAccount $job) => $job->account->username === 'lama');
    }

    public function test_daftar_post_menampilkan_gambar_dan_alasan_ditolak(): void
    {
        $account = SourceAccount::create(['username' => 'isinya', 'status' => 'approved']);
        $dir = storage_path('raw/isinya/111');
        @mkdir($dir, 0775, true);
        file_put_contents("$dir/post.json", json_encode([
            'caption' => 'Flyer kesehatan jamaah', 'permalink' => 'https://instagram.com/p/x',
            'timestamp' => '2026-07-18T02:18:27+0000',
        ]));
        file_put_contents("$dir/0.jpg", 'jpg');
        // Post yang rawnya sudah dihapus tetap harus muncul dari excluded_posts.
        DB::table('excluded_posts')->insert([
            ['media_id' => '111', 'source_account' => 'isinya', 'reason' => 'bukan_paket'],
            ['media_id' => '222', 'source_account' => 'isinya', 'reason' => 'haji'],
        ]);

        try {
            $this->get(route('accounts.posts', $account))->assertOk()
                ->assertSee('bukan_paket')->assertSee('haji')
                ->assertSee(route('posts.raw', ['111', 0]))
                ->assertSee('file raw sudah dihapus');

            $this->get(route('accounts.posts', [$account, 'filter' => 'rejected']))
                ->assertOk()->assertSee('haji');
            $this->get(route('accounts.posts', [$account, 'filter' => 'packages']))
                ->assertOk()->assertDontSee('bukan_paket');

            $this->get(route('posts.raw', ['111', 0]))->assertOk();
            $this->get(route('posts.raw', ['111', 9]))->assertNotFound();
        } finally {
            array_map('unlink', glob("$dir/*") ?: []);
            @rmdir($dir);
            @rmdir(dirname($dir));
        }
    }

    /**
     * `/posts` = halaman yang sama tanpa lingkup akun: post dari SEMUA akun, plus
     * kolom akunnya. Yang membedakannya cuma satu argumen di controller.
     */
    public function test_halaman_semua_post_menggabungkan_akun(): void
    {
        SourceAccount::create(['username' => 'akun_a', 'status' => 'approved']);
        SourceAccount::create(['username' => 'akun_b', 'status' => 'approved']);
        foreach (['akun_a' => '901', 'akun_b' => '902'] as $user => $media) {
            @mkdir(storage_path("raw/$user/$media"), 0775, true);
            // Timestamp jauh di depan: halaman semua post dipotong 60 baris per
            // halaman dan storage/raw asli ikut terbaca, jadi fixture-nya harus
            // berada di puncak urutan.
            file_put_contents(storage_path("raw/$user/$media/post.json"), json_encode([
                'caption' => "punya $user", 'timestamp' => '2030-01-01T00:00:00+0000',
            ]));
        }

        try {
            $this->get(route('posts'))->assertOk()
                ->assertSee('punya akun_a')->assertSee('punya akun_b')
                ->assertSee('@akun_a')->assertSee('@akun_b');

            // Yang berlingkup akun tetap menyaring.
            $this->get(route('accounts.posts', SourceAccount::firstWhere('username', 'akun_a')))
                ->assertOk()->assertSee('punya akun_a')->assertDontSee('punya akun_b');
        } finally {
            foreach (['akun_a/901', 'akun_b/902'] as $path) {
                array_map('unlink', glob(storage_path("raw/$path/*")) ?: []);
                @rmdir(storage_path("raw/$path"));
                @rmdir(dirname(storage_path("raw/$path")));
            }
        }
    }

    /**
     * Blokir = vonis manusia "bukan paket". Import sengaja menahan raw post
     * `bukan_paket` (vonis mesin, paling sering salah); begitu operator
     * mengonfirmasinya, filenya baru dibuang dan barisnya jadi `manual`.
     */
    public function test_bulk_blokir_membuang_file_dan_menandai_manual(): void
    {
        Queue::fake();
        $account = SourceAccount::create(['username' => 'blokiran', 'status' => 'approved']);
        $dir = storage_path('raw/blokiran/444');
        @mkdir($dir, 0775, true);
        file_put_contents("$dir/post.json", '{}');
        file_put_contents("$dir/0.jpg", 'jpg');
        file_put_contents(storage_path('extracted/444.json'), '{}');
        DB::table('excluded_posts')->insert([
            'media_id' => '444', 'source_account' => 'blokiran', 'reason' => 'bukan_paket',
        ]);

        $this->post(route('posts.bulk'), ['action' => 'block', 'media' => ['444']])
            ->assertRedirect();

        $this->assertDirectoryDoesNotExist($dir);
        $this->assertFileDoesNotExist(storage_path('extracted/444.json'));
        // Barisnya TETAP ada: itu yang menahan fetch & extract mengulanginya.
        $this->assertDatabaseHas('excluded_posts', ['media_id' => '444', 'reason' => 'manual']);
    }

    /**
     * Hapus blokir cuma membuang barisnya `excluded_posts`, tidak menyentuh file
     * dan tidak dilingkupi akun — `media_id` sudah unik, dan halaman semua post
     * memakai endpoint yang sama.
     */
    public function test_bulk_hapus_blokir_membuang_barisnya_saja(): void
    {
        SourceAccount::create(['username' => 'lepasan', 'status' => 'approved']);
        $dir = storage_path('raw/lepasan/777');
        @mkdir($dir, 0775, true);
        file_put_contents("$dir/post.json", '{}');
        DB::table('excluded_posts')->insert([
            ['media_id' => '777', 'source_account' => 'lepasan', 'reason' => 'bukan_paket'],
            ['media_id' => '888', 'source_account' => 'akunlain', 'reason' => 'manual'],
        ]);

        try {
            $this->post(route('posts.bulk'), ['action' => 'unblock', 'media' => ['777', '888']])
                ->assertRedirect();

            $this->assertDatabaseCount('excluded_posts', 0);
            $this->assertFileExists("$dir/post.json");
        } finally {
            array_map('unlink', glob("$dir/*") ?: []);
            @rmdir($dir);
            @rmdir(dirname($dir));
        }
    }

    public function test_bulk_baca_ulang_melepas_blok_dan_mengantrikan(): void
    {
        Queue::fake();
        $account = SourceAccount::create(['username' => 'bacaulang', 'status' => 'approved']);
        $dir = storage_path('raw/bacaulang/555');
        @mkdir($dir, 0775, true);
        file_put_contents("$dir/post.json", '{}');
        DB::table('excluded_posts')->insert([
            ['media_id' => '555', 'source_account' => 'bacaulang', 'reason' => 'bukan_paket'],
            // Rawnya sudah dibuang: tidak ada gambar untuk dibaca, jadi dilewat.
            ['media_id' => '666', 'source_account' => 'bacaulang', 'reason' => 'manual'],
        ]);

        try {
            $this->post(route('posts.bulk'), ['action' => 'extract', 'media' => ['555', '666']])
                ->assertRedirect();

            Queue::assertPushed(ExtractPost::class, 1);
            $this->assertDatabaseMissing('excluded_posts', ['media_id' => '555']);
            $this->assertDatabaseHas('excluded_posts', ['media_id' => '666']);
        } finally {
            array_map('unlink', glob("$dir/*") ?: []);
            @rmdir($dir);
            @rmdir(dirname($dir));
        }
    }

    /**
     * Raw kosong tapi ada paketnya = flyernya sudah dipindah promoteFlyer ke disk
     * `flyers`, bukan post yang ditolak. Kartunya harus memajang flyer itu.
     */
    public function test_post_yang_flyernya_sudah_dipromosikan_tetap_bergambar(): void
    {
        $account = SourceAccount::create(['username' => 'promoted', 'status' => 'approved']);
        $dir = storage_path('raw/promoted/333');
        @mkdir($dir, 0775, true);
        file_put_contents("$dir/post.json", json_encode(['caption' => 'Umroh September', 'timestamp' => '2026-06-22T00:00:00+0000']));
        Package::create(['source_account' => 'promoted', 'media_id' => '333', 'flyer_index' => 0, 'status' => 'review']);

        try {
            $this->get(route('accounts.posts', $account))->assertOk()
                ->assertSee(route('flyer', ['media' => '333', 'index' => 0]))
                ->assertDontSee('file raw sudah dihapus');
        } finally {
            array_map('unlink', glob("$dir/*") ?: []);
            @rmdir($dir);
            @rmdir(dirname($dir));
        }
    }
}
