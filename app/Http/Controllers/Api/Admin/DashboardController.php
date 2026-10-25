<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Carrito;
use App\Models\Categoria;
use App\Models\Producto;
use App\Support\Catalogo;
use App\Support\Costos;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Indicadores del mes: ventas confirmadas, costo, ganancia y margen, comparados con el mes anterior,
 * más ventas por día, por categoría, productos más rentables, embudo de carritos e inventario.
 * Los días y meses se cuentan en hora de Lima.
 */
class DashboardController extends Controller
{
    private const ZONA = 'America/Lima';

    public function __invoke(Request $request): JsonResponse
    {
        $mes = $request->validate(['mes' => ['nullable', 'date_format:Y-m']])['mes'] ?? null;
        $inicio = CarbonImmutable::parse(($mes ?? now(self::ZONA)->format('Y-m')).'-01', self::ZONA)->startOfMonth();
        $fin = $inicio->endOfMonth();

        $ventas = $this->ventas($inicio, $fin);
        $anteriores = $this->ventas($inicio->subMonth(), $inicio->subMonth()->endOfMonth());
        $categorias = Categoria::pluck('x_categoria', 'n_categoria');

        return response()->json([
            'mes' => $inicio->format('Y-m'),
            'kpis' => $this->kpis($ventas),
            'anterior' => $this->kpis($anteriores),
            'por_dia' => $this->porDia($ventas, $inicio),
            'por_categoria' => $this->porCategoria($ventas, $categorias),
            'top_productos' => $this->topProductos($ventas),
            'por_canal' => $ventas->groupBy('x_canal')->map(fn ($g, $canal) => ['canal' => $canal, 'ventas' => round($g->sum('n_total'), 2), 'pedidos' => $g->count()])->values(),
            'tendencia' => $this->tendencia($inicio),
            'embudo' => $this->embudo($inicio, $fin),
            'cupones' => $ventas->whereNotNull('x_cupon')->groupBy('x_cupon')->map(fn ($g, $codigo) => [
                'codigo' => $codigo, 'usos' => $g->count(), 'descuento' => round($g->sum('n_descuento'), 2), 'ventas' => round($g->sum('n_total'), 2),
            ])->sortByDesc('usos')->values(),
            'inventario' => $this->inventario(),
            'abandonados' => [
                'total' => Carrito::abandonados()->count(),
                'con_contacto' => Carrito::abandonados()->whereNotNull('x_celular')->count(),
                'valor' => round((float) Carrito::abandonados()->sum('n_total'), 2),
            ],
            'pedidos_por_confirmar' => Carrito::where('x_estado', 'pedido')->count(),
            'sin_costo' => [
                'productos' => Producto::where('l_activo', '1')->whereNull('n_costo')->count(),
                // Ventas del mes con productos sin costo: su ganancia está sobreestimada.
                'ventas' => $ventas->filter(fn ($v) => $v->items->contains(fn ($i) => $i->n_costo === null))->count(),
            ],
            'parametros' => Costos::parametros(),
        ]);
    }

    private function ventas(CarbonImmutable $desde, CarbonImmutable $hasta): Collection
    {
        return Carrito::where('x_estado', 'vendido')
            ->whereBetween('f_venta', [$desde->utc(), $hasta->utc()])
            ->with('items.producto:n_producto,x_producto,x_slug,n_categoria')
            ->get()
            ->each(function (Carrito $v) {
                $v->costo = round($v->items->sum(fn ($i) => (float) $i->n_costo * $i->n_cantidad) + $v->n_costo_entrega + $v->n_comision, 2);
                $v->ganancia = round($v->n_total - $v->costo, 2);
            });
    }

    private function kpis(Collection $ventas): array
    {
        $total = round($ventas->sum('n_total'), 2);
        $ganancia = round($ventas->sum('ganancia'), 2);
        $pedidos = $ventas->count();

        return [
            'ventas' => $total,
            'pedidos' => $pedidos,
            'unidades' => (int) $ventas->sum('n_items'),
            'ticket' => $pedidos ? round($total / $pedidos, 2) : 0,
            'costo' => round($ventas->sum('costo'), 2),
            'ganancia' => $ganancia,
            'margen' => $total > 0 ? round($ganancia * 100 / $total, 1) : 0,
            'descuentos' => round($ventas->sum('n_descuento'), 2),
        ];
    }

