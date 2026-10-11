<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NavegacionSistemaActualizada implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly string $codigo) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('sistema.navegacion');
    }

    public function broadcastAs(): string
    {
        return 'sistema.actualizado';
    }

    public function broadcastWith(): array
    {
        return [
            'tipo' => 'MENU_ACTUALIZADO',
            'datos' => ['id_menu' => $this->codigo],
        ];
    }
}
