<?php

namespace App\Http\Controllers;

use App\Models\Package;
use App\Support\BannedPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Pencarian publik. Hanya paket published.
 *
 * Filter masih jalan lewat query string (?city=&airline=&max_price=...), formnya
 * saja yang dilepas dari halaman supaya layar penuh flyer saat review.
 */
class PackageSearchController extends Controller
{
    /** Pilihan urutan -> label tombol. Kuncinya sekaligus whitelist `?sort=`. */
    public const SORTS = [
        'date' => 'tanggal terdekat',
        'date_desc' => 'tanggal terjauh',
        'price' => 'termurah',
        'price_desc' => 'termahal',
    ];

    public function index(Request $request): View
    {
        // Pratinjau lokal: lihat paket yang belum lolos review. Default menyala di
        // lokal (?semua=0 untuk mematikan), tapi dikunci ke env local supaya jalur
        // "publish tanpa review manusia" tidak pernah ada di produksi.
        $preview = app()->isLocal() && $request->boolean('semua', true);

        $query = Package::query()
            ->unless($preview, fn ($q) => $q->published());

        if ($city = $request->string('city')->toString()) {
            $query->where('departure_city', $city);
        }
        if ($airline = $request->string('airline')->toString()) {
            $query->where('airline', $airline);
        }
        if ($from = $request->date('from')) {
            $query->whereDate('departure_date', '>=', $from);
        }
        if ($to = $request->date('to')) {
            $query->whereDate('departure_date', '<=', $to);
        }
        if ($min = $request->integer('duration_min')) {
            $query->where('duration_days', '>=', $min);
        }
        if ($max = $request->integer('duration_max')) {
            $query->where('duration_days', '<=', $max);
        }
        if ($maxPrice = $request->integer('max_price')) {
            $query->whereAny(Package::PRICE_COLUMNS, '<=', $maxPrice);
        }
        // Nama hotel apa adanya, jadi filternya pencocokan teks — bintang hotel
        // tidak ada lagi (dulu dari tabel master; flyer tidak menyebutkannya).
        if ($hotel = $request->string('hotel')->toString()) {
            $query->where(fn ($q) => $q
                ->where('hotel_makkah', 'like', "%$hotel%")
                ->orWhere('hotel_madinah', 'like', "%$hotel%"));
        }

        $sort = $request->string('sort')->toString();
        $sort = isset(self::SORTS[$sort]) ? $sort : 'date';

        // Harga paket = tier terisi yang termurah. min() SQLite balik NULL kalau ada
        // argumen NULL, jadi tiap tier dikerek ke sentinel dulu lalu dikembalikan
        // jadi NULL kalau keempatnya kosong — supaya paket tanpa harga jatuh ke
        // bawah, bukan jadi "termurah".
        $termurah = 'nullif(min('.implode(', ', array_map(
            fn ($column) => "coalesce($column, 1e18)",
            Package::PRICE_COLUMNS,
        )).'), 1e18)';

        $packages = $query
            ->orderByRaw(match ($sort) {
                'price' => "$termurah asc nulls last",
                'price_desc' => "$termurah desc nulls last",
                'date_desc' => 'departure_date desc nulls last',
                default => 'departure_date asc nulls last',
            })
            ->get();

        return view('search', [
            'packages' => $packages,
            'preview' => $preview,
            'sort' => $sort,
            'reference' => (int) config('umroh.bpiu_reference'),
        ]);
    }

    /**
     * "Ini bukan flyer umroh" — buang paketnya, banned post sumbernya supaya tidak
     * pernah di-scrap lagi, dan pindahkan raw + hasil ekstraksinya ke storage/trash.
     * Keputusannya dicatat ke storage/feedback.jsonl sebagai bahan perbaikan prompt.
     */
    public function destroy(Package $package, Request $request): JsonResponse
    {
        abort_unless(app()->isLocal(), 404);

        foreach ($package->posts() as $post) {
            File::append(storage_path('feedback.jsonl'), json_encode([
                'media_id' => $post['media_id'],
                'source_account' => $post['account'],
                'verdict' => 'bukan_paket',
                'note' => $request->string('note')->toString() ?: null,
                'reviewed_at' => now()->toIso8601String(),
                'extraction' => $package->raw_extraction,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);

            BannedPost::add(
                $post['media_id'],
                $post['account'],
                'manual',
                $request->string('note')->toString() ?: null,
            );
        }

        $package->trashSources();
        $package->delete();

        return response()->json(['deleted' => $package->id]);
    }

    /**
     * Catatan koreksi dari pratinjau lokal. Bahan mentah untuk memperbaiki prompt
     * ekstraksi — tidak mengubah status publikasi.
     */
    public function feedback(Package $package, Request $request): RedirectResponse|JsonResponse
    {
        abort_unless(app()->isLocal(), 404);

        $package->update($request->validate([
            'review_verdict' => ['required', Rule::in(array_keys(Package::REVIEW_VERDICTS))],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]) + ['reviewed_at' => now()]);

        if ($request->wantsJson()) {
            return response()->json(['saved' => $package->id]);
        }

        // Fallback tanpa JS: balik ke kartunya, bukan ke puncak halaman.
        return back()->withFragment("p{$package->id}")->with('saved', $package->id);
    }

    public function show(Package $package, Request $request): View
    {
        abort_unless(
            $package->status === 'published' || (app()->isLocal() && $request->boolean('semua', true)),
            404,
        );

        return view('package', [
            'package' => $package,
            'reference' => (int) config('umroh.bpiu_reference'),
        ]);
    }
}
