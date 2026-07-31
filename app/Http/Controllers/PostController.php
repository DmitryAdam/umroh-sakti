<?php

namespace App\Http\Controllers;

use App\Jobs\ExtractPost;
use App\Models\Package;
use App\Models\SourceAccount;
use App\Support\ExcludedPost;
use App\Support\PipelineLog;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Post apa adanya: gambar yang terunduh, statusnya (jadi paket / ditolak dengan
 * alasannya / masih menunggu), caption, dan aksinya.
 *
 * Satu halaman dua ruang lingkup: `/posts` seluruh akun, `/accounts/{id}/posts`
 * satu akun. Bedanya cuma satu argumen — sisanya (tab, tabel, aksi kelompok)
 * dipakai bersama, karena kerjanya memang sama dan cuma himpunannya yang beda.
 *
 * Aksinya sengaja TIDAK dilingkupi akun: `media_id` sudah unik, dan akunnya
 * dicari sendiri dari raw/`excluded_posts`/baris paket (`akun()`). Tanpa itu tiap
 * aksi butuh dua jalur — satu buat halaman akun, satu buat halaman semua post.
 *
 * Alat kerja operator: seluruh controller ini ada di grup `auth`.
 */
class PostController extends Controller
{
    private const PER_HALAMAN = 60;

    private const FILTERS = ['packages', 'rejected', 'pending', 'suggestions'];

    public function index(Request $request, ?SourceAccount $account = null): View
    {
        $posts = $this->kumpulkan($account?->username);

        $f = in_array($request->query('filter'), self::FILTERS, true) ? $request->query('filter') : null;
        $tampil = ($f === null ? $posts : $posts->filter(self::saring($f)))->values();

        // Halaman "semua post" gampang jadi ribuan baris — masing-masing dengan
        // gambar. Dipotong di sini, bukan di query: himpunannya memang dirakit dari
        // disk + dua tabel, jadi tidak ada yang bisa di-LIMIT.
        $halaman = LengthAwarePaginator::resolveCurrentPage();

        return view('posts', [
            'account' => $account,
            'posts' => new LengthAwarePaginator(
                $tampil->forPage($halaman, self::PER_HALAMAN)->values(),
                $tampil->count(),
                self::PER_HALAMAN,
                $halaman,
                ['path' => $request->url(), 'query' => $request->query()],
            ),
            'jejak' => $account ? PipelineLog::fetchBlock($account->username) : [],
            // username -> id, sekali query: kolom akun di halaman semua post menaut
            // ke halaman per-akunnya, dan tanpa peta ini itu satu query per baris.
            'akunId' => $account ? [] : SourceAccount::pluck('id', 'username')->all(),
            'f' => $f,
            // Angka per tab dari saringan yang sama dengan yang menyaring tabelnya —
            // dua definisi berarti tab yang menyebut 3 lalu menampilkan 5.
            'jumlah' => [null => $posts->count()] + collect(self::FILTERS)
                ->mapWithKeys(fn ($key) => [$key => $posts->filter(self::saring($key))->count()])
                ->all(),
        ]);
    }

    /**
     * Satu tab = satu saringan. Dipakai dua kali (isi tabel + angka di tabnya).
     *
     * @return \Closure(array<string, mixed>): bool
     */
    private static function saring(string $filter): \Closure
    {
        return match ($filter) {
            'rejected' => fn ($p) => $p['alasan'] !== null,
            'packages' => fn ($p) => $p['paket']->isNotEmpty(),
            'suggestions' => fn ($p) => $p['usulan'] !== null,
            // "menunggu" = belum divonis dan belum jadi paket. Usulan yang belum
            // di-approve tidak dihitung di sini: yang menahannya bukan antrian.
            default => fn ($p) => $p['alasan'] === null && $p['paket']->isEmpty() && $p['usulan'] === null,
        };
    }

