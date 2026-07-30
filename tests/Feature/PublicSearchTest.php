<?php

namespace Tests\Feature;

use App\Models\Package;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PublicSearchTest extends TestCase
{
    use RefreshDatabase;

    private function package(array $attrs = [], int $price = 25900000): Package
    {
        $p = Package::create(array_merge([
            'status' => 'published',
            'departure_date' => '2026-03-14',
            'date_certainty' => 'exact',
            'duration_days' => 9,
            'departure_city' => 'Jakarta',
            'airline' => 'Saudia',
            'extracted_at' => now(),
            'price_quad' => $price,
        ], $attrs));

        return $p;
    }

    /** Catatan koreksi cuma boleh dari pratinjau lokal, dan tidak mengubah status publikasi. */
    public function test_catatan_koreksi_tersimpan_dan_dikunci_ke_lokal(): void
    {
        $package = $this->package(['status' => 'review']);

        $this->post("/paket/{$package->id}/feedback", ['review_verdict' => 'bukan_paket'])
            ->assertNotFound();

        // Env dipaksa local supaya guardnya kebuka; CSRF-nya jadi ikut aktif karena
        // Laravel cuma melewatinya saat env = testing.
        app()['env'] = 'local';
        config(['app.env' => 'local']);
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->post("/paket/{$package->id}/feedback", [
            'review_verdict' => 'bukan_paket',
            'review_note' => 'ini poster daftar hotel',
        ])->assertRedirect();

        // Jalur AJAX: balas JSON, jangan redirect — halaman tidak boleh reload.
        $this->postJson("/paket/{$package->id}/feedback", ['review_verdict' => 'oke'])
            ->assertOk()
            ->assertJson(['saved' => $package->id]);

        $package->refresh();
        $this->assertSame('oke', $package->review_verdict);
        $package->update(['review_verdict' => 'bukan_paket']);

        $package->refresh();
        $this->assertSame('bukan_paket', $package->review_verdict);
        $this->assertSame('ini poster daftar hotel', $package->review_note);
        $this->assertNotNull($package->reviewed_at);
        $this->assertSame('review', $package->status, 'feedback bukan jalur publish');
    }

    /** Tombol X: paketnya hilang dan post sumbernya pindah ke storage/trash, bukan lenyap. */
    public function test_tombol_buang_menghapus_paket_dan_memindahkan_sumbernya(): void
    {
        $package = $this->package([
            'status' => 'review',
            'media_id' => 'buang1',
            'source_account' => 'agen_x',
        ]);

        $raw = storage_path('raw/agen_x/buang1');
        File::ensureDirectoryExists($raw);
        file_put_contents("$raw/post.json", '{}');

        $this->deleteJson("/paket/{$package->id}")->assertNotFound();

        // Env dipaksa local supaya guardnya kebuka; CSRF ikut aktif karena Laravel
        // cuma melewatinya saat env = testing.
        app()['env'] = 'local';
        config(['app.env' => 'local']);
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->deleteJson("/paket/{$package->id}")->assertOk();

        $this->assertSame(0, Package::count());
        $this->assertDirectoryDoesNotExist($raw);
        $this->assertFileExists(storage_path('trash/agen_x/buang1/post.json'));

        File::deleteDirectory(storage_path('trash/agen_x'));
    }

    /** Panel pipeline itu alat kerja lokal — jangan pernah kebuka di produksi. */
    public function test_panel_pipeline_dikunci_ke_lokal(): void
    {
        $this->getJson('/pipeline/status')->assertNotFound();

        app()['env'] = 'local';
        config(['app.env' => 'local']);

        $this->getJson('/pipeline/status')
            ->assertOk()
            ->assertJsonStructure(['sekarang', 'akun', 'terfetch', 'antrian', 'raw', 'extracted', 'paket', 'jalan']);
    }

    /**
     * Urutan harga dibaca dari tier terisi yang termurah — paket tanpa harga sama
     * sekali harus jatuh ke bawah di kedua arah, bukan ikut jadi "termurah".
     */
    public function test_urut_harga_pakai_tier_termurah_dan_paket_tanpa_harga_di_bawah(): void
    {
        $this->package(['departure_city' => 'Mahal', 'departure_date' => '2026-04-01'], 40_000_000);
        // Quad kosong, triple terisi: yang dipakai 22 jt, bukan single yang 50 jt.
        $this->package([
            'departure_city' => 'Murah',
            'departure_date' => '2026-05-01',
            'price_quad' => null,
            'price_triple' => 22_000_000,
            'price_single' => 50_000_000,
        ]);
        $this->package(['departure_city' => 'Tanpaharga', 'departure_date' => '2026-03-01', 'price_quad' => null]);

        $urutan = fn (string $sort) => array_map(
            fn ($p) => $p->departure_city,
            $this->get("/?sort=$sort")->assertOk()->viewData('packages')->all(),
        );

        $this->assertSame(['Murah', 'Mahal', 'Tanpaharga'], $urutan('price'));
        $this->assertSame(['Mahal', 'Murah', 'Tanpaharga'], $urutan('price_desc'));
        $this->assertSame(['Tanpaharga', 'Mahal', 'Murah'], $urutan('date'));
        $this->assertSame(['Murah', 'Mahal', 'Tanpaharga'], $urutan('date_desc'));
        // Sort ngawur jangan bikin 500, balik ke default.
        $this->assertSame($urutan('date'), $urutan('; drop table packages'));
    }

    /** Rentang keberangkatan: batasnya inklusif, dan boleh diisi salah satu saja. */
    public function test_filter_rentang_tanggal_keberangkatan(): void
    {
        $this->package(['departure_city' => 'Awal', 'departure_date' => '2026-03-01']);
        $this->package(['departure_city' => 'Tengah', 'departure_date' => '2026-05-10']);
        $this->package(['departure_city' => 'Akhir', 'departure_date' => '2026-08-20']);

        $kota = fn (string $q) => array_map(
            fn ($p) => $p->departure_city,
            $this->get("/?$q")->assertOk()->viewData('packages')->all(),
        );

        $this->assertSame(['Tengah'], $kota('from=2026-05-10&to=2026-05-10'), 'batasnya inklusif');
        $this->assertSame(['Tengah', 'Akhir'], $kota('from=2026-04-01'));
        $this->assertSame(['Awal', 'Tengah'], $kota('to=2026-05-10'));
        $this->assertSame(['Awal', 'Tengah', 'Akhir'], $kota('from=&to='), 'kolom kosong = tanpa batas');
    }

    public function test_hanya_paket_published_yang_tampil(): void
    {
        $this->package(['departure_city' => 'Surabaya']);
        $this->package(['departure_city' => 'Medan', 'status' => 'review']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Surabaya')
            ->assertDontSee('Medan');
    }

    public function test_paket_belum_direview_tidak_bisa_diakses_langsung(): void
    {
        $draft = $this->package(['status' => 'review']);

        $this->get(route('package.show', $draft))->assertNotFound();
    }

    /**
     * Nama kota juga muncul di dropdown facet, jadi assertSee pada nama kota
     * tidak membuktikan apa-apa. Yang dicek adalah link ke detail paket.
     */
    public function test_filter_kota_dan_harga_bekerja(): void
    {
        $murah = $this->package(['departure_city' => 'Jakarta'], 25000000);
        $mahal = $this->package(['departure_city' => 'Surabaya'], 40000000);

        $this->get('/?city=Jakarta')->assertOk()
            ->assertSee(route('package.show', $murah), false)
            ->assertDontSee(route('package.show', $mahal), false);

        $this->get('/?max_price=30000000')->assertOk()
            ->assertSee(route('package.show', $murah), false)
            ->assertDontSee(route('package.show', $mahal), false);
    }

    public function test_filter_nama_hotel(): void
    {
        $anjum = $this->package(['departure_city' => 'Bandung', 'hotel_makkah' => 'Hotel Anjum Makkah']);
        $lain = $this->package(['departure_city' => 'Semarang', 'hotel_makkah' => 'Swissotel Al Maqam']);

        $this->get('/?hotel=anjum')->assertOk()
            ->assertSee(route('package.show', $anjum), false)
            ->assertDontSee(route('package.show', $lain), false);
    }

    public function test_harga_di_bawah_bpiu_diberi_peringatan_bukan_disembunyikan(): void
    {
        config(['umroh.bpiu_reference' => 23000000]);
        $this->package(['departure_city' => 'Jakarta'], 15000000);

        $this->get('/')
            ->assertOk()
            ->assertSee('Jakarta')
            ->assertSee('di bawah BPIU Referensi Kemenag', false);
    }

    public function test_halaman_detail_menampilkan_tanggal_data_dan_sumber(): void
    {
        $p = $this->package([
            'source_account' => 'agen_a',
            'source_permalink' => 'https://instagram.com/p/abc',
            'media_id' => 'm1',
        ]);

        $this->get(route('package.show', $p))
            ->assertOk()
            ->assertSee('Data per')
            ->assertSee('konfirmasi langsung ke travel', false)
            ->assertSee('https://instagram.com/p/abc', false);
    }
}
