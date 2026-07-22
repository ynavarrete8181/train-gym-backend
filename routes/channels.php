<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('notificaciones.usuario.{usuarioId}', function ($user, $usuarioId) {
    return (int) $user->id === (int) $usuarioId;
});

Broadcast::channel('notificaciones.persona.{personaId}', function ($user, $personaId) {
    return (int) $user->persona_id === (int) $personaId;
});