    /**
     * Aksi kelompok atas post yang dicentang: `extract` (baca ulang), `block`
     * (vonis manusia "bukan paket"), `unblock` (buang blokirnya).
     *
     * Satu endpoint tiga aksi, sama seperti /accounts/bulk: yang beda cuma satu
     * cabang di dalam loop, dan semuanya memanggil method yang sama dengan tombol
     * per barisnya — tidak ada jalur kedua yang bisa menyimpang.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => 'required|in:extract,block,unblock',
            'media' => 'required|array',
            'media.*' => 'string',
        ]);

        // Hapus blokir: barisnya `excluded_posts` dibuang, itu saja. Filenya sudah
        // dihapus saat diblokir dan TIDAK di-download di sini — yang tadinya
        // ditahan barisnya sekarang boleh diambil lagi oleh fetch berikutnya.
        if ($data['action'] === 'unblock') {
            $n = DB::table('excluded_posts')->whereIn('media_id', $data['media'])->delete();

            return back()->with('status', "$n blokir dihapus — postnya ikut lagi di scrap berikutnya, "
                .'gambarnya baru ada setelah itu.');
        }

        $kena = 0;
        $lewat = 0;
        foreach ($data['media'] as $media) {
            if ($data['action'] === 'block') {
                $this->blokir($media);
                $kena++;

                continue;
            }

            $this->bacaUlang($media) ? $kena++ : $lewat++;
        }

        $pesan = $data['action'] === 'block'
            ? "$kena post diblokir — filenya dibuang, barisnya di excluded_posts tinggal sebagai penahan fetch."
            : "$kena post masuk antrian ai. Pastikan `php artisan queue:work` jalan.";

        return back()->with('status', $pesan.($lewat ? " $lewat dilewat: rawnya sudah dihapus." : ''));
    }

    /**
     * "Baca ulang AI" satu post — termasuk yang sudah dikecualikan.
     *
     * Gerbang vision TETAP jalan. Tombol ini melepas bloknya (baris
     * `excluded_posts` + jejak bacaan lama) supaya postnya boleh dibaca lagi —
     * bukan memaksakan vonis "ini paket". Yang memastikan sebuah post benar-benar
     * penawaran umroh cuma vision, dan melewatinya berarti caption "chat admin"
     * pun disusun jadi paket. Kalau hasilnya masih ditolak, yang salah promptnya,
     * dan itu yang diperbaiki — bukan gerbangnya yang dimatikan per post.
     */
    public function reextract(string $media): RedirectResponse
    {
        if (! $this->bacaUlang($media)) {
            return back()->with('status', "Raw post $media sudah dihapus — gambarnya harus di-download ulang dulu lewat tombol scrap paksa di daftar akun.");
        }

        return back()->with('status', "Post $media masuk antrian ai — gambar + caption dibaca ulang lewat gerbang vision. "
            .'Pastikan `php artisan queue:work` jalan.');
    }

    /**
     * Halaman usulan: satu form post, plus daftar kiriman sendiri + statusnya.
     *
     * Satu halaman satu perilaku untuk kedua peran — admin tidak punya jalur
     * "tambah post langsung" lagi (lihat `store()`), jadi tidak ada cabang di sini.
     *
     * Daftarnya disaring dengan `oleh` (= `_created_by`), BUKAN `usulan`
     * (= `_suggested_by`): yang kedua dibuang begitu admin menyetujui, jadi kiriman
     * yang sudah jalan akan menghilang dari daftar pengirimnya sendiri justru pada
     * saat statusnya mulai menarik. `_created_by` itu jejak permanen.
     */
    public function create(Request $request): View
    {
        $email = $request->user()->email;

        return view('suggest', [
            'accounts' => SourceAccount::orderBy('username')->pluck('username'),
            'akunSaya' => SourceAccount::where('suggested_by', $email)->orderByDesc('id')->get(),
            'postSaya' => $this->kumpulkan(null)->where('oleh', $email)->values(),
        ]);
    }

