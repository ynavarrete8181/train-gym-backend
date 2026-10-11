<?php

namespace App\Models\Institucional;

use Illuminate\Database\Eloquent\Model;

class UsuarioContexto extends Model
{
    protected $table = 'institucional.usuario_contexto';

    protected $fillable = ['id_usuario', 'id_contexto', 'principal', 'activo'];

    protected $casts = ['principal' => 'boolean', 'activo' => 'boolean'];
}
