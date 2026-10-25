<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\Categoria;
use App\Models\Marca;
use App\Models\Producto;
use App\Support\Catalogo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Catálogo público: portada, búsqueda con filtros y detalle de producto. No exige sesión. */
class TiendaController extends Controller
{
    private const PRECIO_FINAL = 'ROUND(mae_productos.x_precio * (100 - LEAST(COALESCE(mae_productos.x_descuento, 0), 99)) / 100, 2)';

    public function config(): JsonResponse
    {
        return response()->json([
            'nombre' => config('tienda.nombre'),
            'descripcion' => config('tienda.descripcion'),
            'url' => config('tienda.url'),
            'whatsapp' => config('tienda.whatsapp'),
            'moneda' => config('tienda.moneda'),
            'estaciones' => config('tienda.estaciones'),
            'pagos' => config('tienda.pagos'),
            'entregas' => collect(config('tienda.entregas'))->map(fn ($e, $clave) => ['clave' => $clave] + $e)->values(),
            'cotizacion_dias' => config('tienda.cotizacion_dias'),
            // Datos del titular para las políticas y el Libro de Reclamaciones.
            'legal' => config('tienda.legal'),
        ]);
    }

    public function inicio(): JsonResponse
    {
        $base = fn () => Producto::visibles()->with(['marca', 'categoria', 'portada']);
        $disponibles = fn (Builder $q) => $q->whereIn('l_estado', ['1', '2']);

        $destacados = $disponibles($base())->where('l_destacado', '1')->orderByDesc('f_creado')->limit(8)->get();
        if ($destacados->count() < 4) {
            // Sin destacados elegidos: los disponibles con más stock.
            $destacados = $destacados->concat($disponibles($base())->whereNotIn('n_producto', $destacados->pluck('n_producto'))
                ->orderByDesc('n_stock')->limit(8 - $destacados->count())->get());
        }

        return response()->json([
            'banners' => Banner::vigentes()->get()->map->datos(),
            'categorias' => $this->categorias(),
            'destacados' => $destacados->map(fn ($p) => Catalogo::producto($p)),
            'novedades' => $disponibles($base())->orderByDesc('f_creado')->orderByDesc('n_producto')->limit(8)->get()->map(fn ($p) => Catalogo::producto($p)),
        ]);
    }

