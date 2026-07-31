<?php

namespace App\Http\Controllers;

use App\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Thumbnail flyer untuk tampilan publik.
 *
 * Flyer full TIDAK di-re-host: yang keluar dari sini selalu versi kecil
 * (lebar maks WIDTH px, kualitas 75). Gambar aslinya di disk `flyers`
 * (storage/flyers saat lokal, s3 di produksi) untuk audit internal saja.
 * Sumber tetap ditautkan ke post asli.
 */
class FlyerThumbController extends Controller
{
    private const WIDTH = 480;

    public function __invoke(Request $request, string $media, int $index): BinaryFileResponse
    {
        // Hanya gambar milik paket yang boleh dilihat. Aturan visibilitasnya sama
        // dengan halaman detail: published, atau pratinjau lokal.
        // Satu carousel bisa jadi beberapa paket, masing-masing menunjuk satu gambar.
        // Cocokkan nomornya dulu; jalur lama (tanpa flyer_index) jatuh ke media saja.
        $package = Package::where('media_id', $media)->where('flyer_index', $index)->first()
            ?? Package::where('media_id', $media)->firstOrFail();
        abort_unless(
            $package->status === 'published' || $request->user()?->isAdmin(),
            404,
        );

        // Flyer paket yang sudah diimpor ada di disk `flyers` (local atau s3);
        // baris lama yang belum dipromosikan masih di storage/raw.
        $raw = storage_path("raw/{$package->source_account}/{$media}/{$index}.jpg");
        $cache = storage_path("app/thumbs/{$media}-{$index}.jpg");

        // Thumbnail-nya di-cache lokal — kalau tidak, tiap kartu jadi satu GET ke s3.
        if (! is_file($cache) || (is_file($raw) && filemtime($cache) < filemtime($raw))) {
            $bytes = is_file($raw)
                ? file_get_contents($raw)
                : Storage::disk(Package::FLYER_DISK)->get("{$media}/{$index}.jpg");
            abort_if($bytes === null || $bytes === false, 404);

            @mkdir(dirname($cache), 0775, true);
            $image = @imagecreatefromstring((string) $bytes);
            abort_unless($image !== false, 404);
            if (imagesx($image) > self::WIDTH) {
                $image = imagescale($image, self::WIDTH);
            }
            imagejpeg($image, $cache, 75);
            imagedestroy($image);
        }

        return response()->file($cache, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
