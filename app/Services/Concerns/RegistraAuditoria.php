<?php

namespace App\Services\Concerns;

use App\Services\Auditoria\AuditoriaServicio;

/**
 * Mixin liviano para que cualquier servicio de "sistema base" deje rastro en
 * auditoria.eventos sin acoplarse a la implementación. Un fallo al auditar
 * nunca debe tumbar la operación de negocio (ver AuditoriaServicio::registrar()).
 */
trait RegistraAuditoria
{
    protected function auditar(string $modulo, string $accion, ?string $tabla = null, mixed $registroId = null, mixed $antes = null, mixed $despues = null, ?string $descripcion = null): void
    {
        app(AuditoriaServicio::class)->registrar([
            'modulo' => $modulo,
            'tabla' => $tabla,
            'accion' => $accion,
            'registro_id' => $registroId,
            'datos_antes' => $antes,
            'datos_despues' => $despues,
            'descripcion' => $descripcion,
        ]);
    }
}
