<?php

namespace App\Http\Controllers;

use App\Console\Commands\QueueWork;
use App\Models\Package;
use App\Models\SourceAccount;
use App\Support\PipelineLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Progress pipeline + tombol batal, biar tidak perlu bolak-balik ke terminal.
 * Tidak ada tombol "jalankan": job diantrikan dari halaman akun (`scrap`) atau
 * `packages:crawl`, dan `php artisan queue:work` yang mengerjakan semuanya.
 * Dikunci ke env local — ini alat kerja, bukan fitur publik.
 */
class PipelineController extends Controller
{
    /** Antrian yang dikenal — sama dengan yang di-spawn `QueueWork`. */
    public const QUEUES = ['ig', 'ai', 'db'];

    /**
     * Buang isi antrian + daftar gagal. Tanpa `$queue` semuanya; dengan `$queue`
     * cuma antrian itu, jadi fetch yang macet bisa dibuang tanpa ikut membunuh
     * ekstraksi yang sedang jalan.
     *
     * Job yang sedang dikerjakan tetap selesai: worker sudah memegangnya di memori,
     * barisnya (yang `reserved_at`-nya terisi) cuma dihapus lebih awal — `delete()`
     * worker sesudahnya jadi no-op. Yang benar-benar dibatalkan cuma yang belum diambil.
     *
     * `cache_locks` ikut dibersihkan: lock `unique` milik job yang dibuang tidak
     * ada yang melepas, dan sampai `uniqueFor` habis job sejenis berikutnya
     * kelihatan hilang diam-diam. Dibersihkan seluruhnya juga saat membatalkan satu
     * antrian — kuncinya tidak menyimpan nama antrian, jadi tidak bisa dipilih.
     */
    public function clear(?string $queue = null): JsonResponse
    {
        abort_unless($queue === null || in_array($queue, self::QUEUES, true), 404);

        $antri = DB::table('jobs')->when($queue, fn ($q) => $q->where('queue', $queue))->delete();
        $gagal = DB::table('failed_jobs')->when($queue, fn ($q) => $q->where('queue', $queue))->delete();
        DB::table('cache_locks')->delete();

        PipelineLog::write($queue ?? 'run',
            '== batalkan antrian '.($queue ?? 'semua').": {$antri} job antri + {$gagal} job gagal dibuang");

        return response()->json($this->numbers());
    }

    /**
     * Kembalikan job gagal ke antriannya. Kebalikan `clear()`: yang itu membuang,
     * yang ini mencoba lagi — dan mayoritas isi `failed_jobs` di sini memang layak
     * dicoba lagi (worker di-Ctrl+C, `database is locked`, model timeout).
     *
     * Bungkus tipis `queue:retry`, bukan insert manual ke `jobs`: payload gagal punya
     * uuid + hitungan percobaan yang harus di-reset, dan menulisnya sendiri berarti
     * menyalin logika framework yang sudah ada.
     *
     * Lock `unique` tidak dicek ulang saat retry (payloadnya didorong langsung, bukan
     * lewat dispatcher), jadi `cache_locks` sengaja tidak disentuh di sini — beda
     * dengan `clear()`, yang justru meninggalkan lock tanpa pemilik.
     */
    public function retry(?string $queue = null): JsonResponse
    {
        abort_unless($queue === null || in_array($queue, self::QUEUES, true), 404);

        $jumlah = DB::table('failed_jobs')->when($queue, fn ($q) => $q->where('queue', $queue))->count();
        Artisan::call('queue:retry', $queue ? ['--queue' => $queue] : ['id' => ['all']]);

        PipelineLog::write($queue ?? 'run',
            '== ulangi antrian '.($queue ?? 'semua').": {$jumlah} job gagal dikembalikan ke antrian");

        return response()->json($this->numbers());
    }

