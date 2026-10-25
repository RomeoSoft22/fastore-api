<?php

namespace App\Support;

use App\Models\Carrito;
use App\Models\Producto;
use Illuminate\Support\Facades\DB;

/**
 * Confirmar una venta fija sus costos (costo unitario de cada producto, entrega y comisión) y
 * descuenta el stock. Reabrirla los revierte. El dashboard calcula la ganancia con esa foto.
 */
class Ventas
{
    public static function confirmar(Carrito $carrito, ?string $fecha = null): void
    {
        if ($carrito->x_estado === 'vendido') {
            return;
        }

        DB::transaction(function () use ($carrito, $fecha) {
            foreach ($carrito->items()->with('producto')->get() as $item) {
                $item->n_costo = $item->producto ? Costos::deProducto($item->producto) : null;
                $item->save();
                if ($item->producto && $item->producto->n_stock !== null) {
                    $item->producto->decrement('n_stock', min($item->n_cantidad, max(0, $item->producto->n_stock)));
                }
            }

            $carrito->forceFill([
                'x_estado' => 'vendido',
                'f_venta' => $fecha ? now()->parse($fecha)->setTimeFrom(now()) : now(),
                'n_costo_entrega' => Costos::parametros()['costo_entrega_pedido'],
                'n_comision' => Costos::comision((float) $carrito->n_total),
            ])->save();
        });
    }

    /** Deshace una venta confirmada: devuelve el stock y limpia la foto de costos. */
    public static function revertir(Carrito $carrito, string $nuevoEstado): void
    {
        DB::transaction(function () use ($carrito, $nuevoEstado) {
            if ($carrito->x_estado === 'vendido') {
                foreach ($carrito->items as $item) {
                    Producto::whereKey($item->n_producto)->whereNotNull('n_stock')->increment('n_stock', $item->n_cantidad);
                    $item->forceFill(['n_costo' => null])->save();
                }
                $carrito->forceFill(['f_venta' => null, 'n_costo_entrega' => 0, 'n_comision' => 0]);
            }
            $carrito->forceFill(['x_estado' => $nuevoEstado])->save();
        });
    }

    /** Ganancia de una venta confirmada: total cobrado − costo de productos − entrega − comisión. */
    public static function ganancia(Carrito $carrito): ?float
    {
        if ($carrito->x_estado !== 'vendido') {
            return null;
        }
        $costo = $carrito->items->sum(fn ($i) => (float) $i->n_costo * $i->n_cantidad);

        return round((float) $carrito->n_total - $costo - (float) $carrito->n_costo_entrega - (float) $carrito->n_comision, 2);
    }
}
