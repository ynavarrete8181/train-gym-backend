<?php

namespace App\Models\Seguridad;

use Illuminate\Database\Eloquent\Model;

class TokenAcceso extends Model
{
    protected $table = 'seguridad.tokens_acceso';

    protected $fillable = [
        'id_usuario',
        'nombre',
        'token_hash',
        'ultimo_uso_en',
        'expira_en',
    ];

    protected $casts = [
        'ultimo_uso_en' => 'datetime',
        'expira_en' => 'datetime',
    ];
}
