<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Support\Costos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Parámetros de la fórmula de costos (flete, aduana, empaque, entrega, comisión, margen objetivo). */
class ParametrosController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['valores' => Costos::parametros(), 'etiquetas' => Costos::PARAMETROS]);
    }

    public function update(Request $request): JsonResponse
    {
        $reglas = array_map(fn () => ['required', 'numeric', 'min:0', 'max:100000'], Costos::PARAMETROS);
        $reglas['margen_objetivo_pct'][] = 'max:95';
        Costos::guardar($request->validate($reglas));

        return $this->index();
    }
}
