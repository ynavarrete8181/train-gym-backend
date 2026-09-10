<?php

namespace App\Models\Integraciones;

use Illuminate\Database\Eloquent\Model;

class ServicioExterno extends Model
{
    protected $table = 'integraciones.servicios';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['headers' => 'array', 'configuracion' => 'array', 'activo' => 'boolean'];
    }
}
