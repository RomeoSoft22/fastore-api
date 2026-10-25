<?php

namespace App\Models;

use App\Support\Imagenes;
use Illuminate\Database\Eloquent\Model;

class ProductoImagen extends Model
{
    public const CREATED_AT = 'f_creado';
    public const UPDATED_AT = null;

    protected $table = 'mae_producto_imagen';
    protected $primaryKey = 'n_imagen';
    protected $guarded = ['n_imagen'];

    public function datos(): array
    {
        return [
            'id' => $this->n_imagen,
            'url' => Imagenes::url($this->x_archivo),
            'miniatura' => Imagenes::url($this->x_miniatura),
            'alt' => $this->x_alt,
            'ancho' => $this->n_ancho,
            'alto' => $this->n_alto,
        ];
    }
}
