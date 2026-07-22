<?php

namespace App\Events\Notificaciones;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificacionCreada implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public array $notificacion,
        public ?int $usuarioId = null,
        public ?int $personaId = null,
    ) {
    }

    public function broadcastAs(): string
    {
        return 'notificacion.creada';
    }

    public function broadcastOn(): array
    {
        $channels = [];

        if ($this->usuarioId) {
            $channels[] = new PrivateChannel('notificaciones.usuario.' . $this->usuarioId);
        }

        if ($this->personaId) {
            $channels[] = new PrivateChannel('notificaciones.persona.' . $this->personaId);
        }

        return $channels;
    }

    public function broadcastWith(): array
    {
        return $this->notificacion;
    }
}
