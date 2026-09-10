<?php

namespace App\Models\Institucional;

use Illuminate\Database\Eloquent\Model;

class Contexto extends Model
{
    protected $table = 'institucional.contextos';

    protected $primaryKey = 'id_contexto';

    protected $fillable = ['id_sede', 'id_unidad', 'id_carrera_area', 'activo'];

    protected $casts = ['activo' => 'boolean'];
}
