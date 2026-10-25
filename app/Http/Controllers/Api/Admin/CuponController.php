<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Carrito;
use App\Models\Cupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Códigos de descuento que genera el administrador y que el cliente ingresa en el carrito. */
class CuponController extends Controller
{
    public function index(): JsonResponse
    {
        // Ventas y descuento total logrados con cada código.
        $resultados = Carrito::whereNotNull('x_cupon')->whereIn('x_estado', ['pedido', 'vendido'])
            ->selectRaw("x_cupon, count(*) as pedidos, sum(case when x_estado = 'vendido' then n_total else 0 end) as ventas, sum(n_descuento) as descuento")
            ->groupBy('x_cupon')->get()->keyBy('x_cupon');

        return response()->json(Cupon::orderByDesc('n_cupon')->get()->map(fn (Cupon $c) => $c->datos() + [
            'pedidos' => (int) ($resultados[$c->c_codigo]->pedidos ?? 0),
            'ventas' => round((float) ($resultados[$c->c_codigo]->ventas ?? 0), 2),
            'descuento_total' => round((float) ($resultados[$c->c_codigo]->descuento ?? 0), 2),
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $cupon = $this->guardar($request, new Cupon);

        return response()->json($cupon->datos(), 201);
    }

    public function update(Request $request, Cupon $cupon): JsonResponse
    {
        return response()->json($this->guardar($request, $cupon)->datos());
    }

    public function destroy(Cupon $cupon): JsonResponse
    {
        // Si ya se usó, solo se desactiva para conservar el historial de pedidos.
        if ($cupon->n_usos > 0) {
            $cupon->update(['l_activo' => '0']);

            return response()->json(['message' => 'El código ya se usó: quedó desactivado.']);
        }
        $cupon->delete();

        return response()->json(['message' => 'Código eliminado.']);
    }

    /** Propuesta de código aleatorio fácil de dictar (sin 0/O ni 1/I). */
    public function generar(Request $request): JsonResponse
    {
        $prefijo = Str::upper(Str::limit(preg_replace('/[^A-Za-z0-9]/', '', (string) $request->query('prefijo', 'FAS')), 8, '')) ?: 'FAS';
        do {
            $codigo = $prefijo.'-'.collect(range(1, 5))->map(fn () => '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'[random_int(0, 31)])->implode('');
        } while (Cupon::where('c_codigo', $codigo)->exists());

        return response()->json(['codigo' => $codigo]);
    }

    private function guardar(Request $request, Cupon $cupon): Cupon
    {
        $request->merge(['codigo' => Str::upper(trim((string) $request->input('codigo')))]);
        $datos = $request->validate([
            'codigo' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[A-Z0-9-]+$/', Rule::unique('car_cupon', 'c_codigo')->ignore($cupon->n_cupon, 'n_cupon')],
            'descripcion' => ['nullable', 'string', 'max:150'],
            'tipo' => ['required', 'in:porcentaje,monto'],
            'valor' => ['required', 'numeric', 'min:0.01', $request->input('tipo') === 'porcentaje' ? 'max:100' : 'max:99999'],
            'minimo' => ['nullable', 'numeric', 'min:0'],
            'max_usos' => ['nullable', 'integer', 'min:1'],
            'inicio' => ['nullable', 'date'],
            'fin' => ['nullable', 'date', 'after_or_equal:inicio'],
            'activo' => ['boolean'],
        ], ['codigo.regex' => 'El código solo admite letras, números y guiones.', 'codigo.unique' => 'Ese código ya existe.']);

        $cupon->fill([
            'c_codigo' => $datos['codigo'],
            'x_descripcion' => $datos['descripcion'] ?? null,
            'x_tipo' => $datos['tipo'],
            'n_valor' => $datos['valor'],
            'n_minimo' => $datos['minimo'] ?? 0,
            'n_max_usos' => $datos['max_usos'] ?? null,
            'f_inicio' => $datos['inicio'] ?? null,
            'f_fin' => $datos['fin'] ?? null,
            'l_activo' => ($datos['activo'] ?? true) ? '1' : '0',
        ])->save();

        return $cupon;
    }
}
