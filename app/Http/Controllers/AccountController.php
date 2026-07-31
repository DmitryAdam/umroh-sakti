<?php

namespace App\Http\Controllers;

use App\Jobs\ExtractPending;
use App\Jobs\FetchAccount;
use App\Models\Package;
use App\Models\SourceAccount;
use App\Support\PipelineLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Daftar akun sumber: masukin username, lihat mana yang sudah di-scrap dan kapan
 * terakhirnya. Alat kerja operator seperti panel pipeline — seluruh controller ini
 * ada di grup `auth` (routes/web.php), tidak pernah kebuka buat tamu.
 */
class AccountController extends Controller
{
    /**
     * Kolom yang bisa diurut di /akun -> label headernya.
     *
     * Whitelist, sama seperti `PackageSearchController::SORTS`: nilai asing balik
     * ke urutan default (yang perlu tindakan di atas), bukan bikin query aneh.
     */
    public const SORTS = [
        'account' => 'akun',
        'followers' => 'pengikut',
        'following' => 'diikuti',
        'ig_posts' => 'post IG',
        'downloaded' => 'terunduh',
        'packages' => 'paket',
        'rejected' => 'ditolak',
        'last_fetched' => 'terakhir scrap',
    ];

    public function index(Request $request): View
    {
        // Angka per akun dihitung dari sumbernya langsung — tidak ada kolom counter
        // yang perlu dijaga tetap sinkron.
        $posts = array_count_values(array_map(
            fn ($path) => basename(dirname($path, 2)),
            glob(storage_path('raw/*/*/post.json')) ?: [],
        ));
        $packages = Package::selectRaw('source_account, count(*) as total')
            ->groupBy('source_account')->pluck('total', 'source_account');
        $dikecualikan = DB::table('excluded_posts')->selectRaw('source_account, count(*) as total')
            ->groupBy('source_account')->pluck('total', 'source_account');

        // Yang gagal-dan-kosong paling atas, lalu yang belum pernah di-scrap:
        // dua-duanya butuh tindakan, sisanya cuma riwayat. Ini urutan defaultnya.
        // `last_error` sendirian tidak menaikkan baris — lihat SourceAccount::gagal().
        $accounts = SourceAccount::orderByRaw(
            '(last_error is not null and last_fetched_at is null) desc, last_fetched_at is null desc, last_fetched_at desc',
        )->get();

        // Blocklist dipisah dari daftar kerja: barisnya cuma penjaga supaya
        // username yang sama ditolak saat dimasukkan lagi, datanya sudah dibuang.
        [$blocked, $accounts] = $accounts->partition->isBlocked();
        // Usulan peran `user` juga dipisah: belum boleh di-scrap, dan yang perlu
        // dikerjakan atasnya cuma satu — setujui atau buang.
        [$pending, $accounts] = $accounts->partition(fn (SourceAccount $a) => $a->status === 'pending');

        // Diurut di PHP, bukan di SQL: tiga kolomnya (terunduh/paket/ditolak) dihitung
        // dari disk + tabel lain, jadi tidak ada kolom yang bisa di-ORDER BY. 197 baris,
        // sort-nya tidak kelihatan.
        $sort = $request->query('sort');
        $key = match ($sort) {
            'account' => fn (SourceAccount $a) => $a->username,
            'followers' => fn (SourceAccount $a) => $a->followers_count ?? -1,
            'following' => fn (SourceAccount $a) => $a->follows_count ?? -1,
            'ig_posts' => fn (SourceAccount $a) => $a->media_count ?? -1,
            'downloaded' => fn (SourceAccount $a) => $posts[$a->username] ?? 0,
            'packages' => fn (SourceAccount $a) => $packages[$a->username] ?? 0,
            'rejected' => fn (SourceAccount $a) => $dikecualikan[$a->username] ?? 0,
            'last_fetched' => fn (SourceAccount $a) => $a->last_fetched_at?->getTimestamp() ?? 0,
            default => null,
        };
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        if ($key !== null) {
            $accounts = ($dir === 'asc' ? $accounts->sortBy($key) : $accounts->sortByDesc($key))->values();
        }

        // "Punya isi" = akun ini pernah menghasilkan sesuatu — post terunduh, paket,
        // atau post yang ditolak. Bahan `SourceAccount::gagal()`: error yang menempel
        // di akun berisi itu satu percobaan meleset, bukan akun yang perlu ditindak.
        $isi = fn (SourceAccount $a) => ($posts[$a->username] ?? 0)
            + ($packages[$a->username] ?? 0)
            + ($dikecualikan[$a->username] ?? 0);

        return view('accounts', [
            'accounts' => $accounts,
            'isi' => $isi,
            'blocked' => $blocked->values(),
            'pending' => $pending->values(),
            'posts' => $posts,
            'packages' => $packages,
            'dikecualikan' => $dikecualikan,
            'sort' => $key === null ? null : $sort,
            'dir' => $dir,
        ]);
    }

