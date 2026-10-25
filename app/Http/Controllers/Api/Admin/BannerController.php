<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Support\Imagenes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Banners de la portada. Imagen de escritorio (ideal 1920×640) y, opcional, una vertical para
 * celulares (ideal 900×900). Se envían como multipart; para editar se usa POST /banners/{id}.
 */
class BannerController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Banner::orderBy('n_orden')->orderBy('n_banner')->get()->map->datos(true));
    }

    public function store(Request $request): JsonResponse
    {
        $banner = $this->guardar($request, new Banner(['n_orden' => (int) Banner::max('n_orden') + 1]));

        return response()->json($banner->datos(true), 201);
    }

    public function update(Request $request, Banner $banner): JsonResponse
    {
        return response()->json($this->guardar($request, $banner)->datos(true));
    }

    public function destroy(Banner $banner): JsonResponse
    {
        Imagenes::borrar($banner->x_imagen, $banner->x_imagen_movil);
        $banner->delete();

        return response()->json(['message' => 'Banner eliminado.']);
    }

    public function ordenar(Request $request): JsonResponse
    {
        $ids = $request->validate(['orden' => ['required', 'array'], 'orden.*' => ['integer']])['orden'];
        DB::transaction(fn () => collect($ids)->values()->each(fn ($id, $i) => Banner::whereKey($id)->update(['n_orden' => $i + 1])));

        return $this->index();
    }

    private function guardar(Request $request, Banner $banner): Banner
    {
        $imagen = ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'];
        $datos = $request->validate([
            'titulo' => ['required', 'string', 'max:120'],
            'subtitulo' => ['nullable', 'string', 'max:255'],
            'boton' => ['nullable', 'string', 'max:40'],
            'enlace' => ['nullable', 'string', 'max:255', 'regex:#^(/|https://)#'],
            'tema' => ['nullable', 'in:claro,oscuro'],
            'activo' => ['nullable', 'boolean'],
            'inicio' => ['nullable', 'date'],
            'fin' => ['nullable', 'date', 'after_or_equal:inicio'],
            'imagen' => [$banner->exists ? 'nullable' : 'required', ...$imagen],
            'imagen_movil' => ['nullable', ...$imagen],
            'quitar_movil' => ['nullable', 'boolean'],
        ], ['enlace.regex' => 'El enlace debe empezar con / (página de la tienda) o con https://']);

        $anteriores = [];
        if ($request->hasFile('imagen')) {
            $anteriores[] = $banner->x_imagen;
            $banner->x_imagen = Imagenes::guardar($request->file('imagen')->getRealPath(), 'banners', 1920, 84)['ruta'];
        }
        if ($request->hasFile('imagen_movil')) {
            $anteriores[] = $banner->x_imagen_movil;
            $banner->x_imagen_movil = Imagenes::guardar($request->file('imagen_movil')->getRealPath(), 'banners', 900, 82)['ruta'];
        } elseif ($request->boolean('quitar_movil')) {
            $anteriores[] = $banner->x_imagen_movil;
            $banner->x_imagen_movil = null;
        }

        $banner->fill([
            'x_titulo' => $datos['titulo'],
            'x_subtitulo' => $datos['subtitulo'] ?? null,
            'x_boton' => $datos['boton'] ?? null,
            'x_enlace' => $datos['enlace'] ?? null,
            'x_tema' => $datos['tema'] ?? 'claro',
            'l_activo' => $request->boolean('activo', true) ? '1' : '0',
            'f_inicio' => $datos['inicio'] ?? null,
            'f_fin' => $datos['fin'] ?? null,
        ])->save();

        Imagenes::borrar(...$anteriores);

        return $banner;
    }
}
