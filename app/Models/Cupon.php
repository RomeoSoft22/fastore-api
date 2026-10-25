<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cupon extends Model
{
    public const CREATED_AT = 'f_creado';
    public const UPDATED_AT = 'f_actualizado';

    protected $table = 'car_cupon';
    protected $primaryKey = 'n_cupon';
    protected $guarded = ['n_cupon'];

    protected function casts(): array
    {
        return ['n_valor' => 'float', 'n_minimo' => 'float', 'f_inicio' => 'date', 'f_fin' => 'date'];
    }

    /** Motivo por el que no se puede usar, o null si es válido para ese subtotal. */
    public function problema(float $subtotal): ?string
    {
        $hoy = now()->startOfDay();

        return match (true) {
            $this->l_activo !== '1' => 'Este código ya no está activo.',
            $this->f_inicio && $this->f_inicio->gt($hoy) => 'Este código aún no está vigente.',
            $this->f_fin && $this->f_fin->lt($hoy) => 'Este código ya venció.',
            $this->n_max_usos !== null && $this->n_usos >= $this->n_max_usos => 'Este código ya alcanzó su límite de usos.',
            $subtotal < $this->n_minimo => 'Este código aplica a compras desde S/ '.number_format($this->n_minimo, 2).'.',
            default => null,
        };
    }

    public function descuento(float $subtotal): float
    {
        $monto = $this->x_tipo === 'porcentaje' ? $subtotal * min(100, $this->n_valor) / 100 : $this->n_valor;

        return round(min($subtotal, $monto), 2);
    }

    public function etiqueta(): string
    {
        return $this->x_tipo === 'porcentaje' ? rtrim(rtrim(number_format($this->n_valor, 2), '0'), '.').'% de descuento' : 'S/ '.number_format($this->n_valor, 2).' de descuento';
    }

    public function datos(): array
    {
        return [
            'id' => $this->n_cupon,
            'codigo' => $this->c_codigo,
            'descripcion' => $this->x_descripcion,
            'tipo' => $this->x_tipo,
            'valor' => $this->n_valor,
            'minimo' => $this->n_minimo,
            'max_usos' => $this->n_max_usos,
            'usos' => $this->n_usos,
            'inicio' => $this->f_inicio?->toDateString(),
            'fin' => $this->f_fin?->toDateString(),
            'activo' => $this->l_activo === '1',
            'etiqueta' => $this->etiqueta(),
            'vigente' => $this->problema(PHP_FLOAT_MAX) === null,
        ];
    }
}