    /**
     * Búsqueda: q, categoria (slug), marca (slugs separados por coma), min, max, disponible (1),
     * condicion (nuevo|usado), orden (relevancia|precio_asc|precio_desc|nuevos|nombre), page, por_pagina.
     * Devuelve también las facetas (conteos por categoría y marca, rango de precios) para los filtros.
     */
    public function productos(Request $request): JsonResponse
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'categoria' => ['nullable', 'string', 'max:80'],
            'marca' => ['nullable', 'string', 'max:300'],
            'min' => ['nullable', 'numeric', 'min:0'],
            'max' => ['nullable', 'numeric', 'min:0'],
            'disponible' => ['nullable', 'boolean'],
            'condicion' => ['nullable', 'in:nuevo,usado'],
            'orden' => ['nullable', 'in:relevancia,precio_asc,precio_desc,nuevos,nombre'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:48'],
        ]);

        // Base con la búsqueda de texto: sobre ella se calculan las facetas.
        $base = Producto::visibles();
        if ($texto = trim((string) ($f['q'] ?? ''))) {
            foreach (preg_split('/\s+/u', $texto) as $palabra) {
                $like = '%'.addcslashes($palabra, '%_\\').'%';
                $base->where(fn ($q) => $q->where('mae_productos.x_producto', 'like', $like)
                    ->orWhere('mae_productos.x_descripcion', 'like', $like)
                    ->orWhere('mae_productos.x_etiquetas', 'like', $like)
                    ->orWhereHas('marca', fn ($m) => $m->where('x_marca', 'like', $like))
                    ->orWhereHas('categoria', fn ($c) => $c->where('x_categoria', 'like', $like)));
            }
        }

        $consulta = (clone $base)->with(['marca', 'categoria', 'portada']);
        $categoria = ! empty($f['categoria']) ? Categoria::where('x_slug', $f['categoria'])->first() : null;
        if (! empty($f['categoria'])) {
            $consulta->where('n_categoria', $categoria?->n_categoria ?? 0);
        }
        if (! empty($f['marca'])) {
            $consulta->whereIn('n_marca', Marca::whereIn('x_slug', explode(',', $f['marca']))->pluck('n_marca'));
        }
        if (isset($f['min'])) {
            $consulta->whereRaw(self::PRECIO_FINAL.' >= ?', [$f['min']]);
        }
        if (isset($f['max'])) {
            $consulta->whereRaw(self::PRECIO_FINAL.' <= ?', [$f['max']]);
        }
        if (! empty($f['disponible'])) {
            $consulta->whereIn('l_estado', ['1', '2']);
        }
        if (! empty($f['condicion'])) {
            $consulta->where('l_producto', $f['condicion'] === 'usado' ? 'U' : 'N');
        }

        match ($f['orden'] ?? 'relevancia') {
            'precio_asc' => $consulta->orderByRaw(self::PRECIO_FINAL.' asc'),
            'precio_desc' => $consulta->orderByRaw(self::PRECIO_FINAL.' desc'),
            'nuevos' => $consulta->orderByDesc('f_creado'),
            'nombre' => $consulta->orderBy('x_producto'),
            default => $consulta->orderByRaw("FIELD(l_estado, '1', '2', '0')")->orderByDesc('l_destacado')->orderByDesc('f_creado'),
        };
        $consulta->orderByDesc('n_producto');

        $pagina = $consulta->paginate((int) ($f['por_pagina'] ?? 12));

        $porCategoria = (clone $base)->select('n_categoria', DB::raw('count(*) as total'))->groupBy('n_categoria')->pluck('total', 'n_categoria');
        $porMarca = (clone $base)->select('n_marca', DB::raw('count(*) as total'))->groupBy('n_marca')->pluck('total', 'n_marca');
        $precios = (clone $base)->selectRaw('MIN('.self::PRECIO_FINAL.') as min, MAX('.self::PRECIO_FINAL.') as max')->first();

        return response()->json([
            'datos' => collect($pagina->items())->map(fn ($p) => Catalogo::producto($p)),
            'pagina' => $pagina->currentPage(),
            'paginas' => $pagina->lastPage(),
            'total' => $pagina->total(),
            'categoria' => $categoria ? Catalogo::categoria($categoria) : null,
            'facetas' => [
                'categorias' => Categoria::where('l_activo', '1')->orderBy('n_orden')->get()
                    ->filter(fn ($c) => isset($porCategoria[$c->n_categoria]))
                    ->map(fn ($c) => Catalogo::categoria($c, (int) $porCategoria[$c->n_categoria]))->values(),
                'marcas' => Marca::where('l_activo', '1')->orderBy('x_marca')->get()
                    ->filter(fn ($m) => isset($porMarca[$m->n_marca]))
                    ->map(fn ($m) => Catalogo::marca($m, (int) $porMarca[$m->n_marca]))->values(),
                'precio' => ['min' => (float) floor((float) $precios->min), 'max' => (float) ceil((float) $precios->max)],
            ],
        ]);
    }

    /** Detalle por slug (o id, para enlaces del sitio anterior) con productos relacionados. */
    public function producto(string $slug): JsonResponse
    {
        $producto = Producto::visibles()->with(['marca', 'categoria', 'imagenes'])
            ->where(fn ($q) => $q->where('x_slug', $slug)->when(ctype_digit($slug), fn ($q) => $q->orWhere('n_producto', (int) $slug)))
            ->firstOrFail();

        $relacionados = Producto::visibles()->with(['marca', 'categoria', 'portada'])
            ->where('n_producto', '!=', $producto->n_producto)
            ->whereIn('l_estado', ['1', '2'])
            ->orderByRaw('CASE WHEN n_categoria = ? THEN 0 WHEN n_marca = ? THEN 1 ELSE 2 END', [(int) $producto->n_categoria, $producto->n_marca])
            ->orderByDesc('f_creado')
            ->limit(8)->get();

        return response()->json([
            'producto' => Catalogo::producto($producto, true),
            'relacionados' => $relacionados->map(fn ($p) => Catalogo::producto($p)),
        ]);
    }

    public function listaCategorias(): JsonResponse
    {
        return response()->json($this->categorias());
    }

    private function categorias(): array
    {
        $totales = Producto::visibles()->select('n_categoria', DB::raw('count(*) as total'))->groupBy('n_categoria')->pluck('total', 'n_categoria');

        return Categoria::where('l_activo', '1')->orderBy('n_orden')->orderBy('x_categoria')->get()
            ->map(fn ($c) => Catalogo::categoria($c, (int) ($totales[$c->n_categoria] ?? 0)))->values()->all();
    }
}
