<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Imagenes;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sirve las imágenes de productos y banners. Los nombres son únicos: se cachean un año.
 * Pedir la misma ruta con .jpg entrega una copia JPEG (se genera una vez): es la que se usa en
 * og:image, porque WhatsApp no siempre muestra la vista previa de imágenes WebP.
 */
class MediaController extends Controller
{
    public function mostrar(string $ruta): Response
    {
        abort_unless(preg_match('#^(productos|banners)/[A-Za-z0-9/_-]+\.(webp|jpg)$#', $ruta), 404);
        $disco = Storage::disk(Imagenes::DISCO);

        if (str_ends_with($ruta, '.jpg') && ! $disco->exists($ruta)) {
            $original = substr($ruta, 0, -4).'.webp';
            abort_unless($disco->exists($original), 404);
            $imagen = imagecreatefromwebp($disco->path($original));
            // Fondo blanco para las zonas transparentes (JPEG no tiene canal alfa).
            $lienzo = imagecreatetruecolor(imagesx($imagen), imagesy($imagen));
            imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 255, 255, 255));
            imagecopy($lienzo, $imagen, 0, 0, 0, 0, imagesx($imagen), imagesy($imagen));
            ob_start();
            imagejpeg($lienzo, null, 85);
            $disco->put($ruta, ob_get_clean());
        }
        abort_unless($disco->exists($ruta), 404);

        return $disco->response($ruta, null, [
            'Content-Type' => str_ends_with($ruta, '.jpg') ? 'image/jpeg' : 'image/webp',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
