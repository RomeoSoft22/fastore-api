<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Categoria extends Model
{
    protected $table = 'mae_categoria';
    protected $primaryKey = 'n_categoria';
    public $timestamps = false;
    protected $guarded = ['n_categoria'];

    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class, 'n_categoria');
    }
}
