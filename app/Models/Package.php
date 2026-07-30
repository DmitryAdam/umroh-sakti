<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
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
     * `Y-m-d` diterima apa adanya; `Y-m` (bulannya saja) dinormalkan ke tanggal 1.
     * Dijaga di setter supaya jalur mana pun (import, tinker, worker yang masih
     * memegang kode lama) kena aturan yang sama.
     *
     * Tahun saja TIDAK diterima. Flyer haji sering cuma menulis "Berangkat Tahun
     * 2027" dan model menyalinnya apa adanya; cast `date` membaca "2027" sebagai
     * unix timestamp — 2027 detik — jadi barisnya masuk bertanggal 1970-01-01
     * 07:33, terurut paling awal dan lolos semua filter rentang.
     *
     * Bulan tanpa tanggal sengaja TIDAK dibuang: banyak flyer memang cuma menulis
     * "Maret 2027", dan tanggal 1 masih bisa diurut & difilter rentang. Yang
     * membedakannya dari tanggal pasti adalah `date_certainty` = month — jangan
     * pernah melabeli hasil normalisasi ini `exact`, angka tanggalnya bikinan kita.
     */
    public static function tanggal(mixed $value): ?string
    {
        $value = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) $value;

        return match (true) {
            (bool) preg_match('/^\d{4}-\d{2}-\d{2}/', $value) => $value,
            (bool) preg_match('/^\d{4}-\d{2}$/', $value) => "$value-01",
            default => null,
        };
    }

    /** Tanggal yang cuma bulan -> `month`, apa pun kata model. Lihat tanggal(). */
    public static function kepastian(mixed $value, ?string $certainty): string
    {
        return match (true) {
            self::tanggal($value) === null => 'unknown',
            (bool) preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $value) => $certainty ?: 'exact',
            default => 'month',
        };
    }

    public function setDepartureDateAttribute(mixed $value): void
    {
        $this->attributes['departure_date'] = self::tanggal($value);
    }

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
        return implode('|', [
            self::tanggal($departureDate) ?? '-',
            ...array_map(self::fold(...), [$hotelMakkah, $hotelMadinah, $airline]),
        ]);
    }

    /**
     * Nilai untuk kunci dedup. Transliterasi Arab-Latin di flyer tidak konsisten
     * dan pemisah daftar juga tidak: paket yang sama diposting dua kali oleh akun
     * yang sama sebagai "Qashr Al Anshar" + "Saudia, Garuda Indonesia" lalu
     * "Qasr Al Anshar" + "Saudia / Garuda Indonesia" — dulu jadi dua baris.
     *
     * Yang dibuang: tanda baca, spasi, kata sandang "al", dan huruf "h" (dh/kh/sh/th
     * dan -ah di akhir semuanya varian ejaan). Token sisanya diurut supaya "A, B"
     * sama dengan "B / A".
     *
     * ponytail: mengurutkan token berarti dua hotel yang katanya sama tapi
     * urutannya beda ikut menyatu. Belum pernah kejadian di data; kalau nanti
     * kejadian, hilangkan sort()-nya dan bandingkan sebagai himpunan bersorot.
     */
    private static function fold(?string $value): string
    {
        $tokens = preg_split('/[^a-z0-9]+/', mb_strtolower(trim((string) $value)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_filter(
            array_map(fn ($t) => str_replace('h', '', $t), $tokens),
            fn ($t) => $t !== '' && $t !== 'al',
        );
        sort($tokens);

        return implode('', $tokens) ?: '-';
    }

    /**
     * Hari terakhir paket. "9 hari" itu inklusif — berangkat 15 Agustus, pulang
     * 23 Agustus — jadi orang tidak perlu menghitung sendiri.
     */
    public function returnDate(): ?Carbon
    {
        return $this->departure_date && $this->duration_days
            ? $this->departure_date->copy()->addDays((int) $this->duration_days - 1)
            : null;
    }

    /** "03 – 11 Nov 2026", atau cuma tanggal berangkat kalau durasinya kosong. */
    public function dateLabel(string $month = 'M'): ?string
    {
        if (! $this->departure_date) {
            return null;
        }

        return ($pulang = $this->returnDate())
            ? $this->departure_date->translatedFormat("d $month").' – '.$pulang->translatedFormat("d $month Y")
            : $this->departure_date->translatedFormat("d $month Y");
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
     * Hapus raw + hasil ekstraksi seluruh post paket ini. Yang menjaga post ini
     * tidak ter-fetch & ter-extract lagi itu baris `excluded_posts`, bukan filenya
     * — jadi filenya tidak perlu disimpan di mana pun.
     */
    public function deleteSources(): void
    {
        foreach ($this->posts() as $post) {
            if (! $post['media_id']) {
                continue;
            }

            File::deleteDirectory(storage_path("raw/{$post['account']}/{$post['media_id']}"));
            File::delete(storage_path("extracted/{$post['media_id']}.json"));
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
