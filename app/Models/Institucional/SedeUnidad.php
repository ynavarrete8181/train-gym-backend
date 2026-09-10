<?php

namespace App\Models\Institucional;

use Illuminate\Database\Eloquent\Model;

class SedeUnidad extends Model
{
    protected $table = 'institucional.sede_unidad';

    protected $fillable = ['id_sede', 'id_unidad', 'codigo', 'activo'];

    protected $casts = ['activo' => 'boolean'];
}
