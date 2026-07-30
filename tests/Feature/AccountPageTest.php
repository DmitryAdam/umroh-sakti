<?php

namespace Tests\Feature;

use App\Jobs\FetchAccount;
use App\Models\SourceAccount;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
