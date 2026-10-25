<?php

namespace App\Models;

use App\Support\Imagenes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    public const CREATED_AT = 'f_creado';
    public const UPDATED_AT = 'f_actualizado';

    protected $table = 'mae_banner';
    protected $primaryKey = 'n_banner';
    protected $guarded = ['n_banner'];

    protected function casts(): array
    {
        return ['f_inicio' => 'date', 'f_fin' => 'date'];
    }

    /** Activos y dentro de su vigencia (fechas opcionales). */
    public function scopeVigentes(Builder $query): Builder
    {
        $hoy = now()->toDateString();

        return $query->where('l_activo', '1')
            ->where(fn ($q) => $q->whereNull('f_inicio')->orWhere('f_inicio', '<=', $hoy))
            ->where(fn ($q) => $q->whereNull('f_fin')->orWhere('f_fin', '>=', $hoy))
            ->orderBy('n_orden')->orderBy('n_banner');
    }

    public function datos(bool $admin = false): array
    {
        return [
            'id' => $this->n_banner,
            'titulo' => $this->x_titulo,
            'subtitulo' => $this->x_subtitulo,
            'boton' => $this->x_boton,
            'enlace' => $this->x_enlace,
            'imagen' => Imagenes::url($this->x_imagen),
            'imagen_movil' => $this->x_imagen_movil ? Imagenes::url($this->x_imagen_movil) : null,
            'tema' => $this->x_tema,
        ] + ($admin ? [
            'orden' => $this->n_orden,
            'activo' => $this->l_activo === '1',
            'inicio' => $this->f_inicio?->toDateString(),
            'fin' => $this->f_fin?->toDateString(),
        ] : []);
    }
}
