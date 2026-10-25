<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Hoja del Libro de Reclamaciones virtual (reclamo o queja). */
class Reclamo extends Model
{
    public const CREATED_AT = 'f_creado';
    public const UPDATED_AT = 'f_actualizado';

    protected $table = 'rcl_reclamo';
    protected $primaryKey = 'n_reclamo';
    protected $guarded = ['n_reclamo'];

    protected function casts(): array
    {
        return ['n_monto' => 'float', 'f_respuesta' => 'datetime', 'f_creado' => 'datetime'];
    }

    public function datos(): array
    {
        return [
            'id' => $this->n_reclamo,
            'codigo' => $this->c_codigo,
            'tipo' => $this->x_tipo,
            'nombre' => $this->x_nombre,
            'documento' => $this->x_documento_tipo.' '.$this->x_documento,
            'domicilio' => $this->x_domicilio,
            'correo' => $this->x_correo,
            'telefono' => $this->x_telefono,
            'apoderado' => $this->x_apoderado,
            'bien' => $this->x_bien,
            'monto' => $this->n_monto,
            'pedido_ref' => $this->x_pedido,
            'descripcion_bien' => $this->x_descripcion_bien,
            'detalle' => $this->x_detalle,
            'pedido_consumidor' => $this->x_pedido_consumidor,
            'estado' => $this->x_estado,
            'respuesta' => $this->x_respuesta,
            'respondido' => $this->f_respuesta?->toIso8601String(),
            'creado' => $this->f_creado?->toIso8601String(),
            // Plazo legal para responder (días calendario aproximados a partir de días hábiles).
            'vence' => $this->f_creado?->copy()->addWeekdays((int) config('tienda.legal.plazo_reclamo_dias'))->toDateString(),
        ];
    }
}
