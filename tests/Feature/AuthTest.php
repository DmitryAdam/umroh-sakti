<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Gerbang operator. Dulu kuncinya `app()->isLocal()` — cukup selama portalnya cuma
 * jalan di laptop, tapi begitu di-deploy alat kerjanya ikut mati buat pemiliknya.
 * Sekarang kuncinya login lewat Google, dan yang dijaga test ini tiga kalimat:
 * sisi tamu tidak bocor, callback tidak bisa dipalsukan, dan yang masuk lewat
 * Google tidak pernah lahir sebagai admin.
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const STATE = 'state-uji-yang-panjang';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
        config([
            'services.google.client_id' => 'client-uji.apps.googleusercontent.com',
            'services.google.client_secret' => 'rahasia-uji',
        ]);
    }

    private function package(array $attrs = []): Package
    {
        return Package::create(array_merge([
            'status' => 'published',
            'departure_date' => '2026-09-14',
            'date_certainty' => 'exact',
            'duration_days' => 9,
            'departure_city' => 'Jakarta',
            'extracted_at' => now(),
            'price_quad' => 25_900_000,
        ], $attrs));
    }

    /** Balasan Google palsu: token dulu, lalu userinfo. */
    private function fakeGoogle(array $profil = []): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'token-uji']),
            'www.googleapis.com/*' => Http::response($profil + [
                'sub' => '110000000000000000001',
                'email' => 'baru@gmail.com',
                'email_verified' => true,
                'name' => 'Orang Baru',
            ]),
        ]);
    }

    /** Kembali dari Google. `state` yang dikirim boleh beda dari yang di sesi. */
    private function pulang(string $state = self::STATE): TestResponse
    {
        return $this->withSession(['google_state' => self::STATE])
            ->get(route('login.callback', ['code' => 'kode-uji', 'state' => $state]));
    }

    public function test_tamu_dilempar_ke_halaman_login(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Masuk dengan Google');
        $this->get('/accounts')->assertRedirect(route('login'));
        $this->get('/users')->assertRedirect(route('login'));
        $this->get('/avatar/siapa_saja.jpg')->assertRedirect(route('login'));

        // Yang minta JSON dapat 401, bukan redirect: tombol aksi per kartu memakai
        // fetch(), dan halaman login yang masuk lewat innerHTML cuma bikin bingung.
        $this->getJson('/pipeline/status')->assertUnauthorized();
    }

    public function test_pencarian_tetap_terbuka_tanpa_login(): void
    {
        $this->package(['departure_city' => 'Surabaya']);
        $this->package(['departure_city' => 'Medan', 'status' => 'review']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Surabaya')
            ->assertDontSee('Medan')
            // Tombol aksi (baca ulang / segarkan / buang) cuma dirender saat login.
            ->assertDontSee('data-post', escape: false)
            ->assertDontSee('data-delete', escape: false)
            ->assertSee('masuk');
    }

    public function test_operator_dapat_pratinjau_dan_tombol_aksi(): void
    {
        $this->package(['departure_city' => 'Medan', 'status' => 'review']);
        $this->actingAsOperator();

        $this->get('/')
            ->assertOk()
            ->assertSee('Medan')
            ->assertSee('data-post', escape: false)
            ->assertSee('data-delete', escape: false);

        // `?all=0` = lihat persis seperti pengunjung, walau sedang login.
        $this->get('/?all=0')->assertOk()->assertDontSee('Medan');
    }

    public function test_tombol_masuk_berangkat_ke_google_dengan_state(): void
    {
        $res = $this->post(route('login'));

        $res->assertRedirectContains('accounts.google.com/o/oauth2/v2/auth');
        $res->assertRedirectContains('client-uji.apps.googleusercontent.com');
        $res->assertSessionHas('google_state');
    }

    public function test_login_pertama_bikin_akun_sebagai_pengusul_bukan_admin(): void
    {
        $this->fakeGoogle();

        $this->pulang()->assertRedirect(route('suggestions'));
        $this->assertAuthenticated();

        $user = User::firstWhere('email', 'baru@gmail.com');
        // Yang mendaftar sendiri tidak pernah dapat alat kerja — naik pangkat
        // cuma lewat SQLite.
        $this->assertSame('user', $user->role);
        $this->assertSame('110000000000000000001', $user->google_id);
        $this->get('/accounts')->assertForbidden();
    }

    /** Login berikutnya jangan menulis ulang perannya — kalau tidak, admin turun sendiri. */
    public function test_login_ulang_tidak_menurunkan_admin(): void
    {
        User::create(['email' => 'operator@umroh.test', 'role' => 'admin']);
        $this->fakeGoogle(['email' => 'operator@umroh.test']);

        $this->pulang()->assertRedirect(route('accounts'));

        $this->assertSame('admin', User::firstWhere('email', 'operator@umroh.test')->role);
        // Ditaut ke baris yang sudah ada, bukan lahir kembar.
        $this->assertSame(1, User::where('email', 'operator@umroh.test')->count());
    }

    public function test_state_yang_tidak_cocok_ditolak(): void
    {
        $this->fakeGoogle();

        $this->pulang(state: 'state-punya-penyerang')->assertRedirect(route('login'));
        $this->assertGuest();
        // Kodenya tidak pernah ditukar: tanpa cek ini, penolakannya cuma kosmetik.
        Http::assertNothingSent();
    }

    public function test_email_google_yang_belum_terverifikasi_ditolak(): void
    {
        $this->fakeGoogle(['email_verified' => false]);

        $this->pulang()->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNull(User::firstWhere('email', 'baru@gmail.com'));
    }

    public function test_akun_ditangguhkan_tidak_bisa_masuk(): void
    {
        User::create(['email' => 'baru@gmail.com', 'role' => 'user', 'suspended_at' => now()]);
        $this->fakeGoogle();

        $this->pulang()->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /** Sesi yang sudah jalan pun diputus — `remember` bikin cookienya hidup berbulan-bulan. */
    public function test_penangguhan_memutus_sesi_yang_sedang_jalan(): void
    {
        $user = User::create(['email' => 'pengusul@umroh.test', 'role' => 'user']);
        $this->actingAs($user);
        $this->get(route('suggestions'))->assertOk();

        $user->update(['suspended_at' => now()]);

        $this->get(route('suggestions'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_keluar_mengakhiri_sesi(): void
    {
        $this->actingAsOperator();

        $this->post(route('logout'))->assertRedirect(route('search'));
        $this->assertGuest();
    }

    /** Admin tunggal disemai migrasi: tanpa itu tidak ada yang bisa membuka alat kerja. */
    public function test_admin_tunggal_sudah_ada_sejak_migrasi(): void
    {
        $admin = User::firstWhere('email', 'dimitry.adam@gmail.com');

        $this->assertNotNull($admin);
        $this->assertSame('admin', $admin->role);
        $this->assertSame(1, User::where('role', 'admin')->count());
    }
}
