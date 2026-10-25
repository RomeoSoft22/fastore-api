<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reclamo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Libro de Reclamaciones virtual (Ley 29571 y D.S. 011-2011-PCM, modificado por D.S. 101-2022-PCM).
 * El consumidor registra su reclamo o queja y recibe un código; la tienda responde desde el admin
 * dentro del plazo legal.
 */
class ReclamoController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'tipo' => ['required', 'in:reclamo,queja'],
            'nombre' => ['required', 'string', 'max:150'],
            'documento_tipo' => ['required', 'in:DNI,CE,Pasaporte,RUC'],
            'documento' => ['required', 'string', 'max:20'],
            'domicilio' => ['required', 'string', 'max:255'],
            'correo' => ['required', 'email', 'max:150'],
            'telefono' => ['required', 'string', 'max:20'],
            'apoderado' => ['nullable', 'string', 'max:150'],
            'bien' => ['required', 'in:producto,servicio'],
            'monto' => ['nullable', 'numeric', 'min:0'],
            'pedido_ref' => ['nullable', 'string', 'max:40'],
            'descripcion_bien' => ['required', 'string', 'max:2000'],
            'detalle' => ['required', 'string', 'max:5000'],
            'pedido_consumidor' => ['required', 'string', 'max:3000'],
            'acepta' => ['accepted'],
        ], ['acepta.accepted' => 'Debes aceptar el tratamiento de tus datos para registrar el reclamo.']);

        $reclamo = DB::transaction(function () use ($datos, $request) {
            $reclamo = Reclamo::create([
                'c_codigo' => 'pendiente-'.uniqid(),
                'x_tipo' => $datos['tipo'],
                'x_nombre' => $datos['nombre'],
                'x_documento_tipo' => $datos['documento_tipo'],
                'x_documento' => $datos['documento'],
                'x_domicilio' => $datos['domicilio'],
                'x_correo' => $datos['correo'],
                'x_telefono' => $datos['telefono'],
                'x_apoderado' => $datos['apoderado'] ?? null,
                'x_bien' => $datos['bien'],
                'n_monto' => $datos['monto'] ?? null,
                'x_pedido' => $datos['pedido_ref'] ?? null,
                'x_descripcion_bien' => $datos['descripcion_bien'],
                'x_detalle' => $datos['detalle'],
                'x_pedido_consumidor' => $datos['pedido_consumidor'],
                'x_ip' => $request->ip(),
            ]);
            // Numeración correlativa por año, como exige la hoja de reclamación.
            $reclamo->update(['c_codigo' => sprintf('LR-%s-%05d', now('America/Lima')->format('Y'), $reclamo->n_reclamo)]);

            return $reclamo;
        });

        return response()->json($reclamo->datos(), 201);
    }

    // ------------------------------------------------------------------ admin

    public function index(Request $request): JsonResponse
    {
        $estado = $request->validate(['estado' => ['nullable', 'in:pendiente,atendido']])['estado'] ?? null;

        return response()->json([
            'datos' => Reclamo::when($estado, fn ($q) => $q->where('x_estado', $estado))->orderByDesc('n_reclamo')->get()->map->datos(),
            'pendientes' => Reclamo::where('x_estado', 'pendiente')->count(),
        ]);
    }

    public function responder(Request $request, Reclamo $reclamo): JsonResponse
    {
        $datos = $request->validate(['respuesta' => ['required', 'string', 'max:5000']]);
        $reclamo->update(['x_respuesta' => $datos['respuesta'], 'x_estado' => 'atendido', 'f_respuesta' => now()]);

        return response()->json($reclamo->datos());
    }
}
