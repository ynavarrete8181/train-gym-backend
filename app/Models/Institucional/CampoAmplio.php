<?php

namespace App\Models\Institucional;

use Illuminate\Database\Eloquent\Model;

class CampoAmplio extends Model
{
    protected $table = 'institucional.campos_amplios';

    protected $primaryKey = 'id_campo_amplio';

    protected $fillable = ['codigo', 'nombre', 'activo'];

    protected $casts = ['activo' => 'boolean'];
}