    /**
     * Hentikan worker. Bukan `pkill` dari web: argv anak
     * (`php artisan queue:work --queue=ig`) identik lintas project di server yang
     * sama, jadi pola apa pun yang cocok dengan worker kita juga membunuh worker app
     * tetangga. Yang ditulis cuma flag; loop induk `QueueWork` yang membacanya lalu
     * mengirim SIGTERM ke anaknya sendiri.
     *
     * Beda dengan `clear()`: itu membuang job, ini menghentikan yang mengerjakan.
     * Antriannya tetap utuh dan jalan lagi begitu `queue:work` dinyalakan.
     *
     * TTL 5 menit supaya flag yang ditekan saat tidak ada worker tidak menunggu
     * selamanya; induk juga membuangnya sendiri saat start.
     */
    public function stop(): JsonResponse
    {
        Cache::put(QueueWork::STOP, true, 300);
        PipelineLog::write('run', '== stop worker diminta dari panel');

        return response()->json($this->numbers());
    }

    /**
     * Jumlah worker paralel untuk satu antrian. Yang ditulis cuma setelannya (cache,
     * sama seperti flag STOP); loop induk `QueueWork` yang menambah/mengurangi anak
     * sekali per detik, jadi worker tidak perlu di-restart.
     *
     * 0 = antriannya dipause: jobnya tetap antri, cuma tidak ada yang mengambil.
     * Batas atasnya `MAX_WORKERS` — yang jebol duluan bukan CPU-nya: `ig` paralel kena
     * rate limit Graph #4, `ai` paralel bikin call vision saling menggantung di router,
     * dan `db` itu SQLite yang cuma boleh satu penulis.
     */
    public function workers(Request $request, string $queue): JsonResponse
    {
        abort_unless(in_array($queue, self::QUEUES, true), 404);

        $jumlah = max(0, min(QueueWork::MAX_WORKERS, (int) $request->input('jumlah')));
        Cache::forever(QueueWork::WORKERS, [...QueueWork::jumlah(), $queue => $jumlah]);
        PipelineLog::write('run', "== worker $queue diatur jadi $jumlah");

        return response()->json($this->numbers());
    }

    public function status(): JsonResponse
    {
        return response()->json($this->numbers());
    }

