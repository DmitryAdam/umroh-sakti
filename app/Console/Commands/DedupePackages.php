<?php

namespace App\Console\Commands;

use App\Models\Package;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Menyatukan baris paket yang kembar dan yang lolos duluan karena aturan
 * dedup-nya masih longgar: fold yang belum membuang "setaraf"/bintang/isi
 * kurung, dan kunci yang menuntut maskapai sama persis padahal salah satu
 * ekstraksinya gagal membacanya (#1069 vs #1473).
 *
 * Dua langkah, dan langkah pertama wajib: `dedup_key` seluruh baris dihitung
 * ulang lebih dulu, kalau tidak baris lama tidak akan pernah ketemu pasangannya
 * dan dupe-nya lahir lagi di import berikutnya.
 *
 * Yang kalah dicatat sebagai repost di yang menang lalu barisnya dihapus.
 * Filenya tidak disentuh kecuali flyer yang tidak dipakai baris lain: hasil
 * ekstraksinya sudah di-prune, dan kalau masih ada, import berikutnya akan
 * mendarat sebagai repost di baris yang menang.
 */
class DedupePackages extends Command
{
    protected $signature = 'packages:dedupe {--dry-run : tampilkan saja, jangan ubah apa pun}';

    protected $description = 'Hitung ulang dedup_key lalu satukan baris paket yang kembar';

    public function handle(): int
    {
        $kering = (bool) $this->option('dry-run');

        // Satu query, lalu semuanya dicocokkan di memori. Versi yang mencari
        // pasangannya lewat query per baris butuh ~2 query x 1.616 baris dan tidak
        // selesai dalam 10 menit ke MySQL yang jauh.
        $semua = Package::all();
        $berubah = 0;

        foreach ($semua as $p) {
            $key = Package::dedupKey(
                $p->departure_date?->toDateString(),
                $p->hotel_makkah, $p->hotel_madinah, $p->airline, $p->duration_days,
            );

            if ($key === $p->dedup_key) {
                continue;
            }

            $p->dedup_key = $key;
            $berubah++;

            if (! $kering) {
                DB::table('packages')->where('id', $p->id)->update(['dedup_key' => $key]);
            }
        }

        $this->line("dedup_key dihitung ulang: $berubah berubah".($kering ? ' (tidak ditulis)' : ''));

        $digabung = 0;

        // Yang kuncinya paling terisi bertahan, seri -> id terkecil. Diurut sekali
        // di depan supaya baris pertama tiap tanggal selalu si pemenang.
        $perTanggal = $semua
            ->sortBy(fn (Package $p) => [-$this->isi($p), $p->id])
            ->groupBy(fn (Package $p) => explode('|', (string) $p->dedup_key)[0]);

        foreach ($perTanggal as $tanggal => $baris) {
            if ($tanggal === '-') {
                continue;
            }

            /** @var list<Package> $menangkan */
            $menangkan = [];

            foreach ($baris as $p) {
                foreach ($menangkan as $menang) {
                    if (! Package::sepadan((string) $p->dedup_key, (string) $menang->dedup_key)) {
                        continue;
                    }

                    $this->line("  #{$p->id} ({$p->source_account}) -> #{$menang->id} :: {$p->dedup_key}");
                    $digabung++;
                    $kering || $this->gabung($p, $menang);

                    continue 2;
                }

                $menangkan[] = $p;
            }
        }

        $this->info("$digabung baris digabung".($kering ? ' (dry-run, tidak ada yang diubah)' : ''));

        return self::SUCCESS;
    }

    /** Berapa bagian kunci yang benar-benar terisi. */
    private function isi(Package $p): int
    {
        return count(array_filter(explode('|', (string) $p->dedup_key), fn ($x) => $x !== '-'));
    }

    private function gabung(Package $kalah, Package $menang): void
    {
        $menang->addRepost(
            $kalah->media_id, $kalah->source_account,
            $kalah->source_permalink, $kalah->source_posted_at?->toDateTimeString(),
        );

        foreach ($kalah->reposts ?? [] as $r) {
            $menang->addRepost($r['media_id'] ?? null, $r['account'] ?? null, $r['permalink'] ?? null, $r['posted_at'] ?? null);
        }

        $this->hapusFlyerYatim($kalah);
        $kalah->delete();
    }

    /**
     * Flyer baris yang kalah cuma boleh dihapus kalau tidak ada baris lain dari
     * gambar yang sama — satu flyer jadwal bisa jadi beberapa baris (offer_index),
     * dan file itu dipakai bersama.
     */
    private function hapusFlyerYatim(Package $p): void
    {
        $dipakaiLain = Package::where('media_id', $p->media_id)
            ->where('flyer_index', $p->flyer_index)
            ->whereKeyNot($p->id)
            ->exists();

        if (! $dipakaiLain && $path = $p->flyerPath()) {
            Storage::disk(Package::FLYER_DISK)->delete($path);
        }
    }
}
