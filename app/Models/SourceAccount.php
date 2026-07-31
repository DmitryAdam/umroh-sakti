<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class SourceAccount extends Model
{
    protected $guarded = [];

    protected $casts = ['last_fetched_at' => 'datetime'];

    /** Kolom profil yang diisi dari storage/profiles/{username}.json (ditulis probe.php). */
    public const PROFILE_COLUMNS = ['full_name', 'followers_count', 'follows_count', 'media_count'];

    /**
     * Profil terakhir yang ditulis probe.php, siap dipakai `update()`.
     *
     * Filenya tidak ada (belum pernah di-scrap, atau response tanpa field profil)
     * = array kosong, jadi kolomnya tidak disentuh — bukan dikosongkan.
     *
     * @return array<string, string|int|null>
     */
    public function profileFromDisk(): array
    {
        $path = storage_path("profiles/{$this->username}.json");
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return array_intersect_key(
            is_array($data) ? $data : [],
            array_flip(static::PROFILE_COLUMNS),
        );
    }

    public function scopeApproved($q)
    {
        return $q->where('status', 'approved');
    }

    public function scopeBlocked($q)
    {
        return $q->where('status', 'blocked');
    }

    /** Usulan dari peran `user` yang belum di-approve admin. Tidak pernah di-scrap. */
    public function scopePending($q)
    {
        return $q->where('status', 'pending');
    }

    public function isBlocked(): bool
    {
        return $this->status === 'blocked';
    }

    /**
     * Gagal = percobaan terakhir error DAN akun ini belum menghasilkan apa pun.
     *
     * `last_error` sendirian bukan vonis: akun yang pernah berhasil di-scrap — atau
     * yang sudah punya post/paket/post-ditolak — cuma kena satu percobaan yang
     * meleset (rate limit, SQLite lock, timeout) dan datanya tetap ada.
     * Menghitungnya gagal bikin angka di kepala halaman (25 dari 189) menyuruh
     * menindak yang tidak perlu ditindak. Yang butuh tindakan cuma yang error DAN
     * kosong beneran.
     *
     * @param  int  $isi  post terunduh + paket + post dikecualikan milik akun ini
     */
    public function gagal(int $isi = 0): bool
    {
        return $this->last_error !== null && $this->last_fetched_at === null && $isi === 0;
    }

    /**
     * Lepas semua post akun ini dari `excluded_posts` — bahan "scrap paksa".
     *
     * Baris itu satu-satunya yang menahan fetch mengunduh ulang post yang pernah
     * ditolak (`probe.php` membacanya lewat PDO). Filenya tidak perlu disentuh:
     * post yang ditolak memang sudah dihapus rawnya.
     *
     * @return int post yang dilepas
     */
    public function lupakanPenolakan(): int
    {
        return DB::table('excluded_posts')->where('source_account', $this->username)->delete();
    }

    /**
     * Hapus semua jejak data akun ini: paket + flyer + raw + hasil ekstraksi.
     *
     * Dipakai blokir maupun hapus — dua-duanya berarti "jangan tampilkan lagi",
     * dan menghapus barisnya saja tidak cukup. Yang menghidupkannya kembali ada
     * dua: `storage/extracted/*.json` yang dipungut `packages:import` jadi baris
     * baru, dan `storage/raw` yang dipindai `ExtractPending` lalu dikirim ulang
     * ke model — dua-duanya jalan tanpa melihat tabel akun.
     *
     * @return int paket yang dihapus
     */
    public function purge(): int
    {
        // Media id dari dua sumber: raw (post yang belum jadi paket, tapi masih
        // akan dipungut pemindai) dan baris paketnya sendiri (rawnya bisa sudah
        // di-prune, hasil ekstraksinya belum tentu).
        $mediaIds = array_map('basename', glob(storage_path("raw/{$this->username}/*"), GLOB_ONLYDIR) ?: []);

        $packages = Package::where('source_account', $this->username)->get();

        foreach ($packages as $package) {
            $package->deleteSources();
            $package->delete();
            $mediaIds[] = $package->media_id;
        }

        // Carousel dipecah per gambar ("17890-2.json"), jadi yang dihapus polanya,
        // bukan satu file — deleteSources() cuma menangani bentuk tanpa sufiks.
        foreach (array_unique(array_filter($mediaIds)) as $id) {
            foreach (glob(storage_path("extracted/$id{.json,-*.json}"), GLOB_BRACE) ?: [] as $file) {
                File::delete($file);
            }
        }

        File::deleteDirectory(storage_path("raw/{$this->username}"));

        return $packages->count();
    }

    /**
     * Hapus total: barisnya, datanya, profilnya, dan catatan post yang dikecualikan.
     *
     * Bedanya dengan blokir — baris `excluded_posts` ikut hilang, jadi kalau
     * username ini dimasukkan lagi nanti dia di-scrap dari nol. Blokir menyimpan
     * barisnya justru supaya input yang sama ditolak.
     *
     * @return int paket yang dihapus
     */
    public function purgeAndDelete(): int
    {
        $paket = $this->purge();

        DB::table('excluded_posts')->where('source_account', $this->username)->delete();
        File::delete(glob(storage_path("profiles/{$this->username}.*")) ?: []);

        $this->delete();

        return $paket;
    }

    /**
     * Baris teks (accounts.txt atau textarea di /accounts) -> akun baru.
     * Sudah ada = tidak disentuh, status manual menang.
     *
     * `$extra` menimpa defaultnya: usulan dari peran `user` masuk sebagai
     * `['status' => 'pending', 'suggested_by' => <email>]` dan menunggu approval —
     * semua jalur crawl menyaring `approved`, jadi baris pending tidak pernah
     * membakar kuota Graph sendiri.
     *
     * @param  iterable<string>  $lines
     * @param  array<string, mixed>  $extra
     * @return list<string> username yang baru didaftarkan
     */
    public static function register(iterable $lines, array $extra = []): array
    {
        $new = [];
        foreach ($lines as $line) {
            $username = static::usernameOf($line);
            if ($username === null || static::where('username', $username)->exists()) {
                continue;
            }
            // $extra di kiri: `+` mempertahankan kunci sebelah kiri, jadi urutan
            // terbalik bikin `status` usulan diam-diam balik jadi approved.
            static::create($extra + ['username' => $username, 'status' => 'approved']);
            $new[] = $username;
        }

        return $new;
    }

    /** "https://www.instagram.com/foo/?x=1" atau "@foo" -> "foo". */
    public static function usernameOf(string $line): ?string
    {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            return null;
        }
        if (str_contains($line, 'instagram.com')) {
            $line = parse_url($line, PHP_URL_PATH) ?: '';
        }
        $line = trim($line, "/@ \t");

        return preg_match('/^[A-Za-z0-9._]+$/', $line) ? $line : null;
    }
}
