<?php

namespace Tests\Feature;

use App\Models\Package;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PackageImportTest extends TestCase
{
    use RefreshDatabase;

    private function extraction(array $override = []): array
    {
        return array_merge([
            '_media_id' => 'm1',
            '_source' => 'agen_a',
            'ppiu_name' => 'PT Barokah Wisata',
            'license_number' => '123/2019',
            'departure_date' => '2026-09-14',
            'date_certainty' => 'exact',
            'duration_days' => 9,
            'departure_city' => 'Jakarta',
            'airline' => 'Saudia',
            'extension' => 'none',
            'price_tiers' => [
                ['occupancy' => 'quad', 'amount' => 25900000, 'currency' => 'IDR', 'is_starting_from' => false],
                ['occupancy' => 'double', 'amount' => 29000000, 'currency' => 'IDR', 'is_starting_from' => false],
            ],
            'hotel_makkah' => ['raw_name' => 'Hotel Anjum Makkah', 'nights' => 4],
            'hotel_madinah' => ['raw_name' => 'setaraf Al Haram', 'nights' => 3],
            'facilities' => ['visa', 'tiket', 'makan_3x'],
            'confidence' => ['price' => 0.9, 'departure_date' => 0.9, 'hotels' => 0.8, 'ppiu' => 0.9],
            '_needs_review' => false,
        ], $override);
    }

    private function import(array ...$docs): void
    {
        $dir = storage_path('framework/testing/extracted');
        is_dir($dir) || mkdir($dir, 0775, true);
        array_map('unlink', glob("$dir/*.json") ?: []);
        foreach ($docs as $i => $doc) {
            file_put_contents("$dir/$i.json", json_encode($doc));
        }
        $this->artisan('packages:import', ['--dir' => $dir])->assertSuccessful();
    }

    public function test_impor_membuat_paket_dengan_beberapa_tier_harga(): void
    {
        $this->import($this->extraction());

        $package = Package::sole();
        $this->assertSame(['quad' => 25900000, 'double' => 29000000], $package->prices(),
            'harga wajib multi-tier, bukan satu kolom');
        $this->assertSame(25900000, $package->lowestPrice());
        $this->assertSame('Jakarta', $package->departure_city);
        $this->assertSame(['visa', 'tiket', 'makan_3x'], $package->facilities);
    }

    /** "USD 3.300" masuk apa adanya = 0,0 jt di UI dan selalu termurah saat diurutkan. */
    public function test_harga_usd_dikonversi_ke_rupiah_saat_import(): void
    {
        config(['umroh.usd_rate' => 16500]);

        $this->import($this->extraction(['price_tiers' => [
            ['occupancy' => 'quad', 'amount' => 3300, 'currency' => 'USD', 'is_starting_from' => true],
        ]]));

        $package = Package::sole();
        $this->assertSame(3300 * 16500, $package->price_quad);
        $this->assertSame('IDR', $package->currency);
        $this->assertTrue($package->convertedFromUsd(), 'asal USD wajib ditandai di UI');
    }

    public function test_repost_dari_akun_lain_digabung_bukan_jadi_paket_baru(): void
    {
        $this->import(
            $this->extraction(['_media_id' => 'm1', '_source' => 'agen_a']),
            $this->extraction(['_media_id' => 'm2', '_source' => 'agen_b']),
            $this->extraction(['_media_id' => 'm3', '_source' => 'agen_c']),
        );

        $this->assertSame(1, Package::count(), 'dedup per paket, bukan per akun sumber');

        $package = Package::sole();
        $this->assertSame('agen_a', $package->source_account, 'post asal ekstraksi tetap di kolom paket');
        $this->assertSame(['agen_b', 'agen_c'], array_column($package->reposts, 'account'),
            'akun yang repost wajib tercatat, bukan dibuang');
    }

    public function test_tanggal_berbeda_tetap_paket_berbeda(): void
    {
        $this->import(
            $this->extraction(['_media_id' => 'm1']),
            $this->extraction(['_media_id' => 'm2', 'departure_date' => '2026-10-20']),
        );

        $this->assertSame(2, Package::count());
    }

    public function test_paket_tidak_pernah_langsung_published(): void
    {
        $this->import(
            $this->extraction(['_media_id' => 'm1', '_needs_review' => false]),
            $this->extraction(['_media_id' => 'm2', '_needs_review' => true, 'departure_date' => '2026-11-01']),
        );

        $this->assertSame(0, Package::where('status', 'published')->count());
        $this->assertSame(1, Package::where('status', 'review')->count());
    }

    /** Hotel ikut di baris paket, apa adanya dari flyer. */
    public function test_nama_hotel_disimpan_apa_adanya_dari_flyer(): void
    {
        $this->import($this->extraction());

        $package = Package::sole();
        $this->assertSame('Hotel Anjum Makkah', $package->hotel_makkah);
        $this->assertSame('setaraf Al Haram', $package->hotel_madinah);
        $this->assertSame(4, $package->nights_makkah);
    }

    /** Repost idempoten: import ulang atas backlog yang sama tidak menggandakan. */
    public function test_import_ulang_tidak_menggandakan_repost(): void
    {
        $docs = [
            $this->extraction(['_media_id' => 'm1', '_source' => 'agen_a']),
            $this->extraction(['_media_id' => 'm2', '_source' => 'agen_b']),
        ];

        $this->import(...$docs);
        $this->import(...$docs);

        $this->assertSame(1, Package::count());
        $this->assertCount(1, Package::sole()->reposts);
    }

    /** Postingan yang dilabeli bukan penawaran paket tidak boleh masuk DB sama sekali. */
    public function test_postingan_bukan_penawaran_ditolak(): void
    {
        $this->import($this->extraction(['post_kind' => 'hotel_info']));

        $this->assertSame(0, Package::count());
    }

    /** --prune memindahkan yang bukan penawaran ke storage/trash, bukan menghapusnya. */
    public function test_prune_memindahkan_post_bukan_penawaran_ke_trash(): void
    {
        $dir = storage_path('framework/testing/extracted');
        is_dir($dir) || mkdir($dir, 0775, true);
        array_map('unlink', glob("$dir/*.json") ?: []);
        file_put_contents("$dir/0.json", json_encode(
            $this->extraction(['_media_id' => 'trash1', '_source' => 'agen_test', 'post_kind' => 'testimoni']),
        ));

        $this->artisan('packages:import', ['--dir' => $dir, '--prune' => true])->assertSuccessful();

        $trash = storage_path('trash/agen_test/trash1/extracted.json');
        $this->assertFileExists($trash, 'post buangan wajib bisa dicek ulang, bukan hilang');
        $this->assertFileDoesNotExist("$dir/0.json");
        $this->assertSame(0, Package::count());

        File::deleteDirectory(storage_path('trash/agen_test'));
    }

    /**
     * Jaring pengaman kalau labelnya meleset: poster "Daftar Hotel" isinya nama
     * hotel doang, tanpa tanggal, harga, maupun durasi.
     */
    public function test_hotel_tanpa_keberangkatan_ditolak_walau_dilabeli_paket(): void
    {
        $this->import($this->extraction([
            'post_kind' => 'package_offer',
            'departure_date' => null,
            'duration_days' => null,
            'departure_city' => null,
            'airline' => null,
            'price_tiers' => [],
        ]));

        $this->assertSame(0, Package::count());
    }

    /** Harga hasil baca yang gagal (0) bukan sinyal keberangkatan. */
    public function test_harga_nol_tidak_dihitung_sebagai_paket(): void
    {
        $this->import($this->extraction([
            'departure_date' => null,
            'duration_days' => null,
            'price_tiers' => [['occupancy' => 'quad', 'amount' => 0, 'currency' => 'IDR', 'is_starting_from' => false]],
        ]));

        $this->assertSame(0, Package::count());
    }

    /** Teaser tanpa harga tetap masuk selama ada tanggal + satu sinyal lain. */
    public function test_paket_tanpa_harga_tetap_masuk_review(): void
    {
        $this->import($this->extraction([
            'price_tiers' => [],
            'hotel_makkah' => null,
            'hotel_madinah' => null,
            'airline' => null,
            '_needs_review' => true,
        ]));

        $this->assertSame('review', Package::sole()->status);
    }

    /** Keberangkatan sebelum ambang tidak diimpor, dan post-nya dibanned. */
    public function test_keberangkatan_sebelum_ambang_dibanned(): void
    {
        config(['umroh.min_departure' => '2026-08-01']);

        $this->import($this->extraction(['_media_id' => 'lewat1', 'departure_date' => '2026-07-14']));

        $this->assertSame(0, Package::count(), 'keberangkatan sebelum Agustus jangan masuk');
        $this->assertDatabaseHas('banned_posts', ['media_id' => 'lewat1', 'reason' => 'sebelum_ambang']);
    }

    /** Tanggal kosong belum bisa dinilai — jangan ikut dibuang oleh ambang. */
    public function test_tanpa_tanggal_tidak_kena_ambang(): void
    {
        config(['umroh.min_departure' => '2026-08-01']);

        $this->import($this->extraction(['departure_date' => null, '_needs_review' => true]));

        $this->assertSame(1, Package::count());
    }

    /**
     * Satu carousel bisa memuat beberapa paket berbeda: probe.php memecahnya jadi
     * satu file per gambar, dan tiap file wajib jadi barisnya sendiri.
     */
    public function test_carousel_jadi_beberapa_paket_satu_per_gambar(): void
    {
        $this->import(
            $this->extraction([
                '_media_id' => 'car1',
                'departure_date' => '2026-09-14',
                '_useful_images' => ['1.jpg'],
            ]),
            $this->extraction([
                '_media_id' => 'car1',
                'departure_date' => '2026-10-20',
                '_useful_images' => ['2.jpg'],
            ]),
        );

        $this->assertSame(2, Package::count(), 'dua gambar penawaran = dua paket');
        $this->assertSame([1, 2], Package::orderBy('flyer_index')->pluck('flyer_index')->all());
    }

    public function test_paket_di_bawah_bpiu_referensi_ditandai(): void
    {
        config(['umroh.bpiu_reference' => 23000000]);

        $this->import($this->extraction(['price_tiers' => [
            ['occupancy' => 'quad', 'amount' => 18000000, 'currency' => 'IDR', 'is_starting_from' => true],
        ]]));

        $this->assertTrue(Package::sole()->isBelowReferencePrice());
    }
}
