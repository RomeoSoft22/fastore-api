<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Producto;
use App\Models\ProductoImagen;
use App\Support\Catalogo;
use App\Support\Costos;
use App\Support\Html;
use App\Support\Imagenes;
use App\Support\Galeria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Administración de productos y de su galería de fotos. */
class ProductoController extends Controller
{
    /**
     * Lista con filtros (q, categoria, estado, activo, vista) y orden, más los contadores del menú
     * lateral del admin (por estado, visibilidad, faltantes y categoría).
     */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'categoria' => ['nullable', 'integer'],
            'estado' => ['nullable', 'in:0,1,2'],
            'activo' => ['nullable', 'in:0,1'],
            'vista' => ['nullable', 'in:sin_fotos,sin_costo,destacados,stock_bajo'],
            'orden' => ['nullable', 'in:recientes,nombre,precio_asc,precio_desc,stock,actualizados'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $consulta = Producto::with(['marca', 'categoria', 'portada'])->withCount('imagenes')
            ->when($f['q'] ?? null, fn ($q, $texto) => $q->where(fn ($q) => $q->where('x_producto', 'like', '%'.addcslashes($texto, '%_\\').'%')->orWhere('n_producto', (int) $texto)))
            ->when($f['categoria'] ?? null, fn ($q, $c) => $q->where('n_categoria', $c))
            ->when(isset($f['estado']), fn ($q) => $q->where('l_estado', $f['estado']))
            ->when(isset($f['activo']), fn ($q) => $q->where('l_activo', $f['activo']));
        match ($f['vista'] ?? null) {
            'sin_fotos' => $consulta->doesntHave('imagenes'),
            'sin_costo' => $consulta->whereNull('n_costo'),
            'destacados' => $consulta->where('l_destacado', '1'),
            'stock_bajo' => $consulta->where('l_estado', '1')->where('n_stock', '<=', 3),
            default => null,
        };
        match ($f['orden'] ?? 'recientes') {
            'nombre' => $consulta->orderBy('x_producto'),
            'precio_asc' => $consulta->orderBy('x_precio'),
            'precio_desc' => $consulta->orderByDesc('x_precio'),
            'stock' => $consulta->orderBy('n_stock'),
            'actualizados' => $consulta->orderByDesc('f_registro'),
            default => $consulta->orderByDesc('n_producto'),
        };

        $pagina = $consulta->paginate((int) ($f['por_pagina'] ?? 25));

        return response()->json([
            'datos' => collect($pagina->items())->map(fn ($p) => $this->datos($p)),
            'pagina' => $pagina->currentPage(),
            'paginas' => $pagina->lastPage(),
            'total' => $pagina->total(),
            'conteos' => $this->conteos(),
        ]);
    }

    private function conteos(): array
    {
        $fila = DB::table('mae_productos')->selectRaw("
            count(*) as todos,
            sum(l_activo = '1') as visibles,
            sum(l_activo = '0') as ocultos,
            sum(l_estado = '1') as disponibles,
            sum(l_estado = '2') as a_pedido,
            sum(l_estado = '0') as agotados,
            sum(l_destacado = '1') as destacados,
            sum(n_costo is null) as sin_costo,
            sum(l_estado = '1' and n_stock <= 3) as stock_bajo,
            sum(not exists (select 1 from mae_producto_imagen i where i.n_producto = mae_productos.n_producto)) as sin_fotos
        ")->first();

        return array_map('intval', (array) $fila) + [
            'categorias' => Producto::select('n_categoria', DB::raw('count(*) as total'))->groupBy('n_categoria')->pluck('total', 'n_categoria'),
        ];
    }

    public function show(Producto $producto): JsonResponse
    {
        return response()->json($this->datos($producto->load(['marca', 'categoria', 'imagenes']), true));
    }

    public function store(Request $request): JsonResponse
    {
        $producto = new Producto(['l_activo' => '1', 'f_creado' => now()]);
        $this->guardar($request, $producto);

        return response()->json($this->datos($producto->fresh(['marca', 'categoria', 'imagenes']), true), 201);
    }

    public function update(Request $request, Producto $producto): JsonResponse
    {
        $this->guardar($request, $producto);

        return response()->json($this->datos($producto->fresh(['marca', 'categoria', 'imagenes']), true));
    }

    /** Cambios rápidos desde la lista: activo, estado, destacado, precio o stock. */
    public function patch(Request $request, Producto $producto): JsonResponse
    {
        $datos = $request->validate([
            'activo' => ['sometimes', 'boolean'],
            'destacado' => ['sometimes', 'boolean'],
            'estado' => ['sometimes', 'in:agotado,disponible,a_pedido'],
            'precio' => ['sometimes', 'numeric', 'min:0', 'max:999999'],
            'costo' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999'],
            'stock' => ['sometimes', 'integer', 'min:0', 'max:999999'],
        ]);
        if (array_key_exists('costo', $datos)) {
            $producto->n_costo = $datos['costo'];
        }
        $mapa = array_flip(Producto::ESTADOS);
        $producto->fill(array_filter([
            'l_activo' => isset($datos['activo']) ? ($datos['activo'] ? '1' : '0') : null,
            'l_destacado' => isset($datos['destacado']) ? ($datos['destacado'] ? '1' : '0') : null,
            'l_estado' => isset($datos['estado']) ? (string) $mapa[$datos['estado']] : null,
            'x_precio' => $datos['precio'] ?? null,
            'n_stock' => $datos['stock'] ?? null,
        ], fn ($v) => $v !== null))->save();

        return response()->json($this->datos($producto->fresh(['marca', 'categoria', 'portada'])));
    }

    public function destroy(Producto $producto): JsonResponse
    {
        DB::transaction(function () use ($producto) {
            $producto->imagenes->each(fn ($imagen) => Galeria::quitar($imagen));
            $producto->delete();
        });

        return response()->json(['message' => 'Producto eliminado.']);
    }

    public function subirImagenes(Request $request, Producto $producto): JsonResponse
    {
        $request->validate([
            'imagenes' => ['required', 'array', 'max:10'],
            'imagenes.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192'],
        ], ['imagenes.*.max' => 'Cada imagen debe pesar como máximo 8 MB.']);

        foreach ($request->file('imagenes') as $archivo) {
            Galeria::agregar($producto, $archivo->getRealPath());
        }

        return response()->json($producto->imagenes()->get()->map->datos());
    }

