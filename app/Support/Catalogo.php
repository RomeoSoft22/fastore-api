<?php

namespace App\Support;

use App\Models\Categoria;
use App\Models\Marca;
use App\Models\Producto;
use Illuminate\Support\Str;

/** Formato JSON único de productos, categorías y marcas para la tienda, el admin y el SEO. */
class Catalogo
{
    public static function producto(Producto $p, bool $detalle = false): array
    {
        $portada = $p->relationLoaded('imagenes') ? $p->imagenes->first() : $p->portada;

        $datos = [
            'id' => $p->n_producto,
            'slug' => $p->x_slug,
            'nombre' => $p->x_producto,
            'resumen' => self::resumen($p),
            'precio' => (float) $p->x_precio,
            'descuento' => (int) $p->x_descuento,
            'precio_final' => $p->precioFinal(),
            'estado' => Producto::ESTADOS[$p->l_estado] ?? 'disponible',
            'condicion' => $p->l_producto === 'U' ? 'usado' : 'nuevo',
            'stock' => (int) $p->n_stock,
            'destacado' => $p->l_destacado === '1',
            'marca' => $p->marca ? self::marca($p->marca) : null,
            'categoria' => $p->categoria ? self::categoria($p->categoria) : null,
            'imagen' => $portada?->datos(),
        ];

        if ($detalle) {
            // HTML ya filtrado (las descripciones antiguas en texto plano se convierten a párrafos).
            $datos['descripcion'] = Html::limpiar($p->x_descripcion);
            $datos['descripcion_texto'] = Html::texto($datos['descripcion']);
            $datos['etiquetas'] = self::etiquetas($p);
            $datos['imagenes'] = $p->imagenes->map->datos()->values();
        }

        return $datos;
    }

    /** @return string[] */
    public static function etiquetas(Producto $p): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $p->x_etiquetas))));
    }

    public static function categoria(Categoria $c, ?int $total = null): array
    {
        return array_filter([
            'id' => $c->n_categoria,
            'nombre' => self::nombreCategoria($c->x_categoria),
            'slug' => $c->x_slug,
            'descripcion' => $c->x_descripcion,
            'total' => $total,
        ], fn ($v) => $v !== null);
    }

    public static function marca(Marca $m, ?int $total = null): array
    {
        return array_filter(['id' => $m->n_marca, 'nombre' => $m->x_marca, 'slug' => $m->x_slug, 'total' => $total], fn ($v) => $v !== null);
    }

    /** Las categorías se guardan en mayúsculas: "FIGURAS DE ACCIÓN" → "Figuras de acción", "NFC" se mantiene. */
    public static function nombreCategoria(string $nombre): string
    {
        $palabras = preg_split('/\s+/u', trim($nombre));

        return implode(' ', array_map(fn ($p, $i) => match (true) {
            (mb_strlen($p) <= 3 || preg_match('/\d/', $p)) && ! in_array(Str::lower($p), ['de', 'del', 'la', 'las', 'los', 'el', 'y', 'para', 'con'], true) => $p,
            $i > 0 => Str::lower($p),
            default => Str::ucfirst(Str::lower($p)),
        }, $palabras, array_keys($palabras)));
    }

    /** Texto corto para tarjetas, meta description y Open Graph. */
    public static function resumen(Producto $p): string
    {
        $texto = $p->x_resumen ?: preg_replace('/\s+/u', ' ', Html::texto($p->x_descripcion));

        return Str::limit(trim((string) $texto), 160);
    }
}
