<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Halaman pengguna. Yang dijaga di sini cuma dua kalimat: satu-satunya aksinya
 * penangguhan (tidak ada jalur menaikkan peran lewat HTTP), dan admin tidak bisa
 * mengunci dirinya sendiri di luar.
 */
class UserPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_halaman_pengguna_cuma_untuk_admin(): void
    {
        $this->actingAsPengusul()->get(route('users'))->assertForbidden();

        $this->actingAsOperator()->get(route('users'))
            ->assertOk()
            ->assertSee('operator@umroh.test')
            // Peran ditulis apa adanya, tapi tidak ada kontrol untuk mengubahnya.
            ->assertSee('pengusul')
            ->assertDontSee('name="role"', false);
    }

    public function test_tangguhkan_dan_aktifkan(): void
    {
        $this->actingAsOperator();
        $korban = User::create(['email' => 'nakal@gmail.com', 'role' => 'user']);

        $this->patch(route('users.update', $korban), ['suspended' => 1])->assertRedirect();
        $this->assertNotNull($korban->refresh()->suspended_at);

        $this->patch(route('users.update', $korban), ['suspended' => 0])->assertRedirect();
        $this->assertNull($korban->refresh()->suspended_at);
    }

    /** Admin tunggal yang menangguhkan dirinya = portalnya cuma bisa dibuka lewat SQLite. */
    public function test_tidak_bisa_menangguhkan_diri_sendiri(): void
    {
        $this->actingAsOperator();
        $saya = User::firstWhere('email', 'operator@umroh.test');

        $this->patch(route('users.update', $saya), ['suspended' => 1])
            ->assertRedirect()
            ->assertSessionHas('status', 'Tidak bisa menangguhkan akun sendiri.');

        $this->assertNull($saya->refresh()->suspended_at);
    }

    /** Peran `user` tidak boleh menangguhkan siapa pun lewat URL langsung. */
    public function test_pengusul_tidak_bisa_menangguhkan(): void
    {
        $korban = User::create(['email' => 'korban@gmail.com', 'role' => 'user']);
        $this->actingAsPengusul();

        $this->patch(route('users.update', $korban), ['suspended' => 1])->assertForbidden();
        $this->assertNull($korban->refresh()->suspended_at);
    }
}