    /**
     * Semua angka dihitung dari sumbernya langsung — tidak ada tabel progress
     * yang perlu dijaga tetap sinkron.
     *
     * Dua satuan yang sengaja dipisah, karena kalau dicampur corongnya bohong:
     * **post** (satu postingan IG) dan **paket** (satu gambar penawaran). Satu
     * carousel = satu post tapi bisa jadi beberapa hasil ekstraksi dan beberapa
     * paket, jadi `paket > post` itu wajar dan bukan tanda dobel.
     *
     * @return array<string, mixed>
     */
    private function numbers(): array
    {
        // Job yang sedang dikerjakan tetap punya barisnya di `jobs`, cuma `reserved_at`-nya
        // terisi — dipisah biar "3 antri" tidak sebenarnya berarti "2 antri, 1 jalan".
        $antrianPer = DB::table('jobs')->selectRaw(
            'queue, sum(reserved_at is null) as antri, sum(reserved_at is not null) as jalan',
        )->groupBy('queue')->get()->keyBy('queue');
        $gagalPer = DB::table('failed_jobs')->selectRaw('queue, count(*) as total')
            ->groupBy('queue')->pluck('total', 'queue');

        // Angka "3 gagal" tanpa sebabnya cuma bikin buka sqlite. Yang ditarik cuma
        // kegagalan TERAKHIR per antrian (max(id)) — kolom `exception` itu stacktrace
        // penuh, jadi menarik semua barisnya berarti ratusan KB tiap polling 2 detik.
        // Baris pertama sudah memuat kelas + pesannya; sisanya (" in /path/file.php:12"
        // dan stacktrace) dibuang.
        $pesanGagal = DB::table('failed_jobs')
            ->whereIn('id', fn ($q) => $q->from('failed_jobs')->selectRaw('max(id)')->groupBy('queue'))
            ->pluck('exception', 'queue')
            ->map(fn ($e) => Str::limit(trim(Str::before(Str::before($e, "\n"), ' in /')), 200));
        $antrian = (int) $antrianPer->sum('antri');

        // Satu glob per direktori, lalu dibandingkan sebagai himpunan. Cek per-post
        // (glob di dalam loop) berarti ratusan syscall tiap polling 2 detik.
        $raw = array_map('basename', array_map('dirname', glob(storage_path('raw/*/*/post.json')) ?: []));
        $hasil = glob(storage_path('extracted/*.json')) ?: [];

        // Post yang ditolak filenya dihapus, jadi yang mengingat "pernah diunduh"
        // tinggal barisnya di excluded_posts. Sekalian lebih jujur dari hitungan
        // folder: barisnya ikut hilang kalau DB di-reset, foldernya tidak.
        $dikecualikan = DB::table('excluded_posts')->count();

        // "17890-2.json" dan "17890.json" sama-sama post 17890: carousel dipecah
        // per gambar, jadi nama filenya bukan satuan post.
        $dibaca = array_unique(array_map(
            fn ($f) => explode('-', pathinfo($f, PATHINFO_FILENAME))[0], $hasil,
        ));

        $status = Package::query()->selectRaw('status, count(*) as total')
            ->groupBy('status')->pluck('total', 'status');

        $sekarang = PipelineLog::current();
        $worker = QueueWork::jumlah();

        return [
            // Baris terakhir per antrian: ig sedang fetch siapa, ai sedang mengekstrak
            // apa, db sedang mengimpor apa. Sumbernya satu — storage/pipeline.jsonl.
            'sekarang' => $sekarang,
            // Jejak detail: request ke graph.facebook.com, tiap gambar yang di-download,
            // request ke model vision & penyusun, nama file yang ditulis.
            'log' => PipelineLog::tail(80),

            'akun' => SourceAccount::approved()->count(),
            'terfetch' => SourceAccount::approved()->whereNotNull('last_fetched_at')->count(),
            'akun_gagal' => SourceAccount::approved()->whereNotNull('last_error')->count(),

            // Corong satuan POST.
            // Diunduh = yang masih di raw + yang sudah dikecualikan. Tanpa yang kedua,
            // angkanya turun tiap import (file post buangan dihapus) dan kelihatan
            // seperti post hilang.
            'post_diunduh' => count($raw) + $dikecualikan,
            'post_menunggu' => count(array_diff($raw, $dibaca)),
            'post_dibaca' => count(array_intersect($raw, $dibaca)) + $dikecualikan,
            'post_dikecualikan' => $dikecualikan,
            'alasan' => DB::table('excluded_posts')->selectRaw('reason, count(*) as total')
                ->groupBy('reason')->pluck('total', 'reason'),

            // Corong satuan PAKET.
            'hasil_ekstraksi' => count($hasil),
            'paket' => (int) $status->sum(),
            'review' => (int) ($status['review'] ?? 0),
            'published' => (int) ($status['published'] ?? 0),

            'antrian' => $antrian,
            'antri_ig' => (int) ($antrianPer['ig']->antri ?? 0),
            'antri_ai' => (int) ($antrianPer['ai']->antri ?? 0),
            'antri_db' => (int) ($antrianPer['db']->antri ?? 0),
            'gagal' => (int) $gagalPer->sum(),
            'jalan' => $antrian > 0,

            // Satu blok per antrian: yang dipakai panel buat kartu + tombol batalnya.
            // `worker` itu SETELAN (berapa yang dimau), bukan berapa proses yang benar-benar
            // hidup — induknya menyusul dalam ≤1 detik, dan tanpa `queue:work` jalan
            // angka itu cuma niat.
            'antrian_per' => collect(self::QUEUES)->mapWithKeys(fn ($q) => [$q => [
                'antri' => (int) ($antrianPer[$q]->antri ?? 0),
                'proses' => (int) ($antrianPer[$q]->jalan ?? 0),
                'gagal' => (int) ($gagalPer[$q] ?? 0),
                'pesan_gagal' => $pesanGagal[$q] ?? null,
                'sekarang' => $sekarang[$q] ?? null,
                'worker' => $worker[$q],
            ]]),
            'max_worker' => QueueWork::MAX_WORKERS,
        ];
    }
}
