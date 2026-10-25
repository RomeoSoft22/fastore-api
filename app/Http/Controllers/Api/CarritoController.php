<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Carrito;
use App\Models\Cupon;
use App\Models\Producto;
use App\Support\Cotizaciones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * El navegador guarda el carrito y lo sincroniza aquí con un token anónimo (UUID). Así la tienda
 * sabe quién agregó productos y no envió el pedido, para darle seguimiento por WhatsApp.
 */
class CarritoController extends Controller
{
    /** Crea o actualiza el carrito. Si el token no existe o ya terminó en pedido, abre uno nuevo. */
    public function sincronizar(Request $request): JsonResponse
    {
        $datos = $this->validar($request, false);
        // Un carrito vacío y sin token no se registra (evita filas basura).
        if (empty($datos['items']) && (empty($datos['token']) || ! Carrito::where('c_token', $datos['token'])->exists())) {
            return response()->json(['token' => null, 'estado' => 'vacio']);
        }
        $carrito = $this->guardar($request, $datos);

        return response()->json(['token' => $carrito->c_token, 'estado' => $carrito->x_estado]);
    }

    /** Valida un código de descuento contra el subtotal del carrito (sin consumirlo). */
    public function cupon(Request $request): JsonResponse
    {
        $datos = $request->validate(['codigo' => ['required', 'string', 'max:30'], 'subtotal' => ['required', 'numeric', 'min:0']]);
        $cupon = Cupon::where('c_codigo', Str::upper(trim($datos['codigo'])))->first();
        $problema = $cupon ? $cupon->problema((float) $datos['subtotal']) : 'Este código no existe.';
        if ($problema) {
            return response()->json(['message' => $problema, 'errors' => ['codigo' => [$problema]]], 422);
        }

        return response()->json([
            'codigo' => $cupon->c_codigo,
            'etiqueta' => $cupon->etiqueta(),
            'descuento' => $cupon->descuento((float) $datos['subtotal']),
        ]);
    }

    /** El cliente envía el pedido por WhatsApp: se registra como "pedido" y se arma el mensaje. */
    public function pedido(Request $request): JsonResponse
    {
        $datos = $this->validar($request, true);

        $carrito = DB::transaction(function () use ($request, $datos) {
            $carrito = $this->guardar($request, $datos);
            abort_if($carrito->n_items === 0, 422, 'El carrito está vacío.');

            // El cupón se vuelve a validar y se consume al enviar el pedido.
            if (! empty($datos['cupon'])) {
                $cupon = Cupon::where('c_codigo', Str::upper(trim($datos['cupon'])))->lockForUpdate()->first();
                $problema = $cupon ? $cupon->problema($carrito->n_subtotal) : 'Este código no existe.';
                if ($problema) {
                    throw ValidationException::withMessages(['cupon' => $problema]);
                }
                $descuento = $cupon->descuento($carrito->n_subtotal);
                $cupon->increment('n_usos');
                $carrito->forceFill(['x_cupon' => $cupon->c_codigo, 'n_descuento' => $descuento, 'n_total' => round($carrito->n_subtotal - $descuento, 2)]);
            }

            // El pedido genera la cotización: su código es lo que el cliente envía por WhatsApp.
            $carrito->forceFill(['x_estado' => 'pedido', 'f_pedido' => now(), 'f_acepta_terminos' => now()]);
            Cotizaciones::asignar($carrito);
            $carrito->save();

            return $carrito;
        });

        return response()->json([
            'pedido' => $carrito->n_carrito,
            'cotizacion' => $carrito->c_cotizacion,
            'vence' => $carrito->f_vence?->toDateString(),
            'whatsapp_url' => Cotizaciones::whatsapp($carrito),
        ]);
    }

    /** Vista pública de una cotización por su código (para el enlace que se comparte). */
    public function cotizacion(string $codigo): JsonResponse
    {
        $carrito = Carrito::where('c_cotizacion', Str::upper($codigo))->firstOrFail();

        return response()->json(Cotizaciones::publica($carrito));
    }

    private function validar(Request $request, bool $pedido): array
    {
        $requerido = $pedido ? 'required' : 'nullable';
        // Datos que cada lugar de entrega exige al enviar el pedido.
        $si = fn (string ...$tipos) => $pedido ? 'required_if:contacto.entrega,'.implode(',', $tipos) : 'nullable';

        return $request->validate([
            'token' => ['nullable', 'uuid'],
            'items' => ['present', 'array', 'max:50'],
            'items.*.id' => ['required', 'integer'],
            'items.*.cantidad' => ['required', 'integer', 'min:1', 'max:99'],
            'contacto' => [$pedido ? 'required' : 'nullable', 'array'],
            'contacto.nombre' => [$requerido, 'string', 'max:120'],
            'contacto.celular' => [$requerido, 'string', 'regex:/^\+?[0-9 ]{9,15}$/'],
            'contacto.correo' => ['nullable', 'email', 'max:150'],
            'contacto.entrega' => [$requerido, 'in:'.implode(',', array_keys(config('tienda.entregas')))],
            // La lista de estaciones solo se exige al pedir: la sincronización guarda lo que haya.
            'contacto.estacion' => [$si('estacion'), 'nullable', $pedido ? 'in:'.implode(',', config('tienda.estaciones')) : 'string', 'max:60'],
            'contacto.direccion' => [$si('local', 'nacional'), 'nullable', 'string', 'max:255'],
            'contacto.ciudad' => [$si('local', 'nacional'), 'nullable', 'string', 'max:120'],
            'contacto.dni' => [$si('nacional'), 'nullable', 'regex:/^[0-9]{8}$|^[A-Za-z0-9]{9,12}$/'],
            'contacto.pago' => [$requerido, 'string', 'max:30'],
            // Ley 29733: el pedido exige aceptar términos y política de privacidad.
            'acepta' => [$pedido ? 'accepted' : 'nullable'],
            'origen' => ['nullable', 'string', 'max:255'],
            'cupon' => ['nullable', 'string', 'max:30'],
        ], [
            'contacto.celular.regex' => 'Ingresa un celular válido (9 dígitos).',
            'contacto.dni.regex' => 'Ingresa un DNI de 8 dígitos (o tu carné de extranjería).',
            'contacto.estacion.required_if' => 'Elige la estación del tren.',
            'contacto.direccion.required_if' => 'Indica la dirección o la agencia Shalom.',
            'contacto.ciudad.required_if' => 'Indica el distrito o la ciudad.',
            'contacto.dni.required_if' => 'Shalom pide el DNI de quien recoge el envío.',
            'acepta.accepted' => 'Debes aceptar los términos y la política de privacidad.',
        ]);
    }

