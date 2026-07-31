<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\SourceAccount;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AccountBlocklistTest extends TestCase
{
    use RefreshDatabase;

    /** Username sengaja tidak mungkin bertabrakan dengan akun asli di storage/. */
    private const AKUN = 'uji_blokir_acc';

    private const MEDIA = 'ujiblokir1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsOperator();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('raw/'.self::AKUN));
        File::delete(glob(storage_path('extracted/'.self::MEDIA.'*.json')) ?: []);

        parent::tearDown();
    }

    /** Akun + satu paket + jejak disknya (raw, hasil ekstraksi, post yang dikecualikan). */
    private function akunBerdata(): SourceAccount
    {
        $account = SourceAccount::create(['username' => self::AKUN, 'status' => 'approved']);

        Package::create([
            'media_id' => self::MEDIA,
            'flyer_index' => 1,
            'source_account' => self::AKUN,
            'departure_date' => '2026-09-14',
            'price_quad' => 25900000,
            'status' => 'review',
        ]);

        File::ensureDirectoryExists(storage_path('raw/'.self::AKUN.'/'.self::MEDIA));
        File::put(storage_path('raw/'.self::AKUN.'/'.self::MEDIA.'/post.json'), '{}');
        File::put(storage_path('extracted/'.self::MEDIA.'-1.json'), '{}');

        DB::table('excluded_posts')->insert([
            'media_id' => self::MEDIA.'x', 'source_account' => self::AKUN, 'reason' => 'manual',
        ]);

        return $account;
    }

    public function test_blokir_membuang_paket_dan_jejaknya_tapi_barisnya_tinggal(): void
    {
        $account = $this->akunBerdata();

        $this->post(route('accounts.block', $account))->assertRedirect();

        $this->assertSame('blocked', $account->fresh()->status);
        $this->assertSame(0, Package::where('source_account', self::AKUN)->count());
        $this->assertDirectoryDoesNotExist(storage_path('raw/'.self::AKUN));
        $this->assertFileDoesNotExist(storage_path('extracted/'.self::MEDIA.'-1.json'),
            'hasil ekstraksi yang ditinggal akan dihidupkan lagi oleh packages:import');
    }

    public function test_username_di_blocklist_ditolak_saat_dimasukkan_lagi(): void
    {
        $account = $this->akunBerdata();
        $this->post(route('accounts.block', $account));

        $this->post(route('accounts.store'), ['usernames' => self::AKUN])->assertRedirect();

        $this->assertSame('blocked', SourceAccount::where('username', self::AKUN)->sole()->status);
        $this->assertSame(1, SourceAccount::where('username', self::AKUN)->count());

        // Barisnya keluar dari daftar kerja dan pindah ke panel blocklist.
        $this->get(route('accounts'))->assertOk()->assertSee('Blocklist (1)')->assertSee('lepas blokir');
    }

    public function test_hapus_membuang_barisnya_sekalian_datanya(): void
    {
        $account = $this->akunBerdata();

        $this->delete(route('accounts.destroy', $account))->assertRedirect();

        $this->assertSame(0, SourceAccount::where('username', self::AKUN)->count());
        $this->assertSame(0, Package::where('source_account', self::AKUN)->count());
        $this->assertDirectoryDoesNotExist(storage_path('raw/'.self::AKUN));
        // Beda dengan blokir: catatan post yang dikecualikan ikut hilang, jadi
        // username yang sama boleh dimasukkan lagi dan di-scrap dari nol.
        $this->assertSame(0, DB::table('excluded_posts')->where('source_account', self::AKUN)->count());
    }

    public function test_import_menolak_hasil_ekstraksi_akun_yang_diblokir(): void
    {
        SourceAccount::create(['username' => self::AKUN, 'status' => 'blocked']);

        // Job `ai` yang masih jalan saat akunnya diblokir menulis filenya sesudah purge.
        $dir = storage_path('framework/testing/extracted');
        File::ensureDirectoryExists($dir);
        array_map('unlink', glob("$dir/*.json") ?: []);
        File::put("$dir/0.json", json_encode([
            '_media_id' => self::MEDIA, '_source' => self::AKUN,
            'post_kind' => 'package_offer', 'departure_date' => '2026-09-14', 'duration_days' => 9,
            'price_tiers' => [['occupancy' => 'quad', 'amount' => 25900000, 'currency' => 'IDR']],
        ]));

        $this->artisan('packages:import', ['--dir' => $dir])->assertSuccessful();

        $this->assertSame(0, Package::count());
        $this->assertFileDoesNotExist("$dir/0.json");
    }
}
