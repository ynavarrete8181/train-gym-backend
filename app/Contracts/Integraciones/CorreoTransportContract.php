<?php

namespace App\Contracts\Integraciones;

interface CorreoTransportContract
{
    public function enviar(array $mensaje): array;
}
