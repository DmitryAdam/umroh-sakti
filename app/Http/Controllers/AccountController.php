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
 * terakhirnya. Alat kerja lokal seperti panel pipeline — `abort_unless(isLocal())`,
 * jadi tidak pernah kebuka di produksi.
 */
class AccountController extends Controller
{
    public function index(): View
    {
        abort_unless(app()->isLocal(), 404);

        // Angka per akun dihitung dari sumbernya langsung — tidak ada kolom counter
        // yang perlu dijaga tetap sinkron.
        $posts = array_count_values(array_map(
            fn ($path) => basename(dirname($path, 2)),
            glob(storage_path('raw/*/*/post.json')) ?: [],
        ));

        return view('accounts', [
            'accounts' => SourceAccount::orderByRaw('last_fetched_at is null desc, last_fetched_at desc')->get(),
            'posts' => $posts,
            'packages' => Package::selectRaw('source_account, count(*) as total')
                ->groupBy('source_account')->pluck('total', 'source_account'),
            'dibanned' => DB::table('banned_posts')->selectRaw('source_account, count(*) as total')
                ->groupBy('source_account')->pluck('total', 'source_account'),
        ]);
    }

    /** Textarea (username / URL / @handle, satu per baris) -> akun approved. */
    public function store(Request $request): RedirectResponse
    {
        abort_unless(app()->isLocal(), 404);

        $lines = preg_split('/\r?\n/', $request->validate([
            'usernames' => ['required', 'string', 'max:10000'],
        ])['usernames']);

        $new = SourceAccount::register($lines);

        return back()->with('status', $new
            ? count($new).' akun baru: '.implode(', ', $new)
            : 'Tidak ada akun baru — semuanya sudah terdaftar atau barisnya tidak valid.');
    }

    /**
     * Scrap semua akun approved. Lewat `packages:crawl` — loop dispatch-nya sudah
     * ada di sana, jadi tombol ini tidak punya versinya sendiri.
     */
    public function fetchAll(): RedirectResponse
    {
        abort_unless(app()->isLocal(), 404);

        PipelineLog::write('run', '== tombol scrap semua: antrikan fetch semua akun approved');
        Artisan::call('packages:crawl', ['--limit' => 9]);

        foreach (preg_split('/\r?\n/', trim(Artisan::output())) as $line) {
            PipelineLog::write('run', $line);
        }

        // Post dari putaran sebelumnya yang belum diekstrak tidak menunggu fetch selesai.
        ExtractPending::dispatch();

        return back()->with('status', 'Semua akun approved masuk antrian ig. Pastikan `php artisan queue:work` jalan.');
    }

    /** Scrap satu akun sekarang, tanpa menunggu putaran pipeline berikutnya. */
    public function fetch(SourceAccount $account): RedirectResponse
    {
        abort_unless(app()->isLocal(), 404);

        FetchAccount::dispatch($account, 9);

        return back()->with('status', "@{$account->username} masuk antrian ig. Pastikan `php artisan queue:work` jalan.");
    }

    /**
     * Buang akunnya dari daftar. Paket yang sudah masuk dan post yang sudah
     * dibanned tidak disentuh — itu hasil kerja, bukan milik barisnya.
     */
    public function destroy(SourceAccount $account): RedirectResponse
    {
        abort_unless(app()->isLocal(), 404);

        $account->delete();

        return back()->with('status', "@{$account->username} dihapus dari daftar akun.");
    }
}