    /**
     * Textarea (username / URL / @handle, satu per baris) -> akun baru.
     *
     * Admin: langsung `approved`, ikut putaran pipeline berikutnya. Peran `user`:
     * `pending` + email pengusulnya — semua jalur crawl menyaring `approved`, jadi
     * usulan tidak bisa membakar kuota Graph sebelum ada yang menyetujuinya.
     */
    public function store(Request $request): RedirectResponse
    {
        $admin = $request->user()->isAdmin();
        $lines = preg_split('/\r?\n/', $request->validate([
            'usernames' => ['required', 'string', 'max:10000'],
        ])['usernames']);

        // Diperiksa sebelum register(): yang diblokir memang sudah dilewati sendiri
        // (barisnya masih ada), tapi tanpa pesan ini penolakannya tidak kelihatan
        // bedanya dari "sudah terdaftar".
        $ditolak = SourceAccount::blocked()->whereIn(
            'username', array_filter(array_map(SourceAccount::usernameOf(...), $lines)),
        )->pluck('username');

        $new = SourceAccount::register($lines, $admin
            ? []
            : ['status' => 'pending', 'suggested_by' => $request->user()->email]);

        return back()->with('status', trim(($new
            ? count($new).($admin ? ' akun baru: ' : ' akun diusulkan, menunggu admin: ').implode(', ', $new)
            : 'Tidak ada akun baru — semuanya sudah terdaftar atau barisnya tidak valid.')
            .($ditolak->isNotEmpty() ? ' Ditolak (ada di blocklist): '.$ditolak->implode(', ').'.' : '')));
    }

    /**
     * Scrap sekelompok akun approved: yang gagal (`failed=1`), yang belum pernah
     * (`new=1`), atau yang terakhir berhasilnya lebih dari N jam lalu (`hours=N`).
     * Lewat `packages:crawl` — loop dispatch dan ketiga filternya sudah ada di
     * sana, jadi tombol ini tidak punya versinya sendiri.
     *
     * **Tidak ada "scrap semua".** Tanpa filter, 189 akun masuk antrian `ig` yang
     * cuma satu worker — kuota Graph (`total_time`) habis di tengah jalan dan
     * sebagian besar akun cuma di-fetch ulang untuk post yang itu-itu juga.
     * Request tanpa satu pun filter ditolak, bukan diartikan "semua".
     */
    public function fetchAll(Request $request): RedirectResponse
    {
        $baru = $request->boolean('new');
        $gagal = $request->boolean('failed');
        $jam = max(0, (int) $request->input('hours'));

        if (! $gagal && ! $baru && $jam < 1) {
            return back()->with('status', 'Pilih dulu kelompoknya: yang gagal, yang belum pernah, atau yang lebih lama dari N jam.');
        }

        $apa = match (true) {
            $gagal => 'akun yang gagal',
            $baru => 'akun yang belum pernah di-scrap',
            default => "akun yang terakhir di-scrap > $jam jam lalu",
        };

        PipelineLog::write('run', "== tombol scrap $apa: antrikan fetch");
        Artisan::call('packages:crawl', array_filter([
            '--limit' => 9,
            '--new' => $baru,
            '--failed' => $gagal,
            '--cooldown' => $jam,
        ]));

        foreach (preg_split('/\r?\n/', trim(Artisan::output())) as $line) {
            PipelineLog::write('run', $line);
        }

        // Post dari putaran sebelumnya yang belum diekstrak tidak menunggu fetch selesai.
        ExtractPending::dispatch();

        return back()->with('status', ucfirst($apa).' masuk antrian ig. Pastikan `php artisan queue:work` jalan.');
    }

