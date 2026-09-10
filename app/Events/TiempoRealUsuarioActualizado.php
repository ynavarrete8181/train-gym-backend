<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TiempoRealUsuarioActualizado implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $usuarioId,
        public readonly string $tipo,
        public readonly array $datos,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('usuario.'.$this->usuarioId);
    }

    public function broadcastAs(): string
    {
        return 'sistema.actualizado';
    }

    public function broadcastWith(): array
    {
        return [
            'tipo' => $this->tipo,
            'datos' => $this->datos,
        ];
    }
}
