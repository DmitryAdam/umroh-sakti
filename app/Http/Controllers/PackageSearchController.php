<?php

namespace App\Http\Controllers;

use App\Jobs\ExtractPost;
use App\Jobs\FetchAccount;
use App\Models\Package;
use App\Models\SourceAccount;
use App\Support\ExcludedPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Pencarian publik. Hanya paket published.
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

    /**
     * Filter pilihan-tertutup: param query -> kolom. Isinya tidak diketik manual —
     * `facets()` menarik nilai yang benar-benar ada di data beserta jumlahnya, jadi
     * tidak ada pilihan yang hasilnya nol dan tidak ada nilai yang kelewat (nama
     * maskapai di flyer belasan ejaan: "Saudia", "Saudia Airlines", "Saudi Airlines").
     */
    public const FACETS = [
        'city' => 'departure_city',
        'airline' => 'airline',
        'account' => 'source_account',
        'extension' => 'extension',
        'certainty' => 'date_certainty',
        'status' => 'status',
    ];

    public function index(Request $request): View
    {
        // Pratinjau operator: lihat paket yang belum lolos review, plus tombol aksi
        // per kartu. Default menyala kalau login (?all=0 untuk mematikan). Tamu
        // tidak pernah dapat keduanya — jalur "publish tanpa review manusia" tetap
        // tidak ada, yang berubah cuma kuncinya (login, bukan env local).
        $preview = $request->user() !== null && $request->boolean('all', true);

        $base = fn () => Package::query()->unless($preview, fn ($q) => $q->published());

        $query = $base();

        foreach (self::FACETS as $param => $column) {
            if ($value = $request->string($param)->toString()) {
                $query->where($column, $value);
            }
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
        if ($minPrice = $request->integer('min_price')) {
            $query->whereAny(Package::PRICE_COLUMNS, '>=', $minPrice);
        }
        // Nama hotel apa adanya, jadi filternya pencocokan teks — bintang hotel
        // tidak ada lagi (dulu dari tabel master; flyer tidak menyebutkannya).
        if ($hotel = $request->string('hotel')->toString()) {
            $query->where(fn ($q) => $q
                ->where('hotel_makkah', 'like', "%$hotel%")
                ->orWhere('hotel_madinah', 'like', "%$hotel%"));
        }
        // Cari bebas: yang sering dicari orang tapi bukan pilihan tertutup —
        // nama pembimbing, hotel, kota, maskapai sekaligus.
        if ($cari = $request->string('q')->toString()) {
            $query->where(fn ($q) => $q
                ->where('guide_name', 'like', "%$cari%")
                ->orWhere('hotel_makkah', 'like', "%$cari%")
                ->orWhere('hotel_madinah', 'like', "%$cari%")
                ->orWhere('departure_city', 'like', "%$cari%")
                ->orWhere('airline', 'like', "%$cari%"));
        }
        // Saklar "ada harga"/"ada tanggal" tidak ada lagi: keduanya sekarang syarat
        // masuk DB (ImportExtractedPackages::belumLengkap), jadi filternya pasti
        // mengembalikan semua baris.

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
            'facets' => $this->facets($base),
            'durations' => $base()->whereNotNull('duration_days')
                ->distinct()->orderBy('duration_days')->pluck('duration_days'),
            'reference' => (int) config('umroh.bpiu_reference'),
        ]);
    }

    /**
     * Nilai yang benar-benar ada per kolom facet + jumlah barisnya, terbanyak dulu.
     *
     * Sengaja dihitung dari himpunan dasar (cuma scope status), bukan dari hasil
     * yang sudah difilter: kalau ikut menyempit, memilih satu kota membuang semua
     * kota lain dari daftar dan pilihannya tidak bisa diganti tanpa reset.
     *
     * @param  \Closure(): \Illuminate\Database\Eloquent\Builder  $base
     * @return array<string, \Illuminate\Support\Collection<string, int>>
     */
    private function facets(\Closure $base): array
    {
        $out = [];

        foreach (self::FACETS as $param => $column) {
            $out[$param] = $base()
                ->whereNotNull($column)->where($column, '!=', '')
                ->selectRaw("$column as nilai, count(*) as jumlah")
                ->groupBy($column)
                ->orderByDesc('jumlah')
                ->pluck('jumlah', 'nilai');
        }

        return $out;
    }

    /**
     * Satu-satunya jalur yang mengubah `status` sesudah import: operator menekan
     * pilihannya di kartu. Sengaja manual — aturan "jangan auto-publish harga"
     * berarti tidak boleh ada kode yang menaikkannya ke `published` sendiri.
     */
    public function status(Package $package, Request $request): JsonResponse
    {
        $package->update($request->validate([
            'status' => ['required', Rule::in(Package::STATUSES)],
        ]));

        return response()->json(['status' => $package->status]);
    }

    /**
     * "Ini bukan flyer umroh" — buang paketnya, kecualikan post sumbernya supaya tidak
     * pernah di-scrap lagi, dan hapus raw + hasil ekstraksinya.
     * Keputusannya dicatat ke storage/feedback.jsonl sebagai bahan perbaikan prompt.
     */
    public function destroy(Package $package, Request $request): JsonResponse
    {
        // Satu carousel bisa jadi beberapa paket (satu gambar = satu baris). Kalau
        // slide lain dari post yang sama masih hidup, × cuma menyangkal baris ini:
        // post-nya tidak dikecualikan dan rawnya tidak dihapus — kalau
        // dihapus, semua paket sebelahnya kehilangan flyernya.
        $punyaSaudara = Package::where('media_id', $package->media_id)
            ->whereKeyNot($package->getKey())
            ->exists();

        foreach ($package->posts() as $post) {
            File::append(storage_path('feedback.jsonl'), json_encode([
                'media_id' => $post['media_id'],
                'source_account' => $post['account'],
                'verdict' => 'bukan_paket',
                'note' => $request->string('note')->toString() ?: null,
                'reviewed_at' => now()->toIso8601String(),
                'extraction' => $package->raw_extraction,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);

            // Kecualikan = seluruh post tidak akan disentuh lagi. Cuma boleh kalau tidak
            // ada slide lain dari post itu yang masih jadi paket.
            $punyaSaudara || ExcludedPost::add(
                $post['media_id'],
                $post['account'],
                'manual',
                $request->string('note')->toString() ?: null,
            );
        }

        $punyaSaudara || $package->deleteSources();
        $package->delete();

        return response()->json(['deleted' => $package->id]);
    }

    /**
     * "Baca ulang pakai AI" — gambar post ini dikirim lagi ke vision + penyusun.
     *
     * Tiga langkah yang tidak bisa dilewat: flyer dikembalikan ke storage/raw
     * (extract cuma membaca dari sana, dan promoteFlyer sudah memindahkannya),
     * hasil ekstraksi lama dihapus, dan barisnya dihapus — `importOne` sengaja
     * tidak pernah menimpa baris yang sudah ada, jadi tanpa ini hasil baca
     * ulangnya tidak akan pernah tampil.
     *
     * Postnya TIDAK dikecualikan dan rawnya tidak dihapus: ini bukan vonis atas
     * postnya, cuma minta dibaca ulang.
     */
    public function reextract(Package $package): JsonResponse
    {
        if (! is_file(storage_path("raw/{$package->source_account}/{$package->media_id}/post.json"))) {
            return response()->json(['error' => 'raw postnya sudah tidak ada — ambil ulang dulu'], 409);
        }

        // Semua slide dikembalikan, bukan cuma milik paket ini: nomor slide dari
        // vision menunjuk gambar ke-N yang DIKIRIM, jadi satu gambar yang hilang
        // menggeser penomoran paket-paket sebelahnya.
        foreach (Package::where('media_id', $package->media_id)->get() as $slide) {
            $slide->restoreFlyer();
        }

        foreach (glob(storage_path("extracted/{$package->media_id}{.json,-*.json}"), GLOB_BRACE) ?: [] as $file) {
            File::delete($file);
        }

        $package->delete();
        ExtractPost::dispatch($package->media_id);

        return response()->json(['queued' => $package->media_id]);
    }

    /**
     * "Ambil ulang postingannya" — raw postnya dibuang lalu akunnya di-fetch lagi,
     * jadi gambarnya di-download ulang (signed CDN URL memang tidak disimpan) DAN
     * dibaca ulang: ExtractPending memindai raw baru, ExtractPost menyusunnya,
     * ImportPackages membikin barisnya lagi. Tanpa membuang hasil ekstraksi lama
     * + barisnya, download itu tidak mengubah apa pun yang tampil — ExtractPending
     * melewati post yang sudah punya file di `storage/extracted`, dan `importOne`
     * memang tidak pernah menimpa baris yang sudah ada.
     *
     * Yang dihapus SEMUA slide se-media_id, bukan cuma paket ini: postnya
     * di-download ulang utuh, dan nomor slide dari vision menunjuk gambar ke-N
     * yang dikirim — sisa baris lama bikin penomorannya tabrakan.
     *
     * Postnya tidak dikecualikan: ini bukan vonis "bukan paket".
     */
    public function refetch(Package $package): JsonResponse
    {
        $account = SourceAccount::firstWhere('username', $package->source_account);

        if (! $account) {
            return response()->json(['error' => "akun @{$package->source_account} tidak terdaftar"], 409);
        }

        // Tanpa ini fetch melewatinya: "sudah ada di storage/raw".
        File::deleteDirectory(storage_path("raw/{$package->source_account}/{$package->media_id}"));

        foreach (glob(storage_path("extracted/{$package->media_id}{.json,-*.json}"), GLOB_BRACE) ?: [] as $file) {
            File::delete($file);
        }

        // Flyer yang sudah dipromosikan ikut dibuang — gambarnya di-download lagi,
        // jadi yang lama cuma jadi yatim di disk `flyers` (tidak ada baris yang
        // menunjuknya, dan `promoteFlyer` menulis path yang sama).
        foreach (Package::where('media_id', $package->media_id)->get() as $slide) {
            $slide->deleteSources();
            $slide->delete();
        }

        FetchAccount::dispatch($account, 50);

        return response()->json(['queued' => $account->username]);
    }

    /**
     * Catatan koreksi dari pratinjau lokal. Bahan mentah untuk memperbaiki prompt
     * ekstraksi — tidak mengubah status publikasi.
     */
    public function feedback(Package $package, Request $request): RedirectResponse|JsonResponse
    {
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
            $package->status === 'published' || $request->user() !== null,
            404,
        );

        // Lightbox mengambil isinya lewat fetch: potongan yang sama, tanpa layout.
        return view($request->ajax() ? 'partials.detail' : 'package', [
            'package' => $package,
            'reference' => (int) config('umroh.bpiu_reference'),
        ]);
    }
}
