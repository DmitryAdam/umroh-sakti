<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;

/**
 * Satu paket = satu baris, dan semua komponennya ikut di baris ini: hotel,
 * fasilitas, dan daftar akun yang memposting ulang. Tidak ada relasi.
 */
class Package extends Model
{
    protected $guarded = [];

    /** Tier harga tetap: satu paket satu baris, tanpa tabel tier. */
    public const PRICE_COLUMNS = ['price_quad', 'price_triple', 'price_double', 'price_single'];

    public const FACILITY_CODES = ['visa', 'tiket', 'hotel', 'makan_3x', 'muthawif',
        'perlengkapan', 'handling', 'city_tour', 'asuransi'];

    /** Koreksi manusia atas hasil ekstraksi — bahan perbaikan prompt, bukan status publikasi. */
    public const REVIEW_VERDICTS = [
        'oke' => 'Oke, datanya benar',
        'bukan_paket' => 'Bukan penawaran paket',
        'data_salah' => 'Data salah baca',
        'foto_salah' => 'Foto yang dipajang salah',
        'kurang_data' => 'Ada data di flyer yang tidak terambil',
    ];

    protected $casts = [
        'departure_date' => 'date',
        'source_posted_at' => 'datetime',
        'extracted_at' => 'datetime',
        'raw_extraction' => 'array',
        'facilities' => 'array',
        'reposts' => 'array',
        'confidence' => 'float',
        'price_starting_from' => 'bool',
        'reviewed_at' => 'datetime',
    ];

    /**
     * Kunci dedup: satu paket yang diposting ulang puluhan agen harus menyatu
     * jadi satu baris. Sengaja TIDAK memakai source_account.
     *
     * ponytail: tanpa penanda travel, dua travel berbeda dengan tanggal + hotel
     * + maskapai yang sama akan menyatu. Tambahkan penanda PPIU ke kunci ini
     * begitu izinnya melekat ke source_accounts.
     */
    public static function dedupKey(
        ?string $departureDate, ?string $hotelMakkah,
        ?string $hotelMadinah, ?string $airline,
    ): string {
        return implode('|', array_map(
            fn ($v) => mb_strtolower(trim((string) $v)) ?: '-',
            [$departureDate, $hotelMakkah, $hotelMadinah, $airline],
        ));
    }

    /**
     * Tier yang terisi saja: ['quad' => 25900000, 'double' => 29000000].
     *
     * @return array<string, int>
     */
    public function prices(): array
    {
        $out = [];
        foreach (self::PRICE_COLUMNS as $column) {
            if ($this->$column !== null) {
                $out[substr($column, 6)] = (int) $this->$column;
            }
        }

        return $out;
    }

    /**
     * Semua post yang memuat paket ini: post asal ekstraksi dulu, lalu repostnya.
     *
     * @return array<int, array{media_id: ?string, account: ?string, permalink: ?string}>
     */
    public function posts(): array
    {
        return [
            [
                'media_id' => $this->media_id,
                'account' => $this->source_account,
                'permalink' => $this->source_permalink,
            ],
            ...array_values($this->reposts ?? []),
        ];
    }

    /**
     * Thumbnail flyer post asal, urut sesuai carousel aslinya. Yang keluar selalu
     * versi kecil — flyer full tidak pernah di-re-host, lihat FlyerThumbController.
     *
     * Di carousel biasanya cuma satu-dua gambar yang memuat detail paket, sisanya
     * foto suasana. probe.php sudah menandainya lewat `_useful_images`; kalau
     * tandanya belum ada (hasil ekstraksi lama), pajang semua.
     *
     * ponytail: cuma flyer post asal — repost tidak dipajang karena flyernya
     * gambar yang itu-itu juga (rebranding). Kalau raw post asal sudah dibuang,
     * kartunya tampil tanpa gambar.
     *
     * @return array<int, string>
     */
    public function flyers(): array
    {
        $files = glob(storage_path("raw/{$this->source_account}/{$this->media_id}/*.jpg")) ?: [];
        sort($files, SORT_NATURAL);

        $indexes = array_map(fn ($f) => (int) pathinfo($f, PATHINFO_FILENAME), $files);

        if ($useful = $this->raw_extraction['_useful_images'] ?? null) {
            $keep = array_map(fn ($f) => (int) pathinfo($f, PATHINFO_FILENAME), $useful);
            $indexes = array_values(array_intersect($indexes, $keep));
        }

        return array_map(
            fn ($i) => route('flyer', ['media' => $this->media_id, 'index' => $i]),
            $indexes,
        );
    }

    /**
     * Pindahkan raw + hasil ekstraksi seluruh post paket ini ke storage/trash.
     * Dipindah, bukan dihapus: keputusannya manual dan bisa salah. Sekaligus bikin
     * post ini tidak ikut ter-extract dan ter-import lagi di putaran berikutnya.
     */
    public function trashSources(): void
    {
        foreach ($this->posts() as $post) {
            if (! $post['media_id']) {
                continue;
            }

            $dest = storage_path("trash/{$post['account']}/{$post['media_id']}");
            $raw = storage_path("raw/{$post['account']}/{$post['media_id']}");
            $json = storage_path("extracted/{$post['media_id']}.json");

            // rename() gagal diam-diam kalau folder induk tujuan belum ada.
            File::ensureDirectoryExists(dirname($dest));

            is_dir($raw) ? File::moveDirectory($raw, $dest, overwrite: true) : File::ensureDirectoryExists($dest);

            if (is_file($json)) {
                File::move($json, "$dest/extracted.json");
            }
        }
    }

    /**
     * Harga di flyernya USD; kolomnya sudah dikonversi ke IDR saat import
     * (ImportExtractedPackages::toIdr). Angka rupiahnya hasil kurs, bukan
     * angka yang tertulis — jadi ditandai di UI.
     */
    public function convertedFromUsd(): bool
    {
        foreach ($this->raw_extraction['price_tiers'] ?? [] as $tier) {
            if (($tier['currency'] ?? 'IDR') === 'USD') {
                return true;
            }
        }

        return false;
    }

    /** Harga termurah, dipakai untuk sorting & facet harga. */
    public function lowestPrice(): ?int
    {
        $prices = $this->prices();

        return $prices ? min($prices) : null;
    }

    /** Di bawah BPIU Referensi Kemenag -> wajib warning, jangan disembunyikan. */
    public function isBelowReferencePrice(): bool
    {
        $low = $this->lowestPrice();

        return $low !== null && $low < (int) config('umroh.bpiu_reference');
    }

    public function scopePublished($q)
    {
        return $q->where('status', 'published');
    }

    public function scopeNeedsReview($q)
    {
        return $q->whereIn('status', ['draft', 'review']);
    }
}