    private function guardar(Request $request, array $datos): Carrito
    {
        return DB::transaction(function () use ($request, $datos) {
            $carrito = ! empty($datos['token']) ? Carrito::where('c_token', $datos['token'])->lockForUpdate()->first() : null;
            if (! $carrito || ! in_array($carrito->x_estado, ['activo', 'vacio'], true)) {
                $carrito = new Carrito([
                    'c_token' => (string) Str::uuid(),
                    'x_origen' => isset($datos['origen']) ? Str::limit($datos['origen'], 250, '') : null,
                    'x_ip' => $request->ip(),
                    'x_dispositivo' => Str::limit(self::dispositivo((string) $request->userAgent()), 115, ''),
                ]);
                $carrito->save();
            }

            // Solo productos visibles y vendibles, con el precio vigente.
            $cantidades = collect($datos['items'])->mapWithKeys(fn ($i) => [(int) $i['id'] => (int) $i['cantidad']]);
            $productos = Producto::visibles()->whereIn('n_producto', $cantidades->keys())->where('l_estado', '!=', '0')->get()->keyBy('n_producto');

            $actuales = $carrito->items()->get()->keyBy('n_producto');
            foreach ($actuales as $id => $item) {
                if (! $productos->has($id)) {
                    $item->delete();
                }
            }
            foreach ($productos as $id => $producto) {
                $item = $actuales->get($id) ?? $carrito->items()->make(['n_producto' => $id]);
                $item->fill(['n_cantidad' => $cantidades[$id], 'n_precio' => $producto->precioFinal()]);
                if (! $item->exists || $item->isDirty()) {
                    $item->save();
                }
            }

            $contacto = array_filter($datos['contacto'] ?? [], fn ($v) => $v !== null && $v !== '');
            $carrito->fill([
                'x_nombre' => $contacto['nombre'] ?? $carrito->x_nombre,
                'x_celular' => isset($contacto['celular']) ? preg_replace('/\s+/', '', $contacto['celular']) : $carrito->x_celular,
                'x_correo' => $contacto['correo'] ?? $carrito->x_correo,
                'x_entrega' => $contacto['entrega'] ?? $carrito->x_entrega,
                'x_estacion' => $contacto['estacion'] ?? $carrito->x_estacion,
                'x_direccion' => $contacto['direccion'] ?? $carrito->x_direccion,
                'x_ciudad' => $contacto['ciudad'] ?? $carrito->x_ciudad,
                'x_dni' => $contacto['dni'] ?? $carrito->x_dni,
                'x_pago' => $contacto['pago'] ?? $carrito->x_pago,
                'n_items' => $productos->sum(fn ($p, $id) => $cantidades[$id]),
                'n_subtotal' => $subtotal = round($productos->sum(fn ($p, $id) => $p->precioFinal() * $cantidades[$id]), 2),
                'n_total' => $subtotal,
                'x_cupon' => null,
                'n_descuento' => 0,
                'f_actividad' => now(),
            ]);
            $carrito->x_estado = $carrito->n_items > 0 ? 'activo' : 'vacio';
            $carrito->save();

            return $carrito;
        });
    }

    /** Resumen legible del navegador: "Android · Chrome", "iPhone · Safari", "Windows · Edge"… */
    private static function dispositivo(string $agente): string
    {
        $so = match (true) {
            str_contains($agente, 'iPhone') => 'iPhone',
            str_contains($agente, 'iPad') => 'iPad',
            str_contains($agente, 'Android') => 'Android',
            str_contains($agente, 'Windows') => 'Windows',
            str_contains($agente, 'Mac OS') => 'Mac',
            str_contains($agente, 'Linux') => 'Linux',
            default => 'Otro',
        };
        $navegador = match (true) {
            str_contains($agente, 'Edg/') => 'Edge',
            str_contains($agente, 'OPR/') => 'Opera',
            str_contains($agente, 'FBAN') || str_contains($agente, 'FBAV') => 'Facebook',
            str_contains($agente, 'Instagram') => 'Instagram',
            str_contains($agente, 'Chrome') => 'Chrome',
            str_contains($agente, 'Firefox') => 'Firefox',
            str_contains($agente, 'Safari') => 'Safari',
            default => 'Navegador',
        };

        return "{$so} · {$navegador}";
    }
}
