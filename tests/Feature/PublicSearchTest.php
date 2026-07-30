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

    /** Tombol X: paketnya hilang dan raw post sumbernya ikut dihapus. */
    public function test_tombol_buang_menghapus_paket_dan_sumbernya(): void
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

        // Yang menjaga post ini tidak di-scrap lagi barisnya, bukan filenya.
        $this->assertDatabaseHas('excluded_posts', ['media_id' => 'buang1', 'reason' => 'manual']);
    }

    /**
     * Satu carousel = beberapa paket. Buang satu slide tidak boleh menyeret raw
     * post-nya ke trash: slide lain masih memakai gambar di folder yang sama.
     */
    public function test_buang_satu_slide_tidak_membuang_sumber_milik_slide_lain(): void
    {
        $buang = $this->package(['status' => 'review', 'media_id' => 'carousel1',
            'source_account' => 'agen_y', 'flyer_index' => 1]);
        $this->package(['status' => 'review', 'media_id' => 'carousel1',
            'source_account' => 'agen_y', 'flyer_index' => 2, 'departure_date' => '2026-04-20']);

        $raw = storage_path('raw/agen_y/carousel1');
        File::ensureDirectoryExists($raw);
        file_put_contents("$raw/post.json", '{}');

        app()['env'] = 'local';
        config(['app.env' => 'local']);
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->deleteJson("/paket/{$buang->id}")->assertOk();

        $this->assertSame(1, Package::count());
        $this->assertFileExists("$raw/post.json", 'flyer slide lain ikut hilang');
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('excluded_posts')
            ->where('media_id', 'carousel1')->count(),
            'post yang masih punya paket lain tidak boleh dikecualikan');

        File::deleteDirectory(storage_path('raw/agen_y'));
    }

    /** Panel pipeline itu alat kerja lokal — jangan pernah kebuka di produksi. */
    public function test_panel_pipeline_dikunci_ke_lokal(): void
    {
        $this->getJson('/pipeline/status')->assertNotFound();

        app()['env'] = 'local';
        config(['app.env' => 'local']);

        $this->getJson('/pipeline/status')
            ->assertOk()
            ->assertJsonStructure([
                'sekarang', 'akun', 'terfetch', 'antrian',
                'post_diunduh', 'post_menunggu', 'post_dibaca', 'post_dikecualikan',
                'paket', 'draft', 'review', 'published', 'jalan',
            ]);
    }

    /**
     * Corong pipeline: tiap tahap dihitung dari sumbernya, tidak ada tabel progress.
     * Yang gampang salah itu pecahan status paket — kalau `published` ikut kehitung
     * di `review`, panel bilang ada yang sudah tampil publik padahal belum.
     */
    public function test_corong_pipeline_memecah_status_paket_dan_alasan_pengecualian(): void
    {
        app()['env'] = 'local';
        config(['app.env' => 'local']);

        $this->package(['status' => 'review', 'media_id' => 'a']);
        $this->package(['status' => 'review', 'media_id' => 'b']);
        $this->package(['status' => 'draft', 'media_id' => 'c']);
        $this->package(['status' => 'published', 'media_id' => 'd']);

        \Illuminate\Support\Facades\DB::table('excluded_posts')->insert([
            ['media_id' => 'x1', 'reason' => 'bukan_paket', 'created_at' => now(), 'updated_at' => now()],
            ['media_id' => 'x2', 'reason' => 'bukan_paket', 'created_at' => now(), 'updated_at' => now()],
            ['media_id' => 'x3', 'reason' => 'sebelum_ambang', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->getJson('/pipeline/status')
            ->assertOk()
            ->assertJson([
                'paket' => 4,
                'review' => 2,
                'draft' => 1,
                'published' => 1,
                'post_dikecualikan' => 3,
                'alasan' => ['bukan_paket' => 2, 'sebelum_ambang' => 1],
            ]);
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

    /** Lightbox mengambil potongan yang sama lewat fetch — tanpa layout. */
    public function test_detail_lewat_ajax_balas_potongan_tanpa_layout(): void
    {
        $p = $this->package(['source_account' => 'agen_a', 'media_id' => 'm1']);

        $this->get(route('package.show', $p), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('konfirmasi langsung ke travel', false)
            ->assertDontSee('<html', false);
    }

    /**
     * Pilihan filter diambil dari data yang benar-benar ada, bukan daftar tetap:
     * nilai yang tidak dipakai satu barispun tidak boleh muncul, dan yang ada
     * dibawa lengkap dengan jumlahnya.
     */
    public function test_pilihan_filter_diambil_dari_data_yang_ada(): void
    {
        $this->package(['airline' => 'Saudia']);
        $this->package(['airline' => 'Saudia']);
        $this->package(['airline' => 'Oman Air']);
        $this->package(['airline' => 'Qatar Airways', 'status' => 'draft']);   // belum published

        $this->get('/')->assertOk()
            ->assertSee('Saudia (2)')
            ->assertSee('Oman Air (1)')
            ->assertDontSee('Qatar Airways');
    }

    public function test_filter_durasi_dan_pencarian_bebas(): void
    {
        $panjang = $this->package(['departure_city' => 'Solo', 'duration_days' => 14,
            'guide_name' => 'Ustadz Fulan']);
        $pendek = $this->package(['departure_city' => 'Medan', 'duration_days' => 9]);

        $this->get('/?duration_min=12')->assertOk()
            ->assertSee(route('package.show', $panjang), false)
            ->assertDontSee(route('package.show', $pendek), false);

        // Cari bebas menyapu pembimbing/hotel/kota/maskapai sekaligus.
        $this->get('/?q=fulan')->assertOk()
            ->assertSee(route('package.show', $panjang), false)
            ->assertDontSee(route('package.show', $pendek), false);
    }
}