    /**
     * Post yang dimasukkan tangan: gambar + caption + permalink + tanggal posting.
     *
     * Ini jalan keluar untuk yang TIDAK bisa dijangkau `fetch`. `business_discovery`
     * mengembalikan media urut timestamp turun — pinned post tidak diangkat ke atas
     * seperti di grid instagram.com, jadi flyer yang di-pin 19 minggu lalu duduk di
     * posisi ke-300-an dan tidak pernah kesentuh `--limit=9`. Paging mundur sejauh itu
     * membakar `total_time` untuk ratusan post yang tidak dipakai; satu form tidak
     * menyentuh kuota Graph sama sekali.
     *
     * Yang ditulis di sini cuma `storage/raw/{user}/{media}/` persis seperti
     * `savePost()` di probe.php — sesudah itu tidak ada jalur khusus: `ExtractPost`
     * membacanya seperti post hasil scrap, gerbang vision tetap menilai, dan paketnya
     * tetap mendarat sebagai `draft`/`review`. Tidak ada yang bisa langsung publish
     * lewat sini.
     *
     * **Semua kiriman jadi usulan, termasuk kiriman admin.** Dulu admin punya jalur
     * cepat (akun langsung `approved`, `ExtractPost` langsung dilempar, kiriman ulang
     * menimpa) — itu tiga cabang `if ($admin)` di satu method untuk satu formulir,
     * dan tiga kesempatan supaya dua peran diam-diam berperilaku beda atas markup
     * yang sama. Sekarang satu jalur: tulis raw, tandai `_suggested_by`, selesai.
     * Approval-nya satu tombol di /posts tab **usulan** — yang untuk admin berarti
     * satu klik tambahan, dan itu harga yang murah untuk satu perilaku.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'permalink' => ['required', 'string', 'max:2000'],
            'account' => ['required', 'string', 'max:255'],
            // Wajib, dan sengaja tidak didefault ke hari ini: tanggal posting itu
            // jangkar tahun buat penyusun ("14 Maret" tanpa tahun). Jangkar yang salah
            // diam-diam menggeser paket 2027 jadi 2026 dan lolos ambang keberangkatan.
            'posted_at' => ['required', 'date'],
            'caption' => ['nullable', 'string', 'max:20000'],
            'images' => ['required', 'array', 'max:20'],
            'images.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:12288'],
        ]);

        $media = self::mediaIdOf($data['permalink']);
        $user = SourceAccount::usernameOf($data['account']);

        if ($media === null) {
            throw ValidationException::withMessages(['permalink' => 'Bukan URL post Instagram. Bentuknya https://www.instagram.com/p/XXXXXXXXXXX/']);
        }
        if ($user === null) {
            throw ValidationException::withMessages(['account' => 'Username tidak terbaca. Isi handle-nya saja, URL profil, atau @handle.']);
        }

        // Usulan tidak pernah menimpa apa pun, siapa pun yang mengirim. Kiriman
        // ulang yang menimpa berarti membuang raw + hasil ekstraksi + baris paket
        // se-media_id — itu tombol hapus paket yang sudah di-review, cuma lewat
        // pintu lain, dan pintu ini terbuka untuk semua yang bisa login.
        //
        // Post yang sudah ada dibetulkan dari /posts (baca ulang / blokir / hapus
        // blokir), bukan dengan mengirim ulang: di situ yang menekan sudah melihat
        // barisnya dan aksinya bernama sesuai akibatnya.
        if ($this->akun($media) !== null) {
            throw ValidationException::withMessages([
                'permalink' => "Post $media sudah ada di sistem. Kalau datanya salah, minta admin membacanya ulang dari halaman post.",
            ]);
        }

        // Akunnya juga `pending`, termasuk kiriman admin: approval-nya satu klik di
        // /accounts, dan cabang "kalau admin langsung approved" berarti dua perilaku
        // untuk satu formulir. Yang mau akun langsung jalan pakai textarea /accounts.
        SourceAccount::firstOrCreate(['username' => $user], [
            'status' => 'pending',
            'suggested_by' => $request->user()->email,
        ]);

        $dir = storage_path("raw/$user/$media");
        File::ensureDirectoryExists($dir);

        $names = [];
        foreach (array_values($request->file('images')) as $upload) {
            if ($name = $this->simpanJpg($upload, $dir, count($names))) {
                $names[] = $name;
            }
        }

        if ($names === []) {
            File::deleteDirectory($dir);

            throw ValidationException::withMessages(['images' => 'Tidak ada gambar yang bisa dibaca.']);
        }

        // Bentuknya wajib sama dengan savePost() di probe.php — itu yang dibaca
        // claimImages(), ExtractPending, dan kumpulkan() di halaman ini.
        File::put("$dir/post.json", json_encode([
            'id' => $media,
            'caption' => (string) ($data['caption'] ?? ''),
            'media_type' => count($names) > 1 ? 'CAROUSEL_ALBUM' : 'IMAGE',
            'permalink' => $data['permalink'],
            'timestamp' => Carbon::parse($data['posted_at'])->format('Y-m-d\TH:i:sO'),
            '_source_account' => $user,
            '_images' => $names,
            '_fetched_at' => now()->toIso8601String(),
            // Satu-satunya jejak bahwa post ini tidak lewat Graph API. Fetch tidak
            // pernah menimpanya (folder yang sudah ada dilewat), jadi penandanya tetap.
            '_manual' => true,
            // Siapa yang mengirim. Beda dengan `_suggested_by` yang dibuang saat
            // di-approve: ini jejak permanen, ditulis untuk admin maupun peran user.
            '_created_by' => $request->user()->email,
            // Penanda "usulan, belum di-approve" — ditulis untuk SEMUA peran. Selama
            // kuncinya ada, tidak satu pun pemindai (ExtractPending, `probe.php
            // extract`) mengirimkannya ke model. Dibuang saat disetujui di
            // `bacaUlang()`, dan itu satu-satunya jalan post ini masuk antrian ai.
            '_suggested_by' => $request->user()->email,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Pesannya sengaja tanpa media_id, nama antrian, dan perintah artisan:
        // yang mengirim perlu tahu kirimannya masuk dan apa langkah berikutnya,
        // bukan nama komponen yang mengerjakannya. Jejak teknisnya sudah ada di
        // /posts dan panel pipeline, buat yang memang mencarinya.
        return redirect()->route('suggestions')->with('status', sprintf(
            'Usulan post @%s (%d gambar) tersimpan dan menunggu disetujui.',
            $user, count($names),
        ));
    }

    /**
     * Simpan satu upload sebagai `{n}.jpg`. Seluruh pipeline menamai slide
     * `{index}.jpg` (claimImages, promoteFlyer, route flyer/raw), jadi png/webp
     * kiriman contributor di-encode ulang di sini, bukan disimpan apa adanya.
     *
     * Diratakan ke putih dulu: imagejpeg() membuang kanal alpha, dan screenshot png
     * yang transparan keluar berlatar hitam — flyer yang tulisannya hitam jadi tidak
     * terbaca sama sekali oleh vision.
     */
    private function simpanJpg(UploadedFile $upload, string $dir, int $n): ?string
    {
        $src = @imagecreatefromstring((string) file_get_contents($upload->getRealPath()));
        if ($src === false) {
            return null;
        }

        $flat = imagecreatetruecolor(imagesx($src), imagesy($src));
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $src, 0, 0, 0, 0, imagesx($src), imagesy($src));
        imagejpeg($flat, "$dir/$n.jpg", 90);
        imagedestroy($flat);
        imagedestroy($src);

