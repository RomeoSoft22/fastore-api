<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\Producto;
use App\Support\Catalogo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Metadatos para buscadores y redes sociales. fastore-web/seo.php los pide para cada URL y los
 * escribe en el <head> antes de entregar la página: así WhatsApp, Facebook y Google leen el
 * nombre, precio, descripción e imagen del producto sin ejecutar JavaScript.
 */
class SeoController extends Controller
{
    public function meta(Request $request): JsonResponse
    {
        $ruta = '/'.trim((string) $request->query('ruta', '/'), '/');
        $url = config('tienda.url');
        $nombre = config('tienda.nombre');

        $meta = [
            'title' => "{$nombre} | Tecnología, NFC y coleccionables en Lima",
            'description' => config('tienda.descripcion'),
            'image' => $url.'/img/social.jpg',
            'image_alt' => $nombre,
            'url' => $url.($ruta === '/' ? '/' : $ruta),
            'type' => 'website',
            'robots' => 'index, follow',
            'jsonld' => [[
                '@context' => 'https://schema.org',
                '@type' => 'OnlineStore',
                'name' => $nombre,
                'url' => $url.'/',
                'logo' => $url.'/img/logo.png',
                'contactPoint' => ['@type' => 'ContactPoint', 'telephone' => '+'.config('tienda.whatsapp'), 'contactType' => 'sales', 'areaServed' => 'PE', 'availableLanguage' => 'es'],
            ]],
            'status' => 200,
        ];

        if (preg_match('#^/producto/([^/]+)$#', $ruta, $m)) {
            return response()->json($this->producto($meta, $m[1]));
        }
        if (preg_match('#^/categoria/([^/]+)$#', $ruta, $m)) {
            $categoria = Categoria::where('x_slug', $m[1])->where('l_activo', '1')->first();
            if (! $categoria) {
                return response()->json(['status' => 404, 'robots' => 'noindex'] + $meta);
            }
            $datos = Catalogo::categoria($categoria);
            $meta['title'] = "{$datos['nombre']} | {$nombre}";
            $meta['description'] = $categoria->x_descripcion ?: "Compra {$datos['nombre']} en {$nombre}. Entrega en estaciones del tren de Lima.";
        } elseif (in_array($ruta, ['/carrito', '/callback']) || str_starts_with($ruta, '/admin')) {
            $meta['robots'] = 'noindex, nofollow';
        } elseif ($ruta === '/tienda') {
            $meta['title'] = "Catálogo | {$nombre}";
        }

        return response()->json($meta);
    }

    public function sitemap(): Response
    {
        $url = config('tienda.url');
        $entradas = [['loc' => $url.'/', 'prioridad' => '1.0'], ['loc' => $url.'/tienda', 'prioridad' => '0.8']];
        foreach (Categoria::where('l_activo', '1')->whereNotNull('x_slug')->get() as $c) {
            $entradas[] = ['loc' => $url.'/categoria/'.$c->x_slug, 'prioridad' => '0.7'];
        }
        foreach (Producto::visibles()->whereNotNull('x_slug')->with('imagenes')->get() as $p) {
            $entradas[] = [
                'loc' => $url.'/producto/'.$p->x_slug,
                'prioridad' => $p->l_estado === '0' ? '0.4' : '0.9',
                'mod' => $p->f_registro?->toDateString(),
                'imagenes' => $p->imagenes->take(5)->map(fn ($i) => $i->datos()['url'])->all(),
            ];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'."\n";
        foreach ($entradas as $e) {
            $xml .= '  <url><loc>'.e($e['loc']).'</loc>'.(isset($e['mod']) ? '<lastmod>'.$e['mod'].'</lastmod>' : '').'<priority>'.$e['prioridad'].'</priority>';
            foreach ($e['imagenes'] ?? [] as $imagen) {
                $xml .= '<image:image><image:loc>'.e($imagen).'</image:loc></image:image>';
            }
            $xml .= "</url>\n";
        }

        return response($xml.'</urlset>', 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    private function producto(array $meta, string $slug): array
    {
        $producto = Producto::visibles()->with(['marca', 'categoria', 'imagenes'])
            ->where(fn ($q) => $q->where('x_slug', $slug)->when(ctype_digit($slug), fn ($q) => $q->orWhere('n_producto', (int) $slug)))
            ->first();
        if (! $producto) {
            return ['status' => 404, 'robots' => 'noindex', 'title' => 'Producto no encontrado | '.config('tienda.nombre')] + $meta;
        }

        $p = Catalogo::producto($producto, true);
        $precio = number_format($p['precio_final'], 2, '.', '');
        $disponibilidad = ['disponible' => 'InStock', 'a_pedido' => 'PreOrder', 'agotado' => 'OutOfStock'][$p['estado']];
        $imagenes = collect($p['imagenes'])->pluck('url')->all();
        $url = config('tienda.url').'/producto/'.$p['slug'];

        return array_merge($meta, [
            'title' => Str::limit($p['nombre'], 60, '…').' | S/ '.$precio.' | '.config('tienda.nombre'),
            'description' => Str::limit(($p['estado'] === 'agotado' ? 'Agotado. ' : 'S/ '.$precio.' · ').$p['resumen'], 158),
            // Versión JPEG: WhatsApp no siempre muestra la vista previa de imágenes WebP.
            'image' => isset($imagenes[0]) ? preg_replace('/\.webp$/', '.jpg', $imagenes[0]) : $meta['image'],
            'image_alt' => $p['nombre'],
            'image_width' => $p['imagenes'][0]['ancho'] ?? null,
            'image_height' => $p['imagenes'][0]['alto'] ?? null,
            'url' => $url,
            'type' => 'product',
            'keywords' => implode(', ', $p['etiquetas']),
            'tags' => $p['etiquetas'],
            'product' => ['price' => $precio, 'currency' => config('tienda.moneda'), 'availability' => strtolower($disponibilidad), 'brand' => $p['marca']['nombre'] ?? null],
            'jsonld' => [[
                '@context' => 'https://schema.org',
                '@type' => 'Product',
                'name' => $p['nombre'],
                'description' => Str::limit($p['descripcion_texto'], 4900),
                'keywords' => implode(', ', $p['etiquetas']) ?: null,
                'image' => $imagenes,
                'sku' => 'FAS-'.$p['id'],
                'brand' => ['@type' => 'Brand', 'name' => $p['marca']['nombre'] ?? config('tienda.nombre')],
                'category' => $p['categoria']['nombre'] ?? null,
                'itemCondition' => $p['condicion'] === 'usado' ? 'https://schema.org/UsedCondition' : 'https://schema.org/NewCondition',
                'offers' => [
                    '@type' => 'Offer',
                    'url' => $url,
                    'priceCurrency' => config('tienda.moneda'),
                    'price' => $precio,
                    'availability' => 'https://schema.org/'.$disponibilidad,
                    'seller' => ['@type' => 'Organization', 'name' => config('tienda.nombre')],
                ],
            ], [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => array_values(array_filter([
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Inicio', 'item' => config('tienda.url').'/'],
                    $p['categoria'] ? ['@type' => 'ListItem', 'position' => 2, 'name' => $p['categoria']['nombre'], 'item' => config('tienda.url').'/categoria/'.$p['categoria']['slug']] : null,
                    ['@type' => 'ListItem', 'position' => $p['categoria'] ? 3 : 2, 'name' => $p['nombre'], 'item' => $url],
                ])),
            ]],
        ]);
    }
}
