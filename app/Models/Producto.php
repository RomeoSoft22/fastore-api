<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Tabla heredada mae_productos. l_estado: 0=Agotado, 1=Con stock, 2=A pedido · l_producto: N=Nuevo, U=Usado.
 * x_descuento es un porcentaje. f_registro la actualiza MySQL en cada cambio; f_creado es la fecha de alta.
 */
class Producto extends Model
{
    public const ESTADOS = ['0' => 'agotado', '1' => 'disponible', '2' => 'a_pedido'];

    protected $table = 'mae_productos';
    protected $primaryKey = 'n_producto';
    public $timestamps = false;
    protected $guarded = ['n_producto', 'f_registro'];

    protected function casts(): array
    {
        return ['x_precio' => 'float', 'x_descuento' => 'integer', 'n_stock' => 'integer', 'f_creado' => 'datetime', 'f_registro' => 'datetime'];
    }

    public function marca(): BelongsTo
    {
        return $this->belongsTo(Marca::class, 'n_marca');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'n_categoria');
    }

    public function imagenes(): HasMany
    {
        return $this->hasMany(ProductoImagen::class, 'n_producto')->orderBy('n_orden')->orderBy('n_imagen');
    }

    public function portada(): HasOne
    {
        return $this->hasOne(ProductoImagen::class, 'n_producto')->ofMany(['n_orden' => 'min', 'n_imagen' => 'min']);
    }

    public function scopeVisibles(Builder $query): Builder
    {
        return $query->where('mae_productos.l_activo', '1');
    }

    public function precioFinal(): float
    {
        $precio = (float) $this->x_precio;

        return $this->x_descuento > 0 ? round($precio * (100 - min(99, $this->x_descuento)) / 100, 2) : $precio;
    }

    public function vendible(): bool
    {
        return $this->l_activo === '1' && $this->l_estado !== '0';
    }
}
