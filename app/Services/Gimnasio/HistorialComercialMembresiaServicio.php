<?php

namespace App\Services\Gimnasio;

use Illuminate\Support\Facades\DB;

/**
 * Trazabilidad de negocio del esquema membresias.
 * Nunca consulta auditoria.eventos para reconstruir precios o contratos.
 * Invocar dentro de la misma transacción de negocio.
 */
class HistorialComercialMembresiaServicio
{
    public function registrarModalidad(
        int $planId,
        int $modalidadId,
        string $accion,
        ?array $antes,
        array $despues
    ): void {
        // Serializar escritores por modalidad para mantener versiones correlativas.
        DB::table('membresias.plan_modalidades')->where('id', $modalidadId)->lockForUpdate()->first();
        $version = (int) DB::table('membresias.plan_modalidad_versiones')
            ->where('modalidad_id', $modalidadId)->max('version') + 1;

        DB::table('membresias.plan_modalidad_versiones')->insert([
            'plan_id' => $planId,
            'modalidad_id' => $modalidadId,
            'version' => $version,
            'accion' => $accion,
            'condiciones_anteriores' => $antes === null ? null : json_encode($antes, JSON_THROW_ON_ERROR),
            'condiciones_nuevas' => json_encode($despues, JSON_THROW_ON_ERROR),
            'usuario_id' => auth()->id(),
            'registrado_at' => now(),
        ]);
    }

    public function registrarContrato(
        int $membresiaId,
        string $accion,
        array $condiciones,
        ?string $motivo = null
    ): void {
        DB::table('membresias.membresias')->where('id', $membresiaId)->lockForUpdate()->first();
        $version = (int) DB::table('membresias.membresia_condiciones_historial')
            ->where('membresia_id', $membresiaId)->max('version') + 1;

        DB::table('membresias.membresia_condiciones_historial')->insert([
            'membresia_id' => $membresiaId,
            'version' => $version,
            'accion' => $accion,
            'condiciones' => json_encode($condiciones, JSON_THROW_ON_ERROR),
            'usuario_id' => auth()->id(),
            'motivo' => $motivo,
            'registrado_at' => now(),
        ]);
    }
}
