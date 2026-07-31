<?php

namespace App\Support;

use Closure;

/**
 * Jejak pipeline yang bisa dibaca sambil jalan: satu baris JSON per kejadian di
 * storage/pipeline.jsonl.
 *
 * File, bukan tabel: penulisnya beberapa proses worker sekaligus dan yang dibaca
 * cuma ekornya. `LOCK_EX` per append sudah cukup — baris pendek, tidak ada
 * read-modify-write.
 *
 * Isinya sengaja detail sampai nama file dan host tujuan request: sebagian besar
 * baris datang dari stdout probe.php apa adanya, diteruskan oleh job yang
 * memanggilnya.
 */
class PipelineLog
{
    /** ponytail: dipangkas saat lewat ambang, bukan dirotasi ke file lain. */
    private const MAX_BYTES = 2_000_000;

    private const KEEP_LINES = 800;

    public static function path(): string
    {
        return storage_path('pipeline.jsonl');
    }

    public static function write(string $queue, string $message): void
    {
        $message = rtrim($message);
        if ($message === '') {
            return;
        }

        $line = json_encode([
            't' => now()->format('H:i:s'),
            'q' => $queue,
            'm' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        file_put_contents(self::path(), $line.PHP_EOL, FILE_APPEND | LOCK_EX);

        if (@filesize(self::path()) > self::MAX_BYTES) {
            self::trim();
        }
    }

    /**
     * Callback output untuk Process: stdout probe.php diteruskan baris per baris.
     * Satu chunk bisa memuat beberapa baris sekaligus, jadi dipecah dulu.
     */
    public static function stream(string $queue): Closure
    {
        return function (string $type, string $chunk) use ($queue) {
            foreach (preg_split('/\r?\n/', $chunk) as $line) {
                self::write($queue, $line);
            }
        };
    }

    /**
     * @return array<int, array{t: string, q: string, m: string}>
     */
    public static function tail(int $lines = 60): array
    {
        $rows = self::rows();

        return array_values(array_slice($rows, -$lines));
    }

    /**
     * Baris terakhir per antrian = yang sedang dikerjakan masing-masing worker.
     *
     * @return array<string, string>
     */
    public static function current(): array
    {
        $out = [];
        foreach (self::rows() as $row) {
            $out[$row['q']] = $row['m'];
        }

        return $out;
    }

    /**
     * Jejak fetch terakhir untuk satu akun: potongan antrian `ig` dari baris
     * `== fetch @user` sampai `== fetch` berikutnya. Baris detailnya ("dilewat:
     * VIDEO tanpa gambar", "dilewat: sudah dikecualikan") tidak menyebut
     * username — yang membatasinya cuma posisi, dan itu cukup karena antrian
     * `ig` cuma satu worker: satu proses = satu akun, tidak ada yang menyelip.
     *
     * Kosong = fetch-nya sudah tergeser dari 800 baris terakhir, bukan tidak
     * pernah jalan.
     *
     * @return array<int, array{t: string, q: string, m: string}>
     */
    public static function fetchBlock(string $username): array
    {
        $rows = array_values(array_filter(self::rows(), fn ($r) => $r['q'] === 'ig'));

        $mulai = null;
        foreach ($rows as $i => $row) {
            if (str_starts_with($row['m'], "== fetch @$username ")) {
                $mulai = $i;
            }
        }
        if ($mulai === null) {
            return [];
        }

        $blok = [];
        foreach (array_slice($rows, $mulai) as $row) {
            if ($blok !== [] && str_starts_with($row['m'], '== fetch @')) {
                break;
            }
            $blok[] = $row;
        }

        return $blok;
    }

    /** @return array<int, array{t: string, q: string, m: string}> */
    private static function rows(): array
    {
        if (! is_file(self::path())) {
            return [];
        }

        $rows = [];
        foreach (file(self::path(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $row = json_decode($line, true);
            if (isset($row['q'], $row['m'])) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private static function trim(): void
    {
        $rows = array_slice(file(self::path()) ?: [], -self::KEEP_LINES);
        file_put_contents(self::path(), implode('', $rows), LOCK_EX);
    }
}
