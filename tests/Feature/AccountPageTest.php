<?php

namespace Tests\Feature;

use App\Jobs\FetchAccount;
use App\Models\SourceAccount;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AccountPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Halaman akun dikunci ke env local (abort_unless), sama seperti panel pipeline.
        $this->app->detectEnvironment(fn () => 'local');
        // Env bukan 'testing' lagi, jadi CSRF-nya tidak dilewati sendiri.
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

    public function test_daftar_menampilkan_alasan_gagal_dan_menaikkannya(): void
    {
        SourceAccount::create(['username' => 'sehat', 'status' => 'approved', 'last_fetched_at' => now()]);
        SourceAccount::create([
            'username' => 'rusak', 'status' => 'approved',
            'last_fetched_at' => now()->subDay(), 'last_error' => 'ERROR: Graph API HTTP 400: token mati',
        ]);

        $this->get(route('accounts'))
            ->assertOk()
            ->assertSee('1 gagal di percobaan terakhir')
            // Yang gagal naik ke atas walaupun timestamp berhasilnya lebih tua.
            ->assertSeeInOrder(['@rusak', 'token mati', '@sehat']);
    }

    public function test_tombol_scrap_mengantrikan_fetch(): void
    {
        Queue::fake();
        $account = SourceAccount::create(['username' => 'hamdantour', 'status' => 'approved']);

        $this->post(route('accounts.fetch', $account))->assertRedirect();

        Queue::assertPushed(FetchAccount::class);
    }

    public function test_scrap_semua_hanya_mengantrikan_akun_approved(): void
    {
        Queue::fake();
        SourceAccount::create(['username' => 'ikut', 'status' => 'approved']);
        SourceAccount::create(['username' => 'ditolak', 'status' => 'rejected']);

        $this->post(route('accounts.fetch_all'))->assertRedirect();

        Queue::assertPushed(FetchAccount::class, 1);
        Queue::assertPushed(fn (FetchAccount $job) => $job->account->username === 'ikut');
    }
}
