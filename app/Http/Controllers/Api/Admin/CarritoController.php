<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Carrito;
use App\Support\Cotizaciones;
use App\Support\Imagenes;
use App\Support\Ventas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Seguimiento de carritos: quién agregó productos y no concluyó, y los pedidos con su cotización. */
class CarritoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'vista' => ['nullable', 'in:abandonados,activos,pedidos,vendidos,descartados,todos'],
            'contacto' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $consulta = Carrito::query()->where('x_estado', '!=', 'vacio')->with('items.producto.portada');
        match ($f['vista'] ?? 'abandonados') {
            'abandonados' => $consulta->abandonados(),
            'activos' => $consulta->where('x_estado', 'activo'),
            'pedidos' => $consulta->where('x_estado', 'pedido'),
            'vendidos' => $consulta->where('x_estado', 'vendido'),
            'descartados' => $consulta->where('x_estado', 'descartado'),
            default => null,
        };
        if (! empty($f['contacto'])) {
            $consulta->whereNotNull('x_celular');
        }
        if ($texto = $f['q'] ?? null) {
            $like = '%'.addcslashes($texto, '%_\\').'%';
            $consulta->where(fn (Builder $q) => $q->where('x_nombre', 'like', $like)->orWhere('x_celular', 'like', $like)
                ->orWhere('n_carrito', (int) $texto)
                ->orWhere('c_cotizacion', 'like', $like)
                ->orWhereHas('items.producto', fn ($p) => $p->where('x_producto', 'like', $like)));
        }

        $pagina = $consulta->orderByRaw('COALESCE(f_venta, f_pedido, f_actividad) DESC')->paginate((int) ($f['por_pagina'] ?? 25));

        return response()->json([
            'datos' => collect($pagina->items())->map(fn ($c) => $this->datos($c)),
            'pagina' => $pagina->currentPage(),
            'paginas' => $pagina->lastPage(),
            'total' => $pagina->total(),
            // Conteos para las pestañas.
            'conteos' => [
                'abandonados' => Carrito::abandonados()->count(),
                'activos' => Carrito::where('x_estado', 'activo')->count(),
                'pedidos' => Carrito::where('x_estado', 'pedido')->count(),
                'vendidos' => Carrito::where('x_estado', 'vendido')->count(),
                'descartados' => Carrito::where('x_estado', 'descartado')->count(),
                'todos' => Carrito::where('x_estado', '!=', 'vacio')->count(),
            ],
        ]);
    }

    public function show(Carrito $carrito): JsonResponse
    {
        return response()->json($this->datos($carrito->load('items.producto.portada')));
    }

    /** Estado (vendido, descartado, activo), nota interna y registro de que se le contactó. */
    public function update(Request $request, Carrito $carrito): JsonResponse
    {
        $datos = $request->validate([
            'estado' => ['nullable', 'in:activo,pedido,vendido,descartado'],
            'nota' => ['nullable', 'string', 'max:2000'],
            'contactado' => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('nota', $datos)) {
            $carrito->x_nota = $datos['nota'];
        }
        if (! empty($datos['contactado'])) {
            $carrito->n_contactos++;
            $carrito->f_contactado = now();
        }
        $carrito->save();

        // Confirmar la venta fija costos y descuenta stock; reabrirla lo revierte.
        if (! empty($datos['estado']) && $datos['estado'] !== $carrito->x_estado) {
            $datos['estado'] === 'vendido'
                ? Ventas::confirmar($carrito)
                : Ventas::revertir($carrito, $datos['estado']);
        }

        return $this->show($carrito->refresh());
    }

    private function datos(Carrito $c): array
    {
        $productos = $c->items->map(fn ($i) => [
            'id' => $i->n_producto,
            'nombre' => $i->producto?->x_producto ?? 'Producto eliminado',
            'slug' => $i->producto?->x_slug,
            'imagen' => $i->producto?->portada ? Imagenes::url($i->producto->portada->x_miniatura) : null,
            'stock' => $i->producto?->n_stock,
            'cantidad' => $i->n_cantidad,
            'precio' => $i->n_precio,
            'costo' => $i->n_costo,
            'agregado' => $i->f_agregado?->toIso8601String(),
        ]);

        return [
            'id' => $c->n_carrito,
            'cotizacion' => $c->c_cotizacion,
            'cotizacion_url' => $c->c_cotizacion ? Cotizaciones::url($c) : null,
            'vence' => $c->f_vence?->toDateString(),
            'estado' => $c->x_estado,
            'canal' => $c->x_canal,
            'abandonado' => $c->abandonado(),
            'nombre' => $c->x_nombre,
            'celular' => $c->x_celular,
            'correo' => $c->x_correo,
            'entrega' => $c->x_entrega,
            'entrega_texto' => $c->x_entrega ? Cotizaciones::entrega($c) : ($c->x_estacion ? 'Estación '.$c->x_estacion : null),
            'estacion' => $c->x_estacion,
            'direccion' => $c->x_direccion,
            'ciudad' => $c->x_ciudad,
            'dni' => $c->x_dni,
            'pago' => $c->x_pago,
            'items' => $c->n_items,
            'subtotal' => $c->n_subtotal,
            'cupon' => $c->x_cupon,
            'descuento' => $c->n_descuento,
            'total' => $c->n_total,
            'ganancia' => Ventas::ganancia($c),
            'venta' => $c->f_venta?->toIso8601String(),
            'productos' => $productos,
            'dispositivo' => $c->x_dispositivo,
            'origen' => $c->x_origen,
            'nota' => $c->x_nota,
            'contactos' => $c->n_contactos,
            'contactado' => $c->f_contactado?->toIso8601String(),
            'pedido' => $c->f_pedido?->toIso8601String(),
            'acepto_terminos' => $c->f_acepta_terminos?->toIso8601String(),
            'actividad' => $c->f_actividad?->toIso8601String(),
            'creado' => $c->f_creado?->toIso8601String(),
            'historial' => $this->historial($c),
            'mensajes' => $c->x_celular ? $this->mensajes($c, $productos->pluck('nombre')->all()) : [],
        ];
    }

    /** Línea de tiempo del carrito, de lo más antiguo a lo más reciente. */
    private function historial(Carrito $c): array
    {
        $eventos = [['fecha' => $c->f_creado, 'texto' => $c->x_canal === 'manual' ? 'Venta registrada a mano' : 'Creó el carrito', 'tipo' => 'inicio']];
        if ($c->x_canal === 'web') {
            foreach ($c->items as $i) {
                $eventos[] = ['fecha' => $i->f_agregado, 'texto' => 'Agregó '.($i->producto?->x_producto ?? 'un producto').' (x'.$i->n_cantidad.')', 'tipo' => 'producto'];
            }
        }
        if ($c->f_pedido && $c->x_canal === 'web') {
            $eventos[] = ['fecha' => $c->f_pedido, 'texto' => 'Envió el pedido'.($c->c_cotizacion ? ' · cotización '.$c->c_cotizacion : ''), 'tipo' => 'pedido'];
        }
        if ($c->f_contactado) {
            $eventos[] = ['fecha' => $c->f_contactado, 'texto' => 'Último contacto por WhatsApp ('.$c->n_contactos.' en total)', 'tipo' => 'contacto'];
        }
        if ($c->f_venta) {
            $eventos[] = ['fecha' => $c->f_venta, 'texto' => 'Venta confirmada', 'tipo' => 'venta'];
        }
        if (! $c->f_pedido && $c->f_actividad) {
            $eventos[] = ['fecha' => $c->f_actividad, 'texto' => 'Última actividad en la tienda', 'tipo' => 'actividad'];
        }

        return collect($eventos)->filter(fn ($e) => $e['fecha'])->sortBy('fecha')
            ->map(fn ($e) => ['fecha' => $e['fecha']->toIso8601String()] + $e)->values()->all();
    }

    /** Mensajes de seguimiento listos para abrir en WhatsApp, según el momento del cliente. */
    private function mensajes(Carrito $c, array $productos): array
    {
        $numero = preg_replace('/\D/', '', $c->x_celular);
        if (strlen($numero) === 9) {
            $numero = '51'.$numero;
        }
        $nombre = $c->x_nombre ? ' '.strtok($c->x_nombre, ' ') : '';
        $lista = implode(', ', array_slice($productos, 0, 3)).(count($productos) > 3 ? ' y más' : '');
        $total = 'S/ '.number_format((float) $c->n_total, 2);
        $tienda = config('tienda.nombre');
        $cotizacion = $c->c_cotizacion ? "tu cotización {$c->c_cotizacion}" : "tu pedido N° {$c->n_carrito}";

        $plantillas = match ($c->x_estado) {
            'pedido' => [
                'Confirmar pedido' => "¡Hola{$nombre}! Te escribimos de {$tienda} por {$cotizacion} ({$total}). ¿Confirmamos tu pedido y coordinamos la entrega?",
                'Datos de pago' => "¡Hola{$nombre}! El total de {$cotizacion} es {$total}. Puedes pagar por Yape o Plin al ".config('tienda.whatsapp').'. Envíanos la captura y despachamos.',
                'Recordar vencimiento' => "¡Hola{$nombre}! {$cotizacion} vence el ".($c->f_vence?->format('d/m') ?? 'pronto').'. ¿Te la reservamos?',
            ],
            'vendido' => [
                'Agradecer' => "¡Gracias por tu compra{$nombre}! Cualquier consulta sobre {$lista}, escríbenos. ¡Que lo disfrutes!",
            ],
            default => [
                'Recuperar carrito' => "¡Hola{$nombre}! Te escribimos de {$tienda}. Vimos que te interesó {$lista}. ¿Te ayudamos a completar tu pedido?",
                'Ofrecer ayuda' => "¡Hola{$nombre}! ¿Tienes alguna duda sobre {$lista}? Te asesoramos por aquí.",
            ],
        };

        return collect($plantillas)->map(fn ($texto, $titulo) => [
            'titulo' => $titulo,
            'texto' => $texto,
            'url' => 'https://wa.me/'.$numero.'?text='.rawurlencode($texto),
        ])->values()->all();
    }
}