    /** Imagen insertada en la descripción desde el editor (no entra a la galería). */
    public function imagenDescripcion(Request $request, Producto $producto): JsonResponse
    {
        $request->validate(['imagen' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192']]);
        $imagen = Imagenes::guardar($request->file('imagen')->getRealPath(), 'productos/'.$producto->n_producto.'/descripcion', 1200);

        return response()->json(['url' => Imagenes::url($imagen['ruta']), 'ancho' => $imagen['ancho'], 'alto' => $imagen['alto']]);
    }

    /** Recibe los ids en el orden deseado; la primera es la portada. */
    public function ordenarImagenes(Request $request, Producto $producto): JsonResponse
    {
        $ids = $request->validate(['orden' => ['required', 'array'], 'orden.*' => ['integer']])['orden'];
        DB::transaction(function () use ($ids, $producto) {
            foreach (array_values($ids) as $i => $id) {
                ProductoImagen::where('n_producto', $producto->n_producto)->where('n_imagen', $id)->update(['n_orden' => $i + 1]);
            }
        });

        return response()->json($producto->imagenes()->get()->map->datos());
    }

    public function eliminarImagen(Producto $producto, int $imagen): JsonResponse
    {
        Galeria::quitar(ProductoImagen::where('n_producto', $producto->n_producto)->findOrFail($imagen));

        return response()->json($producto->imagenes()->get()->map->datos());
    }

    private function guardar(Request $request, Producto $producto): void
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:170', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('mae_productos', 'x_slug')->ignore($producto->n_producto, 'n_producto')],
            'marca_id' => ['required', 'integer', 'exists:mae_marca,n_marca'],
            'categoria_id' => ['nullable', 'integer', 'exists:mae_categoria,n_categoria'],
            'descripcion' => ['nullable', 'string', 'max:200000'],
            'resumen' => ['nullable', 'string', 'max:300'],
            'etiquetas' => ['nullable', 'array', 'max:20'],
            'etiquetas.*' => ['string', 'max:40'],
            'precio' => ['required', 'numeric', 'min:0', 'max:999999'],
            'costo' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'costo_extra' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'descuento' => ['nullable', 'integer', 'min:0', 'max:99'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'estado' => ['required', 'in:agotado,disponible,a_pedido'],
            'condicion' => ['required', 'in:nuevo,usado'],
            'destacado' => ['boolean'],
            'activo' => ['boolean'],
        ], ['slug.regex' => 'La URL solo admite minúsculas, números y guiones.', 'slug.unique' => 'Otro producto ya usa esa URL.']);

        $slug = $datos['slug'] ?: $this->slugUnico($datos['nombre'], $producto->n_producto);

        $producto->fill([
            'x_producto' => $datos['nombre'],
            'x_slug' => $slug,
            'n_marca' => $datos['marca_id'],
            'n_categoria' => $datos['categoria_id'] ?? null,
            // HTML del editor filtrado: solo formato, enlaces e imágenes; nada de scripts ni eventos.
            'x_descripcion' => Html::limpiar($datos['descripcion'] ?? null) ?: null,
            'x_resumen' => $datos['resumen'] ?? null,
            'x_etiquetas' => collect($datos['etiquetas'] ?? [])
                ->map(fn ($e) => trim(str_replace(',', ' ', mb_strtolower($e))))->filter()->unique()->implode(', ') ?: null,
            'x_precio' => $datos['precio'],
            'n_costo' => $datos['costo'] ?? null,
            'n_costo_extra' => $datos['costo_extra'] ?? 0,
            'x_descuento' => ($datos['descuento'] ?? 0) ?: null,
            'n_stock' => $datos['stock'] ?? 0,
            'l_estado' => (string) array_flip(Producto::ESTADOS)[$datos['estado']],
            'l_producto' => $datos['condicion'] === 'usado' ? 'U' : 'N',
            'l_destacado' => ($datos['destacado'] ?? false) ? '1' : '0',
            'l_activo' => ($datos['activo'] ?? true) ? '1' : '0',
        ])->save();
    }

    private function slugUnico(string $nombre, ?int $ignorar): string
    {
        $base = Str::slug(Str::limit($nombre, 80, '')) ?: 'producto';
        $slug = $base;
        for ($i = 2; Producto::where('x_slug', $slug)->when($ignorar, fn ($q) => $q->where('n_producto', '!=', $ignorar))->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    private function datos(Producto $p, bool $detalle = false): array
    {
        return Catalogo::producto($p, $detalle) + Costos::analisis($p) + [
            'activo' => $p->l_activo === '1',
            'marca_id' => $p->n_marca,
            'categoria_id' => $p->n_categoria,
            'resumen_propio' => $p->x_resumen,
            'imagenes_total' => $p->imagenes_count ?? ($p->relationLoaded('imagenes') ? $p->imagenes->count() : null),
            'creado' => $p->f_creado?->toIso8601String(),
            'actualizado' => $p->f_registro?->toIso8601String(),
        ];
    }
}