    /**
     * Tiga tindakan baris (scrap / blokir / hapus) untuk banyak akun sekaligus,
     * dari centang di tabel `/accounts`.
     *
     * Satu endpoint dengan `action`, bukan tiga route: yang membedakan cuma satu
     * baris di dalam loop, dan checkbox-nya toh cuma satu himpunan. Tindakannya
     * sama persis dengan tombol per barisnya — `fetch()`, `block()`, `destroy()`
     * memanggil method model yang sama, jadi tidak ada jalur kedua yang bisa
     * menyimpang.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:crawl,force,block,delete,approve'],
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $accounts = SourceAccount::whereIn('id', $data['ids'])->get();
        $paket = 0;
        $dilepas = 0;
        $kena = [];

        foreach ($accounts as $account) {
            // Yang diblokir dilewat, tidak digagalkan: centang "semua" di daftar
            // kerja tidak pernah memuatnya, tapi scrap massal jangan sampai
            // menghidupkan lagi akun yang sengaja dimatikan.
            if (in_array($data['action'], ['crawl', 'force'], true) && $account->isBlocked()) {
                continue;
            }

            $kena[] = $account->username;

            if ($data['action'] === 'approve') {
                // Approval usulan: statusnya saja. Scrap-nya tidak diantrikan di sini —
                // putaran `packages:crawl` berikutnya sudah mengambil semua approved,
                // dan tombol scrap per baris ada kalau mau sekarang.
                $account->update(['status' => 'approved']);
            } elseif ($data['action'] === 'crawl' || $data['action'] === 'force') {
                // Paksa = lepas dulu post yang pernah ditolak, sisanya scrap biasa.
                $dilepas += $data['action'] === 'force' ? $account->lupakanPenolakan() : 0;
                FetchAccount::dispatch($account, 9);
            } elseif ($data['action'] === 'block') {
                $paket += $account->purge();
                $account->update(['status' => 'blocked']);
            } else {
                $paket += $account->purgeAndDelete();
            }
        }

        $pesan = match ($data['action']) {
            'approve' => count($kena).' usulan akun disetujui — ikut di putaran scrap berikutnya.',
            'crawl' => count($kena).' akun masuk antrian ig. Pastikan `php artisan queue:work` jalan.',
            'force' => count($kena)." akun masuk antrian ig · $dilepas post yang pernah ditolak dilepas, di-download & dibaca AI lagi.",
            'block' => count($kena)." akun masuk blocklist · $paket paket dihapus, usernamenya ditolak kalau dimasukkan lagi.",
            'delete' => count($kena)." akun dihapus total · $paket paket ikut dihapus.",
        };

        return back()->with('status', $pesan.($kena ? ' ('.implode(', ', array_slice($kena, 0, 8)).(count($kena) > 8 ? ', …' : '').')' : ''));
    }

    /**
     * Scrap satu akun sekarang, tanpa menunggu putaran pipeline berikutnya.
     *
     * `force=1` (scrap paksa): hapus dulu baris `excluded_posts` akun ini, jadi post
     * yang pernah ditolak ikut di-download lagi dan dibayar ke model lagi. Barisnya
     * itu satu-satunya yang menahan fetch (`probe.php` membacanya lewat PDO) — begitu
     * hilang, sisa pipeline jalan sendiri: fetch → ExtractPending → ExtractPost → import.
     *
     * Filenya tidak perlu disentuh: post yang ditolak file rawnya memang sudah dihapus.
     * Konsekuensinya `--limit` sekarang menghitung post-post itu juga, jadi akun dengan
     * backlog panjang perlu beberapa kali tekan — yang sudah terunduh dilewat lagi.
     */
    public function fetch(SourceAccount $account, Request $request): RedirectResponse
    {
        if ($account->isBlocked()) {
            return back()->with('status', "@{$account->username} ada di blocklist — lepas blokirnya dulu kalau mau di-scrap.");
        }

        $dilepas = $request->boolean('force') ? $account->lupakanPenolakan() : 0;

        FetchAccount::dispatch($account, 9);

        return back()->with('status', "@{$account->username} masuk antrian ig"
            .($dilepas ? " · $dilepas post dilepas dari daftar dikecualikan, di-download & dibaca AI lagi" : '')
            .'. Pastikan `php artisan queue:work` jalan.');
    }

    /**
     * Blokir: barisnya disimpan (itu yang menolak input username yang sama nanti),
     * datanya dibuang. Kirim `unblock=1` untuk melepasnya kembali jadi approved —
     * datanya tidak balik, akunnya tinggal di-scrap ulang.
     */
    public function block(SourceAccount $account, Request $request): RedirectResponse
    {
        if ($request->boolean('unblock')) {
            $account->update(['status' => 'approved']);

            return back()->with('status', "@{$account->username} keluar dari blocklist — status approved, belum di-scrap.");
        }

        $paket = $account->purge();
        $account->update(['status' => 'blocked']);

        return back()->with('status', "@{$account->username} masuk blocklist · $paket paket dihapus. Username ini ditolak kalau dimasukkan lagi.");
    }

    /**
     * Hapus total: baris, paket, raw, hasil ekstraksi, profil, dan catatan post
     * yang dikecualikan. Tidak ada sisa yang menahannya — username ini boleh
     * dimasukkan lagi dan di-scrap dari nol. Yang mau menolaknya permanen: blokir.
     */
    public function destroy(SourceAccount $account): RedirectResponse
    {
        $paket = $account->purgeAndDelete();

        return back()->with('status', "@{$account->username} dihapus total · $paket paket ikut dihapus.");
    }
}
