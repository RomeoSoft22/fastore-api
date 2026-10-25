<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Carrito extends Model
{
    public const CREATED_AT = 'f_creado';
    public const UPDATED_AT = 'f_actualizado';
    public const ESTADOS = ['activo', 'pedido', 'vendido', 'descartado', 'vacio'];

    protected $table = 'car_carrito';
    protected $primaryKey = 'n_carrito';
    protected $guarded = ['n_carrito'];

    protected function casts(): array
    {
        return [
            'n_total' => 'float',
            'n_subtotal' => 'float',
            'n_descuento' => 'float',
            'n_costo_entrega' => 'float',
            'n_comision' => 'float',
            'f_venta' => 'datetime',
            'f_vence' => 'date',
            'f_acepta_terminos' => 'datetime',
            'f_actividad' => 'datetime',
            'f_pedido' => 'datetime',
            'f_contactado' => 'datetime',
            'f_creado' => 'datetime',
            'f_actualizado' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CarritoItem::class, 'n_carrito')->orderBy('f_agregado');
    }

    /** Con productos, sin pedido enviado y sin actividad desde hace TIENDA_ABANDONO_HORAS. */
    public function scopeAbandonados(Builder $query): Builder
    {
        return $query->where('x_estado', 'activo')->where('n_items', '>', 0)
            ->where('f_actividad', '<', now()->subHours((int) config('tienda.abandono_horas')));
    }

    public function abandonado(): bool
    {
        return $this->x_estado === 'activo' && $this->n_items > 0
            && (bool) $this->f_actividad?->lt(now()->subHours((int) config('tienda.abandono_horas')));
    }
}
