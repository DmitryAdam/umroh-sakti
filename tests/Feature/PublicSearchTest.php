<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\SourceAccount;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /** Catatan koreksi cuma boleh dari pratinjau operator, dan tidak mengubah status publikasi. */
    public function test_catatan_koreksi_tersimpan_dan_butuh_login(): void
    {
        $package = $this->package(['status' => 'review']);

        $this->post("/packages/{$package->id}/feedback", ['review_verdict' => 'bukan_paket'])
            ->assertRedirect(route('login'));

        $this->actingAsOperator();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->post("/packages/{$package->id}/feedback", [
            'review_verdict' => 'bukan_paket',
            'review_note' => 'ini poster daftar hotel',
        ])->assertRedirect();

        // Jalur AJAX: balas JSON, jangan redirect — halaman tidak boleh reload.
        $this->postJson("/packages/{$package->id}/feedback", ['review_verdict' => 'oke'])
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

        $this->deleteJson("/packages/{$package->id}")->assertUnauthorized();

        $this->actingAsOperator();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->deleteJson("/packages/{$package->id}")->assertOk();

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

        $this->actingAsOperator();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->deleteJson("/packages/{$buang->id}")->assertOk();

        $this->assertSame(1, Package::count());
        $this->assertFileExists("$raw/post.json", 'flyer slide lain ikut hilang');
        $this->assertSame(0, DB::table('excluded_posts')
            ->where('media_id', 'carousel1')->count(),
            'post yang masih punya paket lain tidak boleh dikecualikan');

        File::deleteDirectory(storage_path('raw/agen_y'));
    }

    /** Panel pipeline itu alat kerja operator — tamu tidak pernah dapat angkanya. */
    public function test_panel_pipeline_butuh_login(): void
    {
        $this->getJson('/pipeline/status')->assertUnauthorized();

        $this->actingAsOperator();

        $this->getJson('/pipeline/status')
            ->assertOk()
            ->assertJsonStructure([
                'sekarang', 'akun', 'terfetch', 'antrian',
                'post_diunduh', 'post_menunggu', 'post_dibaca', 'post_dikecualikan',
                'paket', 'review', 'published', 'jalan',
            ]);
    }

    /** Tombol batal membuang antrian + daftar gagal, dan tetap butuh login. */
    public function test_batalkan_antrian_mengosongkan_jobs_dan_failed_jobs(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->deleteJson('/pipeline/queue')->assertUnauthorized();

        $this->actingAsOperator();

        DB::table('jobs')->insert([
            ['queue' => 'ig', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()],
            ['queue' => 'ai', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()],
        ]);
        DB::table('failed_jobs')->insert([
            ['uuid' => 'u1', 'connection' => 'database', 'queue' => 'ig', 'payload' => '{}',
                'exception' => 'x', 'failed_at' => now()],
        ]);

        // Batal per antrian: cuma antrian itu yang dibuang, tetangganya tetap.
        $this->deleteJson('/pipeline/queue/ig')
            ->assertOk()
            ->assertJson(['antri_ig' => 0, 'antri_ai' => 1, 'gagal' => 0]);

        $this->assertSame(['ai'], DB::table('jobs')->pluck('queue')->all());

        $this->deleteJson('/pipeline/queue')
            ->assertOk()
            ->assertJson(['antrian' => 0, 'gagal' => 0, 'jalan' => false]);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());

        // Nama antrian asing tidak boleh jadi "hapus semua".
        $this->deleteJson('/pipeline/queue/default')->assertNotFound();
    }

    /** Job yang sedang dikerjakan tetap kehitung — barisnya cuma ber-`reserved_at`. */
    public function test_status_memisah_job_antri_dan_job_jalan_per_antrian(): void
    {
        $this->actingAsOperator();

        DB::table('jobs')->insert([
            ['queue' => 'ai', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
                'available_at' => time(), 'created_at' => time()],
            ['queue' => 'ai', 'payload' => '{}', 'attempts' => 1, 'reserved_at' => time(),
                'available_at' => time(), 'created_at' => time()],
        ]);

        $this->getJson('/pipeline/status')
            ->assertOk()
            ->assertJson([
                'antri_ai' => 1,
                'antrian_per' => ['ai' => ['antri' => 1, 'proses' => 1, 'gagal' => 0]],
            ]);
    }

    /**
     * Corong pipeline: tiap tahap dihitung dari sumbernya, tidak ada tabel progress.
     * Yang gampang salah itu pecahan status paket — kalau `published` ikut kehitung
     * di `review`, panel bilang ada yang sudah tampil publik padahal belum.
     */
    public function test_corong_pipeline_memecah_status_paket_dan_alasan_pengecualian(): void
    {
        $this->actingAsOperator();

        $this->package(['status' => 'review', 'media_id' => 'a']);
        $this->package(['status' => 'review', 'media_id' => 'b']);
        $this->package(['status' => 'review', 'media_id' => 'c']);
        $this->package(['status' => 'published', 'media_id' => 'd']);

        DB::table('excluded_posts')->insert([
            ['media_id' => 'x1', 'reason' => 'bukan_paket', 'created_at' => now(), 'updated_at' => now()],
            ['media_id' => 'x2', 'reason' => 'bukan_paket', 'created_at' => now(), 'updated_at' => now()],
            ['media_id' => 'x3', 'reason' => 'sebelum_ambang', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->getJson('/pipeline/status')
            ->assertOk()
            ->assertJson([
                'paket' => 4,
                'review' => 3,
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

    /**
     * Keberangkatan yang sudah lewat tetap ada (masih ketemu lewat ?from=/?to=),
     * tapi selalu di dasar daftar — di urutan mana pun, termasuk urut harga.
     */
    public function test_keberangkatan_lewat_selalu_di_bawah(): void
    {
        $this->package(['departure_city' => 'Lewat', 'departure_date' => now()->subDay()], 10_000_000);
        $this->package(['departure_city' => 'Nanti', 'departure_date' => now()->addMonth()], 40_000_000);
        $this->package(['departure_city' => 'Tanpatanggal', 'departure_date' => null], 20_000_000);

        $urutan = fn (string $sort) => array_map(
            fn ($p) => $p->departure_city,
            $this->get("/?sort=$sort")->assertOk()->viewData('packages')->all(),
        );

        // Baris tanpa tanggal bukan "lewat" — kalau kunci pertamanya balik NULL,
        // justru dia yang naik ke puncak.
        $this->assertSame(['Nanti', 'Tanpatanggal', 'Lewat'], $urutan('date'));
        $this->assertSame(['Nanti', 'Tanpatanggal', 'Lewat'], $urutan('date_desc'));
        $this->assertSame(['Tanpatanggal', 'Nanti', 'Lewat'], $urutan('price'));

        // Hari ini masih dihitung berangkat, bukan lewat.
        $this->package(['departure_city' => 'Harini', 'departure_date' => now()]);
        $this->assertSame('Harini', $urutan('date')[0]);
    }

    /** Rentang keberangkatan: batasnya inklusif, dan boleh diisi salah satu saja. */
    public function test_filter_rentang_tanggal_keberangkatan(): void
    {
        // Tanggalnya relatif ke hari ini: keberangkatan yang lewat diurut ke dasar
        // daftar, jadi tanggal tetap bikin urutan yang diharapkan basi sendiri
        // begitu tanggalnya terlewati.
        [$awal, $tengah, $akhir] = [now()->addMonth(), now()->addMonths(3), now()->addMonths(6)];

        $this->package(['departure_city' => 'Awal', 'departure_date' => $awal]);
        $this->package(['departure_city' => 'Tengah', 'departure_date' => $tengah]);
        $this->package(['departure_city' => 'Akhir', 'departure_date' => $akhir]);

        $kota = fn (string $q) => array_map(
            fn ($p) => $p->departure_city,
            $this->get("/?$q")->assertOk()->viewData('packages')->all(),
        );

        $d = fn ($t) => $t->toDateString();

        $this->assertSame(['Tengah'], $kota("from={$d($tengah)}&to={$d($tengah)}"), 'batasnya inklusif');
        $this->assertSame(['Tengah', 'Akhir'], $kota('from='.$d($tengah->copy()->subDay())));
        $this->assertSame(['Awal', 'Tengah'], $kota('to='.$d($tengah)));
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
        $this->package(['airline' => 'Qatar Airways', 'status' => 'review']);   // belum published

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

    /** Nama travel = akun IG-nya: username di kolom paket, nama tampilan di source_accounts. */
    public function test_cari_bebas_menyapu_username_dan_nama_travel(): void
    {
        SourceAccount::create(['username' => 'sunnatravel.id', 'full_name' => 'Sunna Wisata Hati',
            'status' => 'approved']);
        $milikTravel = $this->package(['source_account' => 'sunnatravel.id']);
        $lain = $this->package(['source_account' => 'umitourtravel_id']);

        foreach (['sunnatravel', 'wisata hati'] as $cari) {
            $this->get('/?q='.urlencode($cari))->assertOk()
                ->assertSee(route('package.show', $milikTravel), false)
                ->assertDontSee(route('package.show', $lain), false);
        }
    }

    /**
     * Halaman pertama dipotong; sisanya diambil lewat fetch dan yang dibalas cuma
     * kartunya (partial yang sama), bukan halaman penuh — kalau ikut layout,
     * `insertAdjacentHTML` menempelkan header + footer kedua ke dalam grid.
     */
    public function test_hasil_dipotong_dan_halaman_berikutnya_cuma_kartu(): void
    {
        for ($i = 0; $i < 26; $i++) {
            $this->package(['guide_name' => "Ustadz $i"]);
        }

        $satu = $this->get('/')->assertOk()
            ->assertSee('>26</span> paket', false)   // total, bukan sebanyak halaman ini
            ->assertSee('muat lebih banyak');
        $this->assertSame(24, substr_count($satu->getContent(), 'id="p'));

        $dua = $this->get('/?page=2', ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()
            ->assertDontSee('<html', false)
            ->assertDontSee('muat lebih banyak');   // halaman terakhir: sentinelnya hilang
        $this->assertSame(2, substr_count($dua->getContent(), 'id="p'));
    }

    /**
     * Halaman tentang: publik dan benar-benar dirender. Tiap halaman baru butuh satu
     * assertOk() — komponen void yang lupa `/>` cuma tumbang saat view-nya di-compile.
     */
    public function test_halaman_tentang_terbuka_untuk_tamu(): void
    {
        $this->get('/about')->assertOk()->assertSee('Tentang Umroh Sakti');
    }
}
