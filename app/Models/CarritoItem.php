<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarritoItem extends Model
{
    public const CREATED_AT = 'f_agregado';
    public const UPDATED_AT = 'f_actualizado';

    protected $table = 'car_carrito_item';
    protected $primaryKey = 'n_item';
    protected $guarded = ['n_item'];

    protected function casts(): array
    {
        return ['n_precio' => 'float', 'f_agregado' => 'datetime', 'f_actualizado' => 'datetime'];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'n_producto');
    }
}
