<?php

namespace App\Models\Institucional;

use Illuminate\Database\Eloquent\Model;

class CarreraArea extends Model
{
    protected $table = 'institucional.carreras_areas';

    protected $primaryKey = 'id_carrera_area';

    protected $fillable = [
        'id_unidad',
        'id_sede_unidad',
        'codigo',
        'codigo_ces',
        'nombre',
        'tipo',
        'id_campo_amplio',
        'activo',
    ];

    protected $casts = ['activo' => 'boolean'];
}
