<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Carrito;
use App\Models\Cupon;
use App\Models\Producto;
use App\Support\Ventas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Venta registrada a mano (cerrada por WhatsApp, en persona, etc.) para que el dashboard tenga
 * todas las ventas del mes. Se guarda como un carrito "vendido" con canal "manual".
 */
class VentaController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'fecha' => ['nullable', 'date', 'before_or_equal:today'],
            'nombre' => ['nullable', 'string', 'max:120'],
            'celular' => ['nullable', 'string', 'max:15'],
            'estacion' => ['nullable', 'string', 'max:60'],
            'pago' => ['nullable', 'string', 'max:30'],
            'cupon' => ['nullable', 'string', 'max:30'],
            'descuento' => ['nullable', 'numeric', 'min:0'],
            'nota' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.id' => ['required', 'integer', 'exists:mae_productos,n_producto'],
            'items.*.cantidad' => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.precio' => ['nullable', 'numeric', 'min:0'],
        ]);

        $carrito = DB::transaction(function () use ($datos) {
            $productos = Producto::whereIn('n_producto', collect($datos['items'])->pluck('id'))->get()->keyBy('n_producto');
            $lineas = collect($datos['items'])->groupBy('id')->map(fn ($grupo, $id) => [
                'id' => (int) $id,
                'cantidad' => $grupo->sum('cantidad'),
                // Precio cobrado realmente; si no se indica, el vigente.
                'precio' => (float) ($grupo->first()['precio'] ?? $productos[$id]->precioFinal()),
            ]);
            $subtotal = round($lineas->sum(fn ($l) => $l['precio'] * $l['cantidad']), 2);

            $cupon = ! empty($datos['cupon']) ? Cupon::where('c_codigo', Str::upper($datos['cupon']))->first() : null;
            $descuento = round(min($subtotal, (float) ($datos['descuento'] ?? ($cupon?->descuento($subtotal) ?? 0))), 2);
            $cupon?->increment('n_usos');

            $carrito = Carrito::create([
                'c_token' => (string) Str::uuid(),
                'x_estado' => 'pedido',
                'x_canal' => 'manual',
                'x_nombre' => $datos['nombre'] ?? null,
                'x_celular' => isset($datos['celular']) ? preg_replace('/\s+/', '', $datos['celular']) : null,
                'x_estacion' => $datos['estacion'] ?? null,
                'x_pago' => $datos['pago'] ?? null,
                'x_nota' => $datos['nota'] ?? null,
                'x_cupon' => $cupon?->c_codigo ?? ($datos['cupon'] ?? null),
                'n_items' => $lineas->sum('cantidad'),
                'n_subtotal' => $subtotal,
                'n_descuento' => $descuento,
                'n_total' => round($subtotal - $descuento, 2),
                'f_pedido' => now(),
                'f_actividad' => now(),
            ]);
            foreach ($lineas as $l) {
                $carrito->items()->create(['n_producto' => $l['id'], 'n_cantidad' => $l['cantidad'], 'n_precio' => $l['precio']]);
            }

            return $carrito;
        });

        Ventas::confirmar($carrito, $datos['fecha'] ?? null);

        return response()->json(['id' => $carrito->n_carrito, 'message' => 'Venta registrada.'], 201);
    }
}
