<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gerbang operator. Dulu kuncinya `app()->isLocal()` — cukup selama portalnya cuma
 * jalan di laptop, tapi begitu di-deploy alat kerjanya ikut mati buat pemiliknya.
 * Sekarang kuncinya login, dan yang dijaga test ini justru sisi tamunya: /akun,
 * panel pipeline, dan tombol aksi per kartu tidak boleh bocor.
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_tamu_dilempar_ke_halaman_login(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Sandi');
        $this->get('/accounts')->assertRedirect(route('login'));
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

    public function test_sandi_salah_tidak_membuka_apa_pun(): void
    {
        User::create(['email' => 'operator@umroh.test', 'password' => 'sandi-benar']);

        $this->post(route('login'), ['email' => 'operator@umroh.test', 'password' => 'salah'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post(route('login'), ['email' => 'operator@umroh.test', 'password' => 'sandi-benar'])
            ->assertRedirect(route('accounts'));
        $this->assertAuthenticated();

        $this->post(route('logout'))->assertRedirect(route('search'));
        $this->assertGuest();
    }

    /** `user:create` itu satu-satunya jalan bikin akun — tidak ada halaman daftar. */
    public function test_perintah_user_create_bikin_akun_yang_bisa_login(): void
    {
        $this->artisan('user:create', ['email' => 'baru@umroh.test', '--password' => 'rahasia-panjang'])
            ->assertSuccessful();

        $this->post(route('login'), ['email' => 'baru@umroh.test', 'password' => 'rahasia-panjang'])
            ->assertRedirect(route('accounts'));
        $this->assertAuthenticated();

        // Email yang sama = ganti sandi, bukan baris kedua.
        $this->artisan('user:create', ['email' => 'baru@umroh.test', '--password' => 'sandi-baru-lagi'])
            ->assertSuccessful();
        $this->assertSame(1, User::count());

        $this->post(route('logout'));
        $this->post(route('login'), ['email' => 'baru@umroh.test', 'password' => 'sandi-baru-lagi'])
            ->assertRedirect(route('accounts'));
        $this->assertAuthenticated();
    }

    /** Sandi pendek ditolak di CLI — kalau tidak, gerbangnya cuma pura-pura. */
    public function test_sandi_pendek_ditolak(): void
    {
        $this->artisan('user:create', ['email' => 'pendek@umroh.test', '--password' => 'abc'])
            ->assertFailed();

        $this->assertSame(0, User::count());
    }
}
