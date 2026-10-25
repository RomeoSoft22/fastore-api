<?php

namespace App\Support;

use App\Models\Carrito;
use Illuminate\Support\Str;

/**
 * Cotización de un pedido: código público (COT-00012-K7QM), vigencia y la vista que se comparte.
 * El sufijo aleatorio evita que alguien vea cotizaciones ajenas probando números seguidos.
 */
class Cotizaciones
{
    public static function asignar(Carrito $carrito): void
    {
        if ($carrito->c_cotizacion) {
            return;
        }
        do {
            $codigo = sprintf('COT-%05d-%s', $carrito->n_carrito, collect(range(1, 4))->map(fn () => '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'[random_int(0, 31)])->implode(''));
        } while (Carrito::where('c_cotizacion', $codigo)->exists());

        $carrito->forceFill(['c_cotizacion' => $codigo, 'f_vence' => now('America/Lima')->addDays((int) config('tienda.cotizacion_dias'))->toDateString()]);
    }

    public static function url(Carrito $carrito): string
    {
        return config('tienda.url').'/cotizacion/'.$carrito->c_cotizacion;
    }

    /** "Estación del Tren · Gamarra", "Envío nacional (Shalom) · Arequipa — Agencia Av. Ejército"… */
    public static function entrega(Carrito $c): string
    {
        $nombre = config('tienda.entregas.'.$c->x_entrega.'.nombre') ?? 'Por coordinar';

        return match ($c->x_entrega) {
            'estacion' => $nombre.' · '.$c->x_estacion,
            'local' => $nombre.' · '.$c->x_direccion.', '.$c->x_ciudad,
            'nacional' => $nombre.' · '.$c->x_ciudad.' — Agencia '.$c->x_direccion.' (DNI '.$c->x_dni.')',
            default => $nombre,
        };
    }

    /** Datos públicos de la cotización: sin celular, DNI ni dirección completos. */
    public static function publica(Carrito $c): array
    {
        $c->loadMissing('items.producto.portada');

        return [
            'codigo' => $c->c_cotizacion,
            'fecha' => $c->f_pedido?->timezone('America/Lima')->toDateString(),
            'vence' => $c->f_vence?->toDateString(),
            'vencida' => $c->f_vence && $c->f_vence->lt(now('America/Lima')->startOfDay()),
            'estado' => $c->x_estado,
            'cliente' => Str::of((string) $c->x_nombre)->explode(' ')->first(),
            'entrega' => [
                'tipo' => $c->x_entrega,
                'nombre' => config('tienda.entregas.'.$c->x_entrega.'.nombre'),
                'detalle' => config('tienda.entregas.'.$c->x_entrega.'.detalle'),
                'lugar' => match ($c->x_entrega) {
                    'estacion' => 'Estación '.$c->x_estacion,
                    'local' => $c->x_ciudad,
                    'nacional' => $c->x_ciudad.' — Agencia Shalom '.$c->x_direccion,
                    default => null,
                },
            ],
            'pago' => $c->x_pago,
            'items' => $c->items->map(fn ($i) => [
                'nombre' => $i->producto?->x_producto ?? 'Producto',
                'slug' => $i->producto?->x_slug,
                'imagen' => $i->producto?->portada ? Imagenes::url($i->producto->portada->x_miniatura) : null,
                'cantidad' => $i->n_cantidad,
                'precio' => $i->n_precio,
                'importe' => round($i->n_precio * $i->n_cantidad, 2),
            ])->values(),
            'subtotal' => $c->n_subtotal,
            'cupon' => $c->x_cupon,
            'descuento' => $c->n_descuento,
            'total' => $c->n_total,
            'whatsapp_url' => self::whatsapp($c),
        ];
    }

    /** Mensaje para WhatsApp de la tienda: el código de la cotización y su enlace. */
    public static function whatsapp(Carrito $c): string
    {
        $mensaje = implode("\n", [
            '¡Hola '.config('tienda.nombre').'! Quiero confirmar mi cotización:',
            '',
            '*'.$c->c_cotizacion.'*',
            'Total: S/ '.number_format((float) $c->n_total, 2).' ('.$c->n_items.' producto'.($c->n_items === 1 ? '' : 's').')',
            'Entrega: '.(config('tienda.entregas.'.$c->x_entrega.'.nombre') ?? 'Por coordinar'),
            '',
            'Ver cotización: '.self::url($c),
        ]);

        return 'https://wa.me/'.config('tienda.whatsapp').'?text='.rawurlencode($mensaje);
    }
}
