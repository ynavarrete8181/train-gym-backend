<?php

namespace App\Models\Institucional;

use Illuminate\Database\Eloquent\Model;

class Unidad extends Model
{
    protected $table = 'institucional.unidades';

    protected $primaryKey = 'id_unidad';

    protected $fillable = ['codigo', 'nombre', 'tipo', 'activo'];

    protected $casts = ['activo' => 'boolean'];
}
