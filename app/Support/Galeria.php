<?php

namespace App\Support;

use App\Models\Producto;
use App\Models\ProductoImagen;

/** Altas y bajas de fotos en la galería de un producto (foto grande + miniatura). */
class Galeria
{
    public const ANCHO = 1400;
    public const ANCHO_MINIATURA = 480;

    public static function agregar(Producto $producto, string $archivo): ProductoImagen
    {
        $carpeta = 'productos/'.$producto->n_producto;
        $grande = Imagenes::guardar($archivo, $carpeta, self::ANCHO);
        $miniatura = Imagenes::guardar($archivo, $carpeta, self::ANCHO_MINIATURA, 76, basename($grande['ruta'], '.webp').'-min');

        return ProductoImagen::create([
            'n_producto' => $producto->n_producto,
            'x_archivo' => $grande['ruta'],
            'x_miniatura' => $miniatura['ruta'],
            'x_alt' => $producto->x_producto,
            'n_ancho' => $grande['ancho'],
            'n_alto' => $grande['alto'],
            'n_orden' => (int) ProductoImagen::where('n_producto', $producto->n_producto)->max('n_orden') + 1,
        ]);
    }

    public static function quitar(ProductoImagen $imagen): void
    {
        Imagenes::borrar($imagen->x_archivo, $imagen->x_miniatura);
        $imagen->delete();
    }
}
