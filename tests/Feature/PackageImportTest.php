<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Support\ExcludedPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
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
            // Flyer itu syarat masuk: hasil ekstraksi yang normal selalu menyebut
            // gambar sumbernya, dan yang tidak ditolak `tanpa_gambar`.
            '_useful_images' => ['0.jpg'],
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

    /**
     * Maskapai yang tidak terbaca di salah satu ekstraksi bukan paket lain.
     * #1069 (maskapai null) dan #1473 ("Saudi Airlines") punya tanggal, durasi,
     * dan dua hotel yang sama persis — dua baris untuk satu paket.
     */
    public function test_maskapai_kosong_di_satu_sisi_tetap_paket_yang_sama(): void
    {
        $this->import(
            $this->extraction(['_media_id' => 'm1', 'airline' => null]),
            $this->extraction(['_media_id' => 'm2', '_source' => 'agen_b', 'airline' => 'Saudia Airlines']),
        );

        $this->assertSame(1, Package::count(), 'maskapai kosong bukan pembeda paket');
    }

    /** "Snood Ajyad", "Snood Ajyad / Setaraf", "Snood Ajyad (±500 m ke Haram)" = satu hotel. */
    public function test_setaraf_dan_keterangan_dalam_kurung_bukan_nama_hotel(): void
    {
        $this->import(
            $this->extraction(['_media_id' => 'm1',
                'hotel_makkah' => ['raw_name' => 'Snood Ajyad'],
                'hotel_madinah' => ['raw_name' => 'Durrat Al Eiman'],
            ]),
            $this->extraction(['_media_id' => 'm2', '_source' => 'agen_b',
                'hotel_makkah' => ['raw_name' => 'Snood Ajyad / Setaraf ★3'],
                'hotel_madinah' => ['raw_name' => 'Durrat Al Eiman (±150 meter ke Masjid Nabawi)'],
            ]),
        );

        $this->assertSame(1, Package::count(), '"setaraf" dan isi kurung bukan bagian nama hotel');
    }

    /** Hotel + tanggal sama tapi durasi beda itu program lain, bukan repost. */
    public function test_durasi_berbeda_tetap_paket_berbeda(): void
    {
        $this->import(
            $this->extraction(['_media_id' => 'm1', 'duration_days' => 9]),
            $this->extraction(['_media_id' => 'm2', 'duration_days' => 12]),
        );

        $this->assertSame(2, Package::count());
    }

    /** Hotel kosong bukan wildcard: tanpa itu satu baris tanpa hotel menelan paket sehari. */
    public function test_hotel_kosong_tidak_digabung_dengan_paket_lain(): void
    {
        $this->import(
            $this->extraction(['_media_id' => 'm1']),
            $this->extraction(['_media_id' => 'm2', '_source' => 'agen_b',
                'hotel_makkah' => null, 'hotel_madinah' => null,
            ]),
        );

        $this->assertSame(2, Package::count());
    }

    /**
     * Baris lama yang lahir sebelum aturan dedup-nya diperketat: kuncinya dihitung
     * ulang lalu yang kalah jadi repost. Tanpa hitung-ulang, baris lama tidak
     * pernah ketemu pasangannya.
     */
    public function test_perintah_dedupe_menyatukan_baris_lama(): void
    {
        $this->import($this->extraction(['_media_id' => 'm1', 'airline' => null]));

        // Baris kedua ditulis langsung: import yang baru memang sudah menolaknya.
        $lama = Package::sole()->replicate()->fill([
            'media_id' => 'm2', 'source_account' => 'agen_b',
            'airline' => 'Saudia Airlines', 'flyer_index' => null,
            'dedup_key' => 'kunci-lama-yang-beda',
        ]);
        $lama->save();

        $this->artisan('packages:dedupe')->assertSuccessful();

        $this->assertSame(1, Package::count());
        $this->assertSame('m2', Package::sole()->media_id,
            'yang datanya lebih lengkap yang bertahan — maskapainya kebaca, bukan null');
        $this->assertSame(['m1'], array_column(Package::sole()->reposts, 'media_id'),
            'yang kalah dicatat sebagai repost, bukan dibuang diam-diam');
    }

    public function test_dedupe_dry_run_tidak_mengubah_apa_pun(): void
    {
        $this->import($this->extraction(['_media_id' => 'm1', 'airline' => null]));

        $lama = Package::sole()->replicate()->fill([
            'media_id' => 'm2', 'flyer_index' => null, 'dedup_key' => 'kunci-lama-yang-beda',
        ]);
        $lama->save();

        $this->artisan('packages:dedupe', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(2, Package::count());
        $this->assertSame('kunci-lama-yang-beda', Package::find($lama->id)->dedup_key);
    }

    public function test_tanggal_berbeda_tetap_paket_berbeda(): void
    {
        $this->import(
            $this->extraction(['_media_id' => 'm1']),
            $this->extraction(['_media_id' => 'm2', 'departure_date' => '2026-10-20']),
        );

        $this->assertSame(2, Package::count());
    }

    /**
     * Yang menahan status cuma `_manual` (kiriman tangan), bukan `_needs_review`:
     * hasil scrap sudah lewat gerbang vision + saringan import, jadi langsung publik.
     */
    public function test_hanya_kiriman_tangan_yang_masuk_review(): void
    {
        $this->import(
            $this->extraction(['_media_id' => 'm1', '_needs_review' => true]),
            $this->extraction(['_media_id' => 'm2', '_manual' => true, 'departure_date' => '2026-11-01']),
        );

        $this->assertSame('published', Package::where('media_id', 'm1')->value('status'));
        $this->assertSame('review', Package::where('media_id', 'm2')->value('status'));
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

    /**
     * --prune menghapus file post yang ditolak, KECUALI `bukan_paket`: itu vonis
     * mesin (gerbang vision / saringan struktural) dan yang paling sering salah —
     * filenya ditahan sampai operator menekan blokir. Jejaknya tetap di
     * excluded_posts, jadi fetch & extract tidak mengulanginya.
     */
    public function test_prune_menghapus_yang_ditolak_kecuali_bukan_paket(): void
    {
        $dir = storage_path('framework/testing/extracted');
        is_dir($dir) || mkdir($dir, 0775, true);
        array_map('unlink', glob("$dir/*.json") ?: []);
        File::ensureDirectoryExists(storage_path('raw/agen_test/trash1'));
        File::ensureDirectoryExists(storage_path('raw/agen_test/kadaluarsa1'));
        file_put_contents("$dir/0.json", json_encode(
            $this->extraction(['_media_id' => 'trash1', '_source' => 'agen_test', 'post_kind' => 'testimoni']),
        ));
        file_put_contents("$dir/1.json", json_encode(
            $this->extraction(['_media_id' => 'kadaluarsa1', '_source' => 'agen_test', 'departure_date' => '2026-01-05']),
        ));
        touch("$dir/0.json", time() - 3600);   // ekstraksinya sudah lama selesai
        touch("$dir/1.json", time() - 3600);

        $this->artisan('packages:import', ['--dir' => $dir, '--prune' => true])->assertSuccessful();

        $this->assertFileExists("$dir/0.json");
        $this->assertDirectoryExists(storage_path('raw/agen_test/trash1'));
        $this->assertFileDoesNotExist("$dir/1.json");
        $this->assertDirectoryDoesNotExist(storage_path('raw/agen_test/kadaluarsa1'));

        $this->assertSame(0, Package::count());
        $this->assertDatabaseHas('excluded_posts', ['media_id' => 'trash1', 'reason' => 'bukan_paket']);
        $this->assertDatabaseHas('excluded_posts', ['media_id' => 'kadaluarsa1', 'reason' => 'sebelum_ambang']);
    }

    /**
     * Antrian `ai` menulis slide carousel satu per satu; import yang membaca
     * separuhnya pernah menghapus folder raw yang dibutuhkan slide berikutnya —
     * paketnya lahir tanpa flyer (paket #375, selisih 10 detik). Hapusnya tidak
     * bisa dibatalkan, jadi hasil ekstraksi yang masih hangat cuma dikecualikan.
     */
    public function test_prune_menunggu_ekstraksi_yang_masih_hangat(): void
    {
        $dir = storage_path('framework/testing/extracted');
        is_dir($dir) || mkdir($dir, 0775, true);
        array_map('unlink', glob("$dir/*.json") ?: []);
        File::ensureDirectoryExists(storage_path('raw/agen_test/hangat1'));
        file_put_contents("$dir/0.json", json_encode(
            $this->extraction(['_media_id' => 'hangat1', '_source' => 'agen_test', 'post_kind' => 'testimoni']),
        ));

        $this->artisan('packages:import', ['--dir' => $dir, '--prune' => true])->assertSuccessful();

        $this->assertFileExists("$dir/0.json");
        $this->assertDirectoryExists(storage_path('raw/agen_test/hangat1'),
            'slide berikutnya masih butuh folder raw ini');
        $this->assertDatabaseHas('excluded_posts', ['media_id' => 'hangat1']);
    }

    /** Exclusion yang kadung tercatat saat carouselnya belum habis diekstrak wajib dicabut. */
    public function test_exclusion_dicabut_kalau_slide_lain_ternyata_jadi_paket(): void
    {
        ExcludedPost::add('carousel3', 'agen_a', 'sebelum_ambang');

        $this->import(
            $this->extraction([
                '_media_id' => 'carousel3',
                '_useful_images' => ['0.jpg'],
                'post_kind' => 'education',
                'departure_date' => null,
                'duration_days' => null,
                'price_tiers' => [],
            ]),
            $this->extraction(['_media_id' => 'carousel3', '_useful_images' => ['1.jpg']]),
        );

        $this->assertSame(1, Package::where('media_id', 'carousel3')->count());
        $this->assertDatabaseMissing('excluded_posts', ['media_id' => 'carousel3']);
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

    /**
     * Harga wajib. Postnya TIDAK dikecualikan: harga yang gagal terbaca itu
     * kegagalan model, bukan vonis atas postnya — ekstraksi ulang harus bisa
     * memungutnya lagi.
     */
    public function test_paket_tanpa_harga_tidak_diimpor_tapi_postnya_tidak_dikecualikan(): void
    {
        $this->import($this->extraction([
            '_media_id' => 'nolharga',
            'price_tiers' => [],
            '_needs_review' => true,
        ]));

        $this->assertSame(0, Package::count(), 'tanpa harga jangan masuk');
        $this->assertDatabaseMissing('excluded_posts', ['media_id' => 'nolharga']);
    }

    /**
     * Caption-only (semua gambarnya kena dedup hash, atau jalur `seed`): tidak ada
     * flyer yang bisa dipajang dan vision tidak pernah melihat apa pun. Postnya
     * tidak dikecualikan — sama alasannya dengan tanpa harga.
     */
    public function test_ekstraksi_tanpa_gambar_tidak_diimpor(): void
    {
        $this->import($this->extraction([
            '_media_id' => 'nogambar',
            '_useful_images' => [],
        ]));

        $this->assertSame(0, Package::count(), 'tanpa flyer jangan masuk');
        $this->assertDatabaseMissing('excluded_posts', ['media_id' => 'nogambar']);
    }

    /** Harga 0 itu hasil baca yang gagal, bukan paket gratis. */
    public function test_harga_nol_ditolak_seperti_tanpa_harga(): void
    {
        $this->import($this->extraction([
            'price_tiers' => [['occupancy' => 'quad', 'amount' => 0, 'currency' => 'IDR']],
        ]));

        $this->assertSame(0, Package::count());
    }

    /** Keberangkatan sebelum ambang tidak diimpor, dan post-nya dikecualikan. */
    public function test_keberangkatan_sebelum_ambang_dikecualikan(): void
    {
        config(['umroh.min_departure' => '2026-08-01']);

        $this->import($this->extraction(['_media_id' => 'lewat1', 'departure_date' => '2026-07-14']));

        $this->assertSame(0, Package::count(), 'keberangkatan sebelum Agustus jangan masuk');
        $this->assertDatabaseHas('excluded_posts', ['media_id' => 'lewat1', 'reason' => 'sebelum_ambang']);
    }

    /**
     * Ekstraksi ulang atas post yang barisnya sudah ada tidak boleh menabrak
     * UNIQUE (media_id, flyer_index).
     *
     * dedup_key ikut berubah kalau modelnya membaca "Saudia" jadi "Saudia Airlines",
     * jadi pencarian lewat dedup_key saja tidak menemukan baris lamanya lalu
     * create() melempar UniqueConstraintViolation — dan itu membatalkan sisa
     * backlog, bukan cuma satu file. Pernah kejadian: 150 hasil ekstraksi
     * menganggur, jumlah paket mandek di 52.
     */
    public function test_ekstraksi_ulang_tidak_menabrak_unique(): void
    {
        $doc = $this->extraction(['_media_id' => 'ulang1', '_useful_images' => ['0.jpg']]);
        $this->import($doc);

        // Baca ulang yang sedikit beda: airline lebih lengkap -> dedup_key baru.
        $this->import($this->extraction([
            '_media_id' => 'ulang1',
            '_useful_images' => ['0.jpg'],
            'airline' => 'Saudia Airlines',
        ]));

        $this->assertSame(1, Package::where('media_id', 'ulang1')->count(),
            'baris yang sama jangan digandakan dan jangan bikin import meledak');
        $this->assertSame('Saudia', Package::sole()->airline,
            'baris lama tidak ditimpa — status hasil review manusia ada di situ');
    }

    /**
     * Slide yang ditolak tidak boleh menyeret post-nya kalau slide lain jadi paket.
     *
     * Folder raw dipakai bersama satu carousel: kalau ikut dihapus,
     * `Package::flyers()` yang glob ke storage/raw balik kosong dan paket
     * saudaranya tampil tanpa gambar. Mengecualikan media_id-nya juga memblokir
     * fetch + extract untuk slide yang justru laku. Slide penolaknya sengaja
     * ditaruh lebih dulu — urutan file tidak boleh menentukan hasil.
     */
    public function test_slide_ditolak_tidak_mengecualikan_post_yang_slide_lain_jadi_paket(): void
    {
        $this->import(
            $this->extraction([
                '_media_id' => 'carousel1',
                '_useful_images' => ['0.jpg'],
                'post_kind' => 'education',
                'departure_date' => null,
                'duration_days' => null,
                'price_tiers' => [],
            ]),
            $this->extraction(['_media_id' => 'carousel1', '_useful_images' => ['1.jpg']]),
        );

        $this->assertSame(1, Package::where('media_id', 'carousel1')->count(),
            'slide penawarannya tetap harus masuk');
        $this->assertDatabaseMissing('excluded_posts', ['media_id' => 'carousel1']);
    }

    /** Kalau TIDAK ada slide yang jadi paket, post-nya memang dikecualikan. */
    public function test_semua_slide_ditolak_maka_postnya_dikecualikan(): void
    {
        $this->import($this->extraction([
            '_media_id' => 'carousel2',
            'post_kind' => 'education',
            'departure_date' => null,
            'duration_days' => null,
            'price_tiers' => [],
        ]));

        $this->assertSame(0, Package::count());
        $this->assertDatabaseHas('excluded_posts', ['media_id' => 'carousel2', 'reason' => 'bukan_paket']);
    }

    /**
     * Haji khusus bukan umroh — jangan masuk portal.
     *
     * Yang gampang salah: menyaring pakai "haji khusus"/"haji plus" mentah. Itu
     * kop surat travel ("UMROH & HAJI PLUS"), paketnya umroh beneran.
     */
    public function test_haji_khusus_tidak_diimpor_tapi_nama_travel_berbau_haji_tetap_lolos(): void
    {
        $this->import(
            $this->extraction([
                '_media_id' => 'haji1',
                'duration_days' => 24,
                '_flyer_text' => "AMMAR TOUR\nVisa Haji Resmi\nHaji Khusus\nMAKTAB VIP 24 Hari\nNomor Porsi",
            ]),
            $this->extraction([
                '_media_id' => 'umroh1',
                '_flyer_text' => "UMROH & HAJI PLUS\nUmroh 9 Hari\nIzin Haji : 2109\nIzin Umroh : 2109",
            ]),
        );

        $this->assertSame(['umroh1'], Package::pluck('media_id')->all(),
            'nama travel yang memuat "haji plus" bukan penanda paketnya haji');
        $this->assertDatabaseHas('excluded_posts', ['media_id' => 'haji1', 'reason' => 'haji']);
    }

    /**
     * Wisata halal ke Korea/Jepang/Eropa bukan umroh. Bentuknya paket lengkap —
     * tanggal, durasi, harga, maskapai — jadi tidak ada saringan lain yang menahannya.
     *
     * Dua yang gampang salah: kop surat travel memuat kata umroh ("Umroh & Halal
     * Tour" menjual Seoul), dan extension ke negara lain TETAP umroh selama tanah
     * sucinya ikut dijual.
     */
    public function test_wisata_halal_bukan_umroh_tapi_umroh_plus_negara_lain_tetap_lolos(): void
    {
        $this->import(
            $this->extraction([
                '_media_id' => 'tour1',
                '_flyer_text' => "Ramah Umroh & Halal Tour\nTOUR AGUSTUS\n5D SEOUL NAMI ISLAND + EVERLAND\n11.5 JT",
            ]),
            $this->extraction([
                '_media_id' => 'tour2',
                '_flyer_text' => "ABNA TOUR\nTHE ULTIMATE HAJJ & UMRAH EXPERIENCE\nMuslim Korea\nProgram 6 Hari",
            ]),
            $this->extraction([
                '_media_id' => 'plus1',
                '_flyer_text' => "UMROH PLUS TURKI CAPPADOCIA\n12 Hari\nHotel Makkah: Fairmont\nHotel Madinah: Anwar",
            ]),
        );

        $this->assertSame(['plus1'], Package::pluck('media_id')->all(),
            'umroh plus negara lain tetap umroh; kop surat berbau umroh bukan penanda');
        $this->assertDatabaseHas('excluded_posts', ['media_id' => 'tour1', 'reason' => 'bukan_umroh']);
        $this->assertDatabaseHas('excluded_posts', ['media_id' => 'tour2', 'reason' => 'bukan_umroh']);
    }

    /**
     * Flyer haji sering cuma menulis tahun. Cast `date` membaca "2027" sebagai unix
     * timestamp dan barisnya masuk bertanggal 1970 — ikut terurut paling awal dan
     * lolos semua filter rentang.
     */
    public function test_tanggal_tidak_lengkap_disimpan_kosong_bukan_1970(): void
    {
        $this->import($this->extraction(['_media_id' => 'tahun1', 'departure_date' => '2027']));

        $this->assertSame(0, Package::count(), 'tahun saja bukan tanggal — jangan masuk, jangan jadi 1970');
    }

    /** Bulan saja tetap dipakai: tanggal 1 + kepastian `month`, bukan `exact`. */
    public function test_bulan_saja_jadi_tanggal_satu_dengan_kepastian_month(): void
    {
        $this->import($this->extraction([
            'departure_date' => '2026-09',
            'date_certainty' => 'exact',   // kata model, tapi tanggalnya tidak lengkap
        ]));

        $package = Package::sole();
        $this->assertSame('2026-09-01', $package->departure_date->toDateString());
        $this->assertSame('month', $package->date_certainty);
    }

    /** Setter model ikut menjaga, bukan cuma import — worker bisa memegang kode lama. */
    public function test_setter_menolak_tanggal_yang_tidak_lengkap(): void
    {
        $this->assertNull((new Package(['departure_date' => '2027']))->departure_date);
    }

    /**
     * Akun yang sama memposting paket yang sama dua kali dengan ejaan beda:
     * "Qashr Al Anshar" vs "Qasr Al Anshar", "Saudia, Garuda" vs "Saudia / Garuda".
     * Dulu jadi dua baris (#76 dan #89) padahal isinya identik.
     */
    public function test_ejaan_dan_pemisah_yang_beda_tetap_satu_paket(): void
    {
        $this->import(
            $this->extraction([
                '_media_id' => 'ejaan1',
                'airline' => 'Saudia, Garuda Indonesia',
                'hotel_madinah' => ['raw_name' => 'Qashr Al Anshar', 'nights' => 3],
            ]),
            $this->extraction([
                '_media_id' => 'ejaan2',
                'airline' => 'Saudia / Garuda Indonesia',
                'hotel_madinah' => ['raw_name' => 'Qasr Al Anshar', 'nights' => 3],
            ]),
        );

        $this->assertSame(1, Package::count(), 'ejaan hotel & pemisah maskapai bukan paket berbeda');
    }

    /** Flyer paling sering tidak menulis kota berangkat karena Jakarta dianggap tahu. */
    public function test_kota_kosong_jadi_jakarta(): void
    {
        $this->import($this->extraction(['departure_city' => null]));

        $this->assertSame('Jakarta', Package::sole()->departure_city);
    }

    /** "9 hari" inklusif: berangkat 15 Agustus, pulang 23 Agustus. */
    public function test_tanggal_pulang_dihitung_dari_durasi(): void
    {
        $package = new Package(['departure_date' => '2026-08-15', 'duration_days' => 9]);

        $this->assertSame('2026-08-23', $package->returnDate()->toDateString());
        // Nama bulannya ikut locale aplikasi — yang diuji bentuk rentangnya.
        $this->assertSame('15 Aug – 23 Aug 2026', $package->dateLabel());
        $this->assertNull((new Package(['duration_days' => 9]))->returnDate());
    }

    /**
     * Tanpa tanggal paket tidak bisa dicari maupun diurut: ditolak. Tapi bukan
     * lewat ambang keberangkatan (itu vonis permanen + mengecualikan postnya),
     * melainkan lewat syarat kelengkapan yang postnya dibiarkan bisa dicoba lagi.
     */
    public function test_tanpa_tanggal_ditolak_tapi_postnya_tidak_dikecualikan(): void
    {
        config(['umroh.min_departure' => '2026-08-01']);

        $this->import($this->extraction([
            '_media_id' => 'notgl', 'departure_date' => null, '_needs_review' => true,
        ]));

        $this->assertSame(0, Package::count());
        $this->assertDatabaseMissing('excluded_posts', ['media_id' => 'notgl']);
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

    /** Satu keberangkatan dalam `departures`, dipakai di test flyer jadwal. */
    private function departure(array $override = []): array
    {
        return array_merge([
            'departure_date' => null,
            'date_certainty' => 'exact',
            'duration_days' => null,
            'price_tiers' => [],
            'airline' => null,
            'extension' => 'unknown',
            'hotel_makkah' => null,
            'hotel_madinah' => null,
        ], $override);
    }

    /**
     * Flyer jadwal: satu gambar, belasan keberangkatan. Semuanya jadi barisnya
     * sendiri — sebelum `departures` ada cuma yang paling menonjol yang masuk dan
     * sisanya hilang, padahal justru di situ jadwalnya paling banyak.
     */
    public function test_satu_gambar_jadwal_jadi_beberapa_paket(): void
    {
        $harga = fn (int $amount) => [
            ['occupancy' => 'quad', 'amount' => $amount, 'currency' => 'IDR', 'is_starting_from' => false],
        ];

        $this->import($this->extraction([
            '_media_id' => 'jadwal1',
            '_useful_images' => ['1.jpg'],
            'departure_date' => '2026-08-04',
            'duration_days' => 9,
            'departures' => [
                $this->departure(['departure_date' => '2026-08-04', 'duration_days' => 9, 'price_tiers' => $harga(31900000)]),
                $this->departure(['departure_date' => '2026-08-13', 'duration_days' => 13, 'price_tiers' => $harga(34900000)]),
                // Tanpa harga sendiri = pakai harga tingkat atas, bukan ditolak.
                $this->departure(['departure_date' => '2026-08-16', 'duration_days' => 9]),
            ],
        ]));

        $this->assertSame(3, Package::count());
        $this->assertSame([0, 1, 2], Package::orderBy('offer_index')->pluck('offer_index')->all());
        $this->assertSame(
            ['2026-08-04' => 31900000, '2026-08-13' => 34900000, '2026-08-16' => 25900000],
            Package::orderBy('offer_index')->get()
                ->mapWithKeys(fn ($p) => [$p->departure_date->toDateString() => (int) $p->price_quad])->all(),
        );
        $this->assertSame([9, 13, 9], Package::orderBy('offer_index')->pluck('duration_days')->all());
        // Semuanya dari gambar yang sama: satu flyer, satu file di disk flyers.
        $this->assertSame([1], Package::pluck('flyer_index')->unique()->values()->all());
        // Field yang tidak pernah ditulis ulang per baris tetap diwarisi.
        $this->assertSame(['Saudia'], Package::pluck('airline')->unique()->all());
    }

    /**
     * Sebagian keberangkatan sudah lewat ambang, sebagian belum: yang lewat cuma
     * dilewat barisnya. Postnya TIDAK dikecualikan — kalau iya, jadwal yang masih
     * berlaku di gambar yang sama ikut hilang selamanya.
     */
    public function test_jadwal_yang_sebagian_lewat_ambang_tidak_mengecualikan_postnya(): void
    {
        config(['umroh.min_departure' => '2026-08-01']);

        $this->import($this->extraction([
            '_media_id' => 'jadwal2',
            'departure_date' => '2026-07-10',
            'departures' => [
                $this->departure(['departure_date' => '2026-07-10']),
                $this->departure(['departure_date' => '2026-09-10']),
            ],
        ]));

        $this->assertSame(1, Package::count(), 'cuma keberangkatan setelah ambang yang masuk');
        $this->assertSame('2026-09-10', Package::first()->departure_date->toDateString());
        $this->assertDatabaseCount('excluded_posts', 0);
    }

    /** Semua keberangkatannya sudah lewat = postnya memang tidak berguna lagi. */
    public function test_jadwal_yang_semua_keberangkatannya_lewat_tetap_dikecualikan(): void
    {
        config(['umroh.min_departure' => '2026-08-01']);

        $this->import($this->extraction([
            '_media_id' => 'jadwal3',
            'departure_date' => '2026-07-10',
            'departures' => [
                $this->departure(['departure_date' => '2026-07-10']),
                $this->departure(['departure_date' => '2026-07-20']),
            ],
        ]));

        $this->assertSame(0, Package::count());
        $this->assertDatabaseHas('excluded_posts', ['media_id' => 'jadwal3', 'reason' => 'sebelum_ambang']);
    }

    /** Import ulang file yang sama tidak menggandakan barisnya per keberangkatan. */
    public function test_import_ulang_jadwal_tidak_menggandakan_baris(): void
    {
        $doc = $this->extraction([
            '_media_id' => 'jadwal4',
            'departures' => [
                $this->departure(['departure_date' => '2026-09-14']),
                $this->departure(['departure_date' => '2026-10-20']),
            ],
        ]);

        $this->import($doc);
        $this->import($doc);

        $this->assertSame(2, Package::count());
    }

    /**
     * Flyer yang jadi paket pindah dari tempat singgah (storage/raw) ke disk
     * `flyers`; slide lain dari carousel yang sama tetap di raw. Kalau raw-nya
     * tidak ikut dihapus, "pindah ke s3" cuma jadi penggandaan byte.
     */
    public function test_flyer_paket_dipindah_ke_disk_flyers(): void
    {
        Storage::fake(Package::FLYER_DISK);

        $raw = storage_path('raw/agen_a/promo1');
        File::ensureDirectoryExists($raw);
        file_put_contents("$raw/1.jpg", 'flyer-bytes');
        file_put_contents("$raw/2.jpg", 'foto-suasana');

        $this->import($this->extraction(['_media_id' => 'promo1', '_useful_images' => ['1.jpg']]));

        Storage::disk(Package::FLYER_DISK)->assertExists('promo1/1.jpg');
        $this->assertSame('flyer-bytes', Storage::disk(Package::FLYER_DISK)->get('promo1/1.jpg'));
        $this->assertFileDoesNotExist("$raw/1.jpg", 'raw flyer wajib dihapus setelah pindah');
        $this->assertFileExists("$raw/2.jpg", 'slide non-paket bukan urusan promosi');

        File::deleteDirectory(storage_path('raw/agen_a'));
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
