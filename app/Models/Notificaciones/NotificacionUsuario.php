<?php

namespace App\Models\Notificaciones;

use Illuminate\Database\Eloquent\Model;

class NotificacionUsuario extends Model
{
    protected $table = 'notificaciones.notificaciones';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['encolado_at' => 'datetime', 'enviado_at' => 'datetime'];
    }
}
