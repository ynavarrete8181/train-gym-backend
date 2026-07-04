<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class AuthUsuario extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $table = 'seguridad.usuarios';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $hidden = [
        'password_hash',
    ];
}
