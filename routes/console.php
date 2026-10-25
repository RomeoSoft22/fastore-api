<?php

use App\Models\Banner;
use App\Support\Imagenes;
use App\Support\ImportadorImagenes;
use Illuminate\Support\Facades\Artisan;

/*
 * Copia las fotos del sitio anterior (img/productos/{id}/1..5.jpg) a la galería de cada producto.
 *   php artisan fastore:importar-imagenes "C:\ruta\a\img\productos"
 * Los productos que ya tienen galería se omiten (usa --reemplazar para rehacerla).
 */
Artisan::command('fastore:importar-imagenes {origen} {--reemplazar}', function (string $origen) {
    $resumen = ImportadorImagenes::desdeCarpeta($origen, (bool) $this->option('reemplazar'), fn (string $linea) => $this->line($linea));
    $this->info("Productos con galería nueva: {$resumen['productos']} · Imágenes: {$resumen['imagenes']}");
})->purpose('Importa las fotos de productos del sitio anterior');

/*
 * Carga los banners que genera fastore-web/herramientas/generar-banners.mjs (carpeta con banners.json).
 *   php artisan fastore:banners "C:\...\fastore-web\herramientas\banners"
 */
Artisan::command('fastore:banners {carpeta}', function (string $carpeta) {
    $manifiesto = json_decode((string) @file_get_contents($carpeta.'/banners.json'), true);
    if (! is_array($manifiesto)) {
        $this->error("No encontré banners.json en {$carpeta}");

        return 1;
    }
    foreach ($manifiesto as $i => $b) {
        if (Banner::where('x_titulo', $b['titulo'])->exists()) {
            $this->line("Ya existe: {$b['titulo']}");
            continue;
        }
        Banner::create([
            'x_titulo' => $b['titulo'],
            'x_subtitulo' => $b['subtitulo'] ?? null,
            'x_boton' => $b['boton'] ?? null,
            'x_enlace' => $b['enlace'] ?? null,
            'x_tema' => $b['tema'] ?? 'claro',
            'x_imagen' => Imagenes::guardar($carpeta.'/'.$b['imagen'], 'banners', 1920, 84)['ruta'],
            'x_imagen_movil' => ! empty($b['imagen_movil']) ? Imagenes::guardar($carpeta.'/'.$b['imagen_movil'], 'banners', 900, 82)['ruta'] : null,
            'n_orden' => (int) Banner::max('n_orden') + 1,
        ]);
        $this->info("Banner creado: {$b['titulo']}");
    }
})->purpose('Carga los banners iniciales de la portada');
