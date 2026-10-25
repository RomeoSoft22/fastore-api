<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\Marca;
use App\Models\Producto;
use App\Support\Catalogo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Categorías y marcas (tablas heredadas mae_categoria y mae_marca). */
class CatalogoController extends Controller
{
    /** Todo lo que necesitan los formularios del admin en una sola llamada. */
    public function index(): JsonResponse
    {
        $porCategoria = Producto::select('n_categoria', DB::raw('count(*) as total'))->groupBy('n_categoria')->pluck('total', 'n_categoria');
        $porMarca = Producto::select('n_marca', DB::raw('count(*) as total'))->groupBy('n_marca')->pluck('total', 'n_marca');

        return response()->json([
            'categorias' => Categoria::orderBy('n_orden')->orderBy('x_categoria')->get()->map(fn ($c) => [
                'id' => $c->n_categoria, 'nombre' => $c->x_categoria, 'etiqueta' => Catalogo::nombreCategoria($c->x_categoria),
                'slug' => $c->x_slug, 'descripcion' => $c->x_descripcion,
                'orden' => $c->n_orden, 'activo' => $c->l_activo === '1', 'productos' => (int) ($porCategoria[$c->n_categoria] ?? 0),
            ]),
            'marcas' => Marca::orderBy('x_marca')->get()->map(fn ($m) => [
                'id' => $m->n_marca, 'nombre' => $m->x_marca, 'slug' => $m->x_slug,
                'activo' => $m->l_activo === '1', 'productos' => (int) ($porMarca[$m->n_marca] ?? 0),
            ]),
            'sin_categoria' => (int) ($porCategoria[''] ?? 0),
        ]);
    }

    public function guardarCategoria(Request $request, ?Categoria $categoria = null): JsonResponse
    {
        $categoria ??= new Categoria;
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:50'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'orden' => ['nullable', 'integer', 'min:0', 'max:999'],
            'activo' => ['boolean'],
        ]);
        $slug = $this->slug($datos['nombre'], 'mae_categoria', 'n_categoria', $categoria->n_categoria);

        $categoria->fill([
            'x_categoria' => Str::upper($datos['nombre']),
            'x_slug' => $slug,
            'x_descripcion' => $datos['descripcion'] ?? null,
            'n_orden' => $datos['orden'] ?? 0,
            'l_activo' => ($datos['activo'] ?? true) ? '1' : '0',
        ])->save();

        return response()->json(['id' => $categoria->n_categoria], $categoria->wasRecentlyCreated ? 201 : 200);
    }

    public function eliminarCategoria(Categoria $categoria): JsonResponse
    {
        // Los productos quedan "sin categoría" (FK con SET NULL).
        $categoria->delete();

        return response()->json(['message' => 'Categoría eliminada.']);
    }

    public function guardarMarca(Request $request, ?Marca $marca = null): JsonResponse
    {
        $marca ??= new Marca;
        $datos = $request->validate(['nombre' => ['required', 'string', 'max:50'], 'activo' => ['boolean']]);

        $marca->fill([
            'x_marca' => $datos['nombre'],
            'x_slug' => $this->slug($datos['nombre'], 'mae_marca', 'n_marca', $marca->n_marca),
            'l_activo' => ($datos['activo'] ?? true) ? '1' : '0',
        ])->save();

        return response()->json(['id' => $marca->n_marca], $marca->wasRecentlyCreated ? 201 : 200);
    }

    public function eliminarMarca(Marca $marca): JsonResponse
    {
        abort_if($marca->productos()->exists(), 409, 'La marca tiene productos. Cámbialos de marca antes de eliminarla.');
        $marca->delete();

        return response()->json(['message' => 'Marca eliminada.']);
    }

    private function slug(string $nombre, string $tabla, string $llave, ?int $ignorar): string
    {
        $base = Str::slug($nombre) ?: 'item';
        $slug = $base;
        for ($i = 2; DB::table($tabla)->where('x_slug', $slug)->when($ignorar, fn ($q) => $q->where($llave, '!=', $ignorar))->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
