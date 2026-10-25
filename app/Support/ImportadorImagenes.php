<?php

namespace App\Support;

use App\Models\Producto;
use Closure;
use Throwable;

/** Pasa las fotos del sitio anterior (img/productos/{id}/1.jpg, 2.jpg…) a la galería nueva. */
class ImportadorImagenes
{
    /** @return array{productos: int, imagenes: int} */
    public static function desdeCarpeta(string $origen, bool $reemplazar, Closure $log): array
    {
        $resumen = ['productos' => 0, 'imagenes' => 0];
        if (! is_dir($origen)) {
            $log("No existe la carpeta {$origen}");

            return $resumen;
        }

        foreach (Producto::with('imagenes')->orderBy('n_producto')->get() as $producto) {
            $archivos = glob(rtrim($origen, '/\\').'/'.$producto->n_producto.'/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}', GLOB_BRACE) ?: [];
            natsort($archivos);
            if (! $archivos) {
                continue;
            }
            if ($producto->imagenes->isNotEmpty()) {
                if (! $reemplazar) {
                    $log("#{$producto->n_producto} ya tiene galería: se omite");
                    continue;
                }
                $producto->imagenes->each(fn ($imagen) => Galeria::quitar($imagen));
            }

            foreach ($archivos as $archivo) {
                try {
                    Galeria::agregar($producto, $archivo);
                    $resumen['imagenes']++;
                } catch (Throwable $e) {
                    $log("#{$producto->n_producto} ".basename($archivo).': '.$e->getMessage());
                }
            }
            $resumen['productos']++;
            $log("#{$producto->n_producto} {$producto->x_producto}: ".count($archivos).' foto(s)');
        }

        return $resumen;
    }
}