        return "$n.jpg";
    }

    /**
     * Permalink post -> `media_id` numerik.
     *
     * Shortcode Instagram itu base64 URL-safe dari pk media, jadi dibalik tanpa
     * memanggil API sama sekali — dan hasilnya deterministik, jadi dua contributor
     * yang mengirim post yang sama dapat `media_id` yang sama dan kiriman kedua
     * menimpa yang pertama alih-alih lahir jadi baris kedua.
     *
     * Wajib angka, bukan `manual_<shortcode>`: route flyer dan raw dikunci
     * `whereNumber`, jadi id non-numerik berarti kartunya tampil tanpa gambar.
     */
    private static function mediaIdOf(string $url): ?string
    {
        // Segmen username di depan itu opsional: Instagram melayani post yang sama
        // sebagai /p/{kode}/ maupun /{handle}/p/{kode}/, dan yang kedua itu yang
        // ter-copy dari beberapa tampilan. Kodenya sama, jadi media_id-nya sama.
        if (! preg_match('~instagram\.com/(?:[A-Za-z0-9._]+/)?(?:p|reel|tv)/([A-Za-z0-9_-]+)~', trim($url), $m)) {
            return null;
        }

        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        $pk = 0;
        foreach (str_split($m[1]) as $char) {
            if (($i = strpos($alphabet, $char)) === false) {
                return null;
            }
            $pk = $pk * 64 + $i;
            // Overflow int 64-bit balik jadi float dan (string) $pk keluar sebagai
            // notasi ilmiah. Shortcode sepanjang itu bukan post Instagram.
            if (! is_int($pk)) {
                return null;
            }
        }

        return (string) $pk;
    }

    /**
     * Gambar mentah satu slide, buat melihat apa yang terunduh dan kenapa ditolak.
     *
     * ponytail: dilayani apa adanya tanpa thumbnail — alat kerja operator, bukan
     * halaman publik; pasang cache seperti FlyerThumbController kalau jadi berat.
     */
    public function raw(string $media, string $index): BinaryFileResponse
    {
        $files = glob(storage_path("raw/*/$media/$index.jpg")) ?: [];
        abort_if($files === [], 404);

        return response()->file($files[0]);
    }

    /**
     * Chrome extension sebagai zip, dirakit dari folder `extension/` saat diunduh.
     *
     * Tidak di-cache dan tidak ada langkah build: foldernya belasan KB, dan zip
     * basi sesudah satu file diedit itu bug yang baru kelihatan waktu extension-nya
     * dipakai — jauh dari tempat salahnya.
     */
    public function extension(): BinaryFileResponse
    {
        $files = File::files(base_path('extension'));
        abort_if($files === [], 404);

        $zip = storage_path('app/umroh-sakti-extension.zip');
        File::ensureDirectoryExists(dirname($zip));
        File::delete($zip);

        $arsip = new \ZipArchive;
        abort_unless($arsip->open($zip, \ZipArchive::CREATE) === true, 500);

        foreach ($files as $file) {
            $arsip->addFile($file->getPathname(), $file->getFilename());
        }

        $arsip->close();

        return response()->download($zip)->deleteFileAfterSend();
    }

    /**
     * Post + statusnya, dirakit dari tiga sumber: folder raw (post.json + jpg),
     * `excluded_posts` (post yang rawnya sudah dibuang tetap harus kelihatan), dan
     * baris paket. Tanpa `$user` = seluruh akun.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function kumpulkan(?string $user): Collection
    {
        $ditolak = DB::table('excluded_posts')
            ->when($user, fn ($q) => $q->where('source_account', $user))
            ->pluck('reason', 'media_id');
        $paket = Package::when($user, fn ($q) => $q->where('source_account', $user))
            ->get()->groupBy('media_id');

        $posts = [];
        foreach (glob(storage_path('raw/'.($user ?? '*').'/*/post.json')) ?: [] as $path) {
            $media = basename(dirname($path));
            $json = json_decode((string) file_get_contents($path), true) ?: [];
            $posts[$media] = [
                'media_id' => $media,
                'account' => basename(dirname(dirname($path))),
                'caption' => $json['caption'] ?? '',
                'permalink' => $json['permalink'] ?? null,
                'timestamp' => $json['timestamp'] ?? null,
                // Terisi = usulan yang belum di-approve; isinya email pengusulnya.
                'usulan' => $json['_suggested_by'] ?? null,
                // Null = hasil scrap. Terisi = dimasukkan tangan; email pengirimnya
                // kalau ada (post manual lama cuma punya `_manual`).
                'oleh' => isset($json['_manual']) ? ($json['_created_by'] ?? 'manual') : null,
                'images' => array_map(
                    fn ($p) => route('posts.raw', [$media, (int) basename($p, '.jpg')]),
                    glob(dirname($path).'/*.jpg') ?: [],
                ),
            ];
        }
        foreach ($ditolak as $media => $reason) {
            $posts[$media] ??= ['media_id' => (string) $media, 'account' => $user, 'caption' => '',
                'permalink' => null, 'timestamp' => null, 'images' => [], 'usulan' => null, 'oleh' => null];
        }

        return collect($posts)->map(function ($p) use ($ditolak, $paket) {
            $p['alasan'] = $ditolak[$p['media_id']] ?? null;
            $p['paket'] = $paket[$p['media_id']] ?? collect();
            $p['account'] ??= $p['paket']->first()?->source_account;

            // Raw kosong TIDAK berarti postnya ditolak: gambar yang jadi paket
            // dipindah ke disk `flyers` oleh promoteFlyer dan raw-nya dihapus
            // (post.json sengaja ditinggal). Ambil dari paketnya kalau begitu.
            $p['images'] = $p['images'] ?: $p['paket']->flatMap->flyers()->all();

            return $p;
        })->sortByDesc('timestamp')->values();
    }

    /**
     * Lepas blok + jejak bacaan lama satu post, lalu antrikan ke `ai`.
     *
     * Urutannya: baris `excluded_posts` dilepas dulu (itu yang bikin extract
     * melewatinya), hasil ekstraksi lama dihapus, baris paket se-`media_id`
     * dikembalikan flyernya lalu dihapus (`importOne()` tidak pernah menimpa baris
     * yang sudah ada). Se-`media_id`, bukan satu slide: nomor slide dari vision
     * menunjuk gambar ke-N yang DIKIRIM, jadi sisa baris/gambar lama bikin
     * penomorannya tabrakan.
     *
     * False kalau rawnya sudah tidak ada — tanpa gambar tidak ada yang dibaca, dan
     * satu klik per post tidak boleh membakar kuota Graph untuk seluruh akun.
     * Download ulang tombolnya sendiri: scrap paksa di /accounts.
     */
    private function bacaUlang(string $media): bool
    {
        $user = $this->akun($media);

        if ($user === null || ! is_file($post = storage_path("raw/$user/$media/post.json"))) {
            return false;
        }

        // Tombol ini sekaligus "setujui usulan": penandanya dibuang di sini, jadi
        // pemindai (ExtractPending, `probe.php extract`) ikut mengambilnya lagi
        // kalau job ini gagal. Approval memang cuma ini — sesudahnya jalurnya sama
        // persis dengan post hasil scrap, gerbang vision dan semua.
        $json = json_decode((string) file_get_contents($post), true) ?: [];
        if (isset($json['_suggested_by'])) {
            unset($json['_suggested_by']);
            File::put($post, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        DB::table('excluded_posts')->where('media_id', $media)->delete();

        foreach (Package::where('media_id', $media)->get() as $slide) {
            $slide->restoreFlyer();
            $slide->delete();
        }

        foreach (glob(storage_path("extracted/{$media}{.json,-*.json}"), GLOB_BRACE) ?: [] as $file) {
            File::delete($file);
        }

        ExtractPost::dispatch($media);

        return true;
    }

    /**
     * Vonis manusia "ini bukan paket": barisnya `excluded_posts` jadi `manual` dan
     * filenya baru dibuang di sini.
     *
     * Import sengaja menahan raw post `bukan_paket` — itu vonis mesin dan yang
     * paling sering salah. Begitu operator mengonfirmasinya, menahan bytenya tidak
     * ada gunanya lagi: yang menjaga post ini tidak di-fetch & di-extract ulang
     * tetap barisnya, bukan filenya.
     */
    private function blokir(string $media): void
    {
        $user = $this->akun($media);

        ExcludedPost::add($media, $user, 'manual');
        $this->hapusJejak($media, $user);
    }

    /**
     * Hapus semua jejak satu post: baris paket (+ flyer yang sudah dipromosikan),
     * folder raw, dan hasil ekstraksinya. Baris `excluded_posts` TIDAK disentuh —
     * pemanggilnya yang menentukan arahnya: `blokir()` justru baru menambahkannya,
     * `store()` justru melepasnya.
     *
     * Se-`media_id`, bukan satu slide: nomor slide dari vision menunjuk gambar ke-N
     * yang DIKIRIM, jadi sisa baris atau gambar lama bikin penomorannya tabrakan.
     */
    private function hapusJejak(string $media, ?string $user): void
    {
        foreach (Package::where('media_id', $media)->get() as $slide) {
            $slide->deleteSources();
            $slide->delete();
        }

        if ($user !== null) {
            File::deleteDirectory(storage_path("raw/$user/$media"));
        }

        foreach (glob(storage_path("extracted/{$media}{.json,-*.json}"), GLOB_BRACE) ?: [] as $file) {
            File::delete($file);
        }
    }

    /**
     * Akun pemilik satu post. Raw dulu (itu yang menentukan letak filenya), lalu
     * dua tabel — post yang rawnya sudah dibuang tetap harus bisa ditindak.
     */
    private function akun(string $media): ?string
    {
        if ($files = glob(storage_path("raw/*/$media/post.json")) ?: []) {
            return basename(dirname(dirname($files[0])));
        }

        return DB::table('excluded_posts')->where('media_id', $media)->value('source_account')
            ?? Package::where('media_id', $media)->value('source_account');
    }
}
