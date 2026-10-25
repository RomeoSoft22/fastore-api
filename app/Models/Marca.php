<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Marca extends Model
{
    protected $table = 'mae_marca';
    protected $primaryKey = 'n_marca';
    public $timestamps = false;
    protected $guarded = ['n_marca'];

    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class, 'n_marca');
    }
}
