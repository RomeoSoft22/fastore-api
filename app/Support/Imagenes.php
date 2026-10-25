<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Guarda las imágenes subidas en el disco "public" como WebP redimensionado (más livianas para
 * celulares) y sirve sus URLs a través de /api/media. Requiere la extensión GD con WebP.
 */
class Imagenes
{
    public const DISCO = 'public';

    public static function url(?string $ruta): ?string
    {
        return $ruta ? config('tienda.api_url').'/api/media/'.$ruta : null;
    }

    /**
     * Convierte el archivo a WebP con un ancho máximo y lo guarda en $carpeta.
     *
     * @return array{ruta: string, ancho: int, alto: int}
     */
    public static function guardar(string $origen, string $carpeta, int $anchoMaximo, int $calidad = 82, ?string $nombre = null): array
    {
        $imagen = self::abrir($origen);
        [$ancho, $alto] = [imagesx($imagen), imagesy($imagen)];

        if ($ancho > $anchoMaximo) {
            $nuevoAlto = (int) round($alto * $anchoMaximo / $ancho);
            $redimensionada = imagescale($imagen, $anchoMaximo, $nuevoAlto, IMG_BICUBIC);
            imagedestroy($imagen);
            $imagen = $redimensionada;
            [$ancho, $alto] = [$anchoMaximo, $nuevoAlto];
        }

        ob_start();
        imagewebp($imagen, null, $calidad);
        $contenido = ob_get_clean();
        imagedestroy($imagen);

        $ruta = trim($carpeta, '/').'/'.($nombre ?? Str::lower(Str::random(20))).'.webp';
        Storage::disk(self::DISCO)->put($ruta, $contenido);

        return ['ruta' => $ruta, 'ancho' => $ancho, 'alto' => $alto];
    }

    public static function borrar(?string ...$rutas): void
    {
        $rutas = array_filter($rutas);
        if ($rutas) {
            Storage::disk(self::DISCO)->delete($rutas);
        }
    }

    /** @return \GdImage */
    private static function abrir(string $origen)
    {
        $info = @getimagesize($origen);
        $imagen = match ($info[2] ?? null) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($origen),
            IMAGETYPE_PNG => @imagecreatefrompng($origen),
            IMAGETYPE_WEBP => @imagecreatefromwebp($origen),
            IMAGETYPE_GIF => @imagecreatefromgif($origen),
            default => false,
        };
        if (! $imagen) {
            throw ValidationException::withMessages(['imagen' => 'El archivo no es una imagen JPG, PNG, WebP o GIF válida.']);
        }

        // Fotos de celular: respeta la orientación guardada en EXIF.
        if (($info[2] ?? null) === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $giro = match ((int) (@exif_read_data($origen)['Orientation'] ?? 1)) {
                3 => 180, 6 => -90, 8 => 90, default => 0,
            };
            if ($giro !== 0) {
                $imagen = imagerotate($imagen, $giro, 0);
            }
        }

        if (! imageistruecolor($imagen)) {
            imagepalettetotruecolor($imagen);
        }
        imagealphablending($imagen, true);
        imagesavealpha($imagen, true);

        return $imagen;
    }
}