    private function porDia(Collection $ventas, CarbonImmutable $inicio): array
    {
        $porDia = $ventas->groupBy(fn ($v) => $v->f_venta->timezone(self::ZONA)->day);

        return collect(range(1, $inicio->daysInMonth))->map(fn ($dia) => [
            'dia' => $dia,
            'ventas' => round(($porDia[$dia] ?? collect())->sum('n_total'), 2),
            'ganancia' => round(($porDia[$dia] ?? collect())->sum('ganancia'), 2),
            'pedidos' => ($porDia[$dia] ?? collect())->count(),
        ])->all();
    }

    /** Reparte el total cobrado (con descuento) entre los productos de cada venta según su peso. */
    private function lineas(Collection $ventas): Collection
    {
        return $ventas->flatMap(function (Carrito $v) {
            $bruto = max(0.01, $v->items->sum(fn ($i) => $i->n_precio * $i->n_cantidad));
            $factor = $v->n_total / $bruto;
            $fijo = $v->n_costo_entrega + $v->n_comision;

            return $v->items->map(function ($i) use ($factor, $bruto, $fijo) {
                $ingreso = $i->n_precio * $i->n_cantidad * $factor;
                $costo = (float) $i->n_costo * $i->n_cantidad + $fijo * ($i->n_precio * $i->n_cantidad / $bruto);

                return ['producto' => $i->producto, 'id' => $i->n_producto, 'unidades' => $i->n_cantidad, 'ventas' => $ingreso, 'ganancia' => $ingreso - $costo];
            });
        });
    }

    private function porCategoria(Collection $ventas, Collection $categorias): array
    {
        return $this->lineas($ventas)
            ->groupBy(fn ($l) => $l['producto']?->n_categoria ?? 0)
            ->map(fn ($g, $id) => [
                'categoria' => isset($categorias[$id]) ? Catalogo::nombreCategoria($categorias[$id]) : 'Sin categoría',
                'ventas' => round($g->sum('ventas'), 2),
                'ganancia' => round($g->sum('ganancia'), 2),
                'unidades' => $g->sum('unidades'),
            ])->sortByDesc('ventas')->values()->all();
    }

    private function topProductos(Collection $ventas): array
    {
        return $this->lineas($ventas)->groupBy('id')->map(function ($g, $id) {
            $ventas = $g->sum('ventas');

            return [
                'id' => $id,
                'nombre' => $g->first()['producto']?->x_producto ?? 'Producto eliminado',
                'unidades' => $g->sum('unidades'),
                'ventas' => round($ventas, 2),
                'ganancia' => round($g->sum('ganancia'), 2),
                'margen' => $ventas > 0 ? round($g->sum('ganancia') * 100 / $ventas, 1) : 0,
            ];
        })->sortByDesc('ganancia')->take(8)->values()->all();
    }

    private function tendencia(CarbonImmutable $inicio): array
    {
        return collect(range(5, 0))->map(function ($atras) use ($inicio) {
            $mes = $inicio->subMonths($atras);
            $kpis = $this->kpis($this->ventas($mes, $mes->endOfMonth()));

            return ['mes' => $mes->format('Y-m'), 'ventas' => $kpis['ventas'], 'ganancia' => $kpis['ganancia'], 'pedidos' => $kpis['pedidos']];
        })->all();
    }

    private function embudo(CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $carritos = Carrito::whereBetween('f_creado', [$desde->utc(), $hasta->utc()])->where('x_canal', 'web')
            ->where(fn ($q) => $q->where('n_items', '>', 0)->orWhereNotNull('f_pedido'));

        return [
            ['etapa' => 'Agregaron al carrito', 'total' => (clone $carritos)->count()],
            ['etapa' => 'Dejaron su celular', 'total' => (clone $carritos)->whereNotNull('x_celular')->count()],
            ['etapa' => 'Enviaron el pedido', 'total' => (clone $carritos)->whereNotNull('f_pedido')->count()],
            ['etapa' => 'Venta confirmada', 'total' => (clone $carritos)->where('x_estado', 'vendido')->count()],
        ];
    }

    private function inventario(): array
    {
        $productos = Producto::where('l_activo', '1')->where('n_stock', '>', 0)->where('l_estado', '!=', '0')->get();

        return [
            'unidades' => (int) $productos->sum('n_stock'),
            'valor_venta' => round($productos->sum(fn ($p) => $p->precioFinal() * $p->n_stock), 2),
            'valor_costo' => round($productos->sum(fn ($p) => (Costos::deProducto($p) ?? 0) * $p->n_stock), 2),
            'sin_costo' => $productos->whereNull('n_costo')->count(),
        ];
    }
}
