<?php

namespace App\Support;

use App\Models\Producto;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Fórmula de costos. Con el costo de manufactura (o compra) de cada producto:
 *
 *   costo unitario  = costo × (1 + flete% + aduana%) + empaque + costo extra del producto
 *   precio sugerido = costo unitario ÷ (1 − margen objetivo% − comisión%)
 *   ganancia/unidad = precio final − comisión − costo unitario
 *
 * Por cada venta se descuenta además el costo de entrega del pedido. El precio final lo decide el
 * administrador; el sugerido es solo una referencia.
 */
class Costos
{
    public const PARAMETROS = [
        'costo_flete_pct' => 'Flete / importación (%)',
        'costo_aduana_pct' => 'Aduana / impuestos (%)',
        'costo_empaque' => 'Empaque por unidad (S/)',
        'costo_entrega_pedido' => 'Costo de entrega por pedido (S/)',
        'comision_pct' => 'Comisión de cobro (%)',
        'margen_objetivo_pct' => 'Margen objetivo (%)',
    ];

    private const CACHE = 'fastore.parametros';

    /** @return array<string, float> */
    public static function parametros(): array
    {
        $guardados = Cache::remember(self::CACHE, 600, fn () => DB::table('cfg_parametro')->pluck('x_valor', 'x_clave')->all());

        return array_map(fn ($clave) => (float) ($guardados[$clave] ?? 0), array_combine(array_keys(self::PARAMETROS), array_keys(self::PARAMETROS)));
    }

    public static function guardar(array $valores): void
    {
        foreach (array_intersect_key($valores, self::PARAMETROS) as $clave => $valor) {
            DB::table('cfg_parametro')->updateOrInsert(['x_clave' => $clave], ['x_valor' => (string) round((float) $valor, 2)]);
        }
        Cache::forget(self::CACHE);
    }

    public static function unitario(?float $costo, float $extra = 0): ?float
    {
        if ($costo === null) {
            return null;
        }
        $p = self::parametros();

        return round($costo * (1 + ($p['costo_flete_pct'] + $p['costo_aduana_pct']) / 100) + $p['costo_empaque'] + $extra, 2);
    }

    public static function deProducto(Producto $producto): ?float
    {
        return self::unitario($producto->n_costo !== null ? (float) $producto->n_costo : null, (float) $producto->n_costo_extra);
    }

    /** Desglose para mostrar en el admin junto al precio. */
    public static function analisis(Producto $producto): array
    {
        $p = self::parametros();
        $unitario = self::deProducto($producto);
        $precio = $producto->precioFinal();
        $divisor = 1 - ($p['margen_objetivo_pct'] + $p['comision_pct']) / 100;
        $ganancia = $unitario === null ? null : round($precio * (1 - $p['comision_pct'] / 100) - $unitario, 2);

        return [
            'costo' => $producto->n_costo !== null ? (float) $producto->n_costo : null,
            'costo_extra' => (float) $producto->n_costo_extra,
            'costo_unitario' => $unitario,
            'precio_sugerido' => $unitario === null || $divisor <= 0 ? null : round($unitario / $divisor, 2),
            'ganancia_unidad' => $ganancia,
            'margen' => $ganancia === null || $precio <= 0 ? null : round($ganancia * 100 / $precio, 1),
        ];
    }

    public static function comision(float $monto): float
    {
        return round($monto * self::parametros()['comision_pct'] / 100, 2);
    }
}
