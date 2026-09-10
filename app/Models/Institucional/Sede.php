<?php

namespace App\Models\Institucional;

use Illuminate\Database\Eloquent\Model;

class Sede extends Model
{
    protected $table = 'institucional.sedes';

    protected $primaryKey = 'id_sede';

    protected $fillable = ['codigo', 'nombre', 'activo'];

    protected $casts = ['activo' => 'boolean'];
}
