<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('usuario.{usuarioId}', function ($usuario, int $usuarioId): bool {
    return (int) $usuario->id === $usuarioId;
});

Broadcast::channel('sistema.navegacion', fn ($usuario): bool => (bool) $usuario);
