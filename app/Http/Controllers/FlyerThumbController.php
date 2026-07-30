<?php

namespace App\Http\Controllers;

use App\Models\Package;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Thumbnail flyer untuk tampilan publik.
 *
 * Flyer full TIDAK di-re-host: yang keluar dari sini selalu versi kecil
 * (lebar maks WIDTH px, kualitas 75). Gambar aslinya tetap di storage/raw
 * untuk ekstraksi & audit internal saja. Sumber tetap ditautkan ke post asli.
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
            $package->status === 'published' || (app()->isLocal() && $request->boolean('semua', true)),
            404,
        );

        $source = storage_path("raw/{$package->source_account}/{$media}/{$index}.jpg");
        abort_unless(is_file($source), 404);

        $cache = storage_path("app/thumbs/{$media}-{$index}.jpg");
        if (! is_file($cache) || filemtime($cache) < filemtime($source)) {
            @mkdir(dirname($cache), 0775, true);
            $image = @imagecreatefromstring((string) file_get_contents($source));
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
