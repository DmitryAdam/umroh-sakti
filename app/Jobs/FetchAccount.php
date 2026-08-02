<?php

namespace App\Jobs;

use App\Models\SourceAccount;
use App\Support\PipelineLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Throwable;

/**
 * Fetch satu akun lewat probe.php (satu-satunya yang menyentuh Graph API).
 *
 * Rate limit Graph API itu tingkat app — semua request numpuk di satu token —
 * jadi seluruh job fetch dipaksa lewat satu lock, berapa pun jumlah workernya.
 * Ini pengganti antrian file storage/fetch_queue.json di probe.php fetchall.
 *
 * Antrian `ig` terpisah dari `ai`: fetch yang antri karena rate limit tidak boleh
 * menahan ekstraksi post yang sudah ke-download, dan akun baru yang masuk tidak
 * menghentikan konversi yang sedang jalan.
 */
class FetchAccount implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    /**
     * Batas waktu, bukan hitungan percobaan. Tiap akun yang antri di lock `ig-fetch`
     * kena release — dan release menaikkan `attempts`. Dengan `tries` biasa, akun
     * ke-4 dst mati `MaxAttemptsExceeded` sebelum dapat giliran, bukan karena error.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function __construct(
        public SourceAccount $account,
        public int $limit = 50,
    ) {
        $this->onQueue('ig');
    }

    public function middleware(): array
    {
        // releaseAfter = jeda antar akun. expireAfter supaya lock ga nyangkut
        // selamanya kalau workernya mati di tengah fetch.
        return [
            (new WithoutOverlapping('ig-fetch'))
                ->releaseAfter(10)
                ->expireAfter($this->timeout),
        ];
    }

    public function handle(): void
    {
        // Diblokir setelah job ini masuk antrian — batalkan di sini, bukan cuma di
        // pemanggilnya: crawl, tombol per-akun, dan retry semuanya lewat sini.
        if ($this->account->isBlocked()) {
            PipelineLog::write('ig', "== lewati @{$this->account->username}: ada di blocklist");

            return;
        }

        PipelineLog::write('ig', "== fetch @{$this->account->username} (limit {$this->limit})");

        // stdout probe.php diteruskan apa adanya: request ke graph.facebook.com,
        // tiap gambar yang di-download, dan post yang dilewat karena sudah dikecualikan.
        try {
            $result = Process::timeout($this->timeout)->run([
                PHP_BINARY,
                base_path('probe.php'),
                'fetch',
                $this->account->username,
                "--limit={$this->limit}",
            ], PipelineLog::stream('ig'));
        } catch (ProcessSignaledException $e) {
            // Ctrl+C / pkill kena satu grup proses — probe.php ikut mati, bukan fetchnya
            // yang salah. Jangan lewat fail(): itu menulis `last_error` dan akunnya
            // kelihatan gagal di /akun padahal cuma workernya dihentikan.
            PipelineLog::write('ig', "@{$this->account->username}: dihentikan (signal {$e->getSignal()}), antri lagi");
            $this->release();

            return;
        }

        if ($result->failed()) {
            $error = trim($result->errorOutput() ?: $result->output());

            // Rate limit bukan kesalahan akun ini — tunggu, jangan tandai gagal.
            // Semua app sudah dicoba sebelum probe.php melempar ini (lihat igCreds).
            //
            // `last_error` sengaja TIDAK ditulis: job ini masih antri (release 300),
            // jadi ini keadaan menunggu, bukan vonis. Kalau distempel, akun yang
            // fetch terakhirnya berhasil tetap memajang "gagal: semua app kena rate
            // limit" — dan stempelnya nyangkut selamanya begitu job yang menunggu itu
            // dibuang (tombol batal di panel) atau retryUntil 2 jam habis, karena yang
            // menghapusnya cuma fetch berikutnya yang berhasil. Jejaknya di
            // storage/pipeline.jsonl, tempat keadaan sementara memang dicatat.
            if (str_contains($error, 'rate limit')) {
                PipelineLog::write('ig', "@{$this->account->username}: rate limit, coba lagi 5 menit");
                $this->release(300);

                return;
            }

            // Akun personal (bukan Business/Creator) tidak terlihat business_discovery
            // sama sekali, dan itu keadaan yang cuma bisa diubah pemilik akunnya —
            // tidak ada scope atau app review yang membukanya. Diulang tiap putaran
            // scrap berarti kegagalan yang sudah pasti, jadi blokir sekalian; postnya
            // masuk lewat /suggestions atau chrome extension yang tidak lewat Graph.
            // Username salah ketik kena aturan yang sama, dan itu memang yang dimau.
            //
            // Aman dari salah blokir karena probe.php sudah mencoba SEMUA app slot
            // sebelum melempar ini (lihat cmdFetch): `Invalid user id` juga muncul
            // saat IG_USER_ID salah pasang dengan token, tapi itu per-slot — kalau
            // ketiganya menjawab sama, yang salah akunnya.
            if (str_contains($error, 'Invalid user id')) {
                PipelineLog::write('ig', "@{$this->account->username}: tidak terlihat business_discovery (personal/tidak ada), diblokir");
                $this->account->update(['status' => 'blocked']);
            }

            // Bukan rate limit = username salah, token mati, atau response ditolak.
            // Diulang pun hasilnya sama, dan retryUntil 2 jam berarti stacktrace
            // penuh tiap 12 detik. Matikan sekali, biar kelihatan di failed_jobs.
            // last_error diisi oleh failed(), yang ikut kepanggil oleh fail().
            $this->fail(new RuntimeException("fetch @{$this->account->username}: $error"));

            // fail() menandai job gagal tapi TIDAK menghentikan handle(). Tanpa
            // return, baris di bawah tetap jalan: akun yang gagal ikut distempel
            // last_fetched_at dan di /akun kelihatan baru saja berhasil di-scrap.
            return;
        }

        $this->account->update([
            'last_fetched_at' => now(),
            'last_error' => null,
        ] + $this->account->profileFromDisk());

        ExtractPending::dispatch();
    }

    /**
     * Satu-satunya tempat `last_error` diisi saat gagal.
     *
     * Dipanggil framework untuk SEMUA kegagalan, bukan cuma `fail()` di handle():
     * `database is locked` dari SQLite, timeout Process, dan exception liar lainnya
     * tidak pernah lewat cabang if di atas — 19 kegagalan pertama semuanya jenis itu
     * dan tidak meninggalkan jejak apa pun di halaman akun.
     *
     * ponytail: kalau penyebabnya justru DB terkunci, update ini bisa ikut gagal dan
     * errornya hilang. busy_timeout 10s di config/database.php sudah menutup mayoritas;
     * kalau masih bocor, tulis ke storage/pipeline.jsonl saja, bukan ke DB.
     */
    public function failed(?Throwable $e): void
    {
        $this->account->update([
            'last_error' => Str::limit(trim((string) $e?->getMessage()) ?: 'gagal tanpa pesan', 300),
        ]);
    }
}
