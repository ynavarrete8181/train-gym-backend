<?php

namespace App\Services\Gimnasio;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Consulta unificada de trazabilidad. No modifica contratos ni historial.
 * Las condiciones originalmente pactadas se leen de la primera contratación
 * registrada; nunca se reconstruyen a partir de la vigencia vigente.
 */
final class HistorialIntegralMembresiaServicio
{
    public function consultar(int $membresiaId): array
    {
        $m = DB::table('membresias.membresias')
            ->where('id', $membresiaId)->first();
        if (! $m) {
            throw ValidationException::withMessages([
                'membresia_id' => 'La membresía no existe.',
            ]);
        }

        $historialComercial = DB::table('membresias.membresia_condiciones_historial as h')
            ->leftJoin('seguridad.users as u', 'u.id', '=', 'h.usuario_id')
            ->where('h.membresia_id', $membresiaId)
            ->orderBy('h.registrado_at')->orderBy('h.id')
            ->get(['h.id', 'h.version', 'h.accion', 'h.condiciones', 'h.usuario_id',
                'h.motivo', 'h.registrado_at', 'u.name as autor_nombre']);

        $contratacion = $historialComercial->first(
            fn ($h) => $h->accion === 'CONTRATACION'
        );
        $original = $contratacion
            ? (json_decode((string) $contratacion->condiciones, true) ?: [])
            : null;

        $suspensiones = DB::table('membresias.membresia_suspensiones')
            ->where('membresia_id', $membresiaId)->get()->keyBy('id');
        $movimientos = DB::table('membresias.membresia_movimientos as mov')
            ->leftJoin('seguridad.users as u', 'u.id', '=', 'mov.usuario_id')
            ->where('mov.membresia_id', $membresiaId)
            ->orderBy('mov.created_at')->orderBy('mov.id')
            ->get(['mov.id', 'mov.tipo', 'mov.estado', 'mov.fecha_efectiva',
                'mov.motivo', 'mov.usuario_id', 'mov.movimiento_origen_id',
                'mov.detalle', 'mov.created_at', 'u.name as autor_nombre']);

        $eventos = [];
        foreach ($historialComercial as $registro) {
            $datos = json_decode((string) $registro->condiciones, true) ?: [];
            $esContratacion = $registro->accion === 'CONTRATACION';
            $eventos[] = [
                'id' => 'contrato-' . $registro->id,
                'categoria' => 'COMERCIAL',
                'tipo' => $registro->accion,
                'estado' => 'CONFIRMADO',
                'fecha' => $registro->registrado_at,
                'usuario_id' => $registro->usuario_id,
                'autor_nombre' => $registro->autor_nombre,
                'motivo' => $registro->motivo,
                'es_contratacion' => $esContratacion,
                'detalle' => $datos,
                'version' => $registro->version,
            ];
        }
        foreach ($movimientos as $mov) {
            $detalle = json_decode((string) $mov->detalle, true) ?: [];
            $suspension = $suspensiones->first(
                fn ($s) => (int) $s->movimiento_inicio_id === (int) $mov->id
                    || (int) ($s->movimiento_fin_id ?? 0) === (int) $mov->id
                    || (int) ($detalle['suspension_id'] ?? 0) === (int) $s->id
            );
            $evento = [
                'id' => 'ciclo-' . $mov->id,
                'categoria' => 'CICLO_VIDA',
                'tipo' => $mov->tipo,
                'estado' => $mov->estado,
                'fecha' => $mov->created_at,
                'fecha_efectiva' => $mov->fecha_efectiva,
                'usuario_id' => $mov->usuario_id,
                'autor_nombre' => $mov->autor_nombre,
                'motivo' => $mov->motivo,
                'movimiento_origen_id' => $mov->movimiento_origen_id,
                'detalle' => $detalle,
            ];
            if ($suspension) {
                $evento['suspension'] = [
                    'id' => $suspension->id,
                    'estado' => $suspension->estado,
                    'tipo' => $suspension->tipo,
                    'fecha_inicio' => $suspension->fecha_inicio,
                    'fecha_fin_prevista' => $suspension->fecha_fin_prevista,
                    'fecha_fin_real' => $suspension->fecha_fin_real,
                    'dias_reconocidos' => $suspension->dias_reconocidos,
                    'fecha_fin_original' => $suspension->fecha_fin_original,
                    'fecha_fin_ajustada' => $suspension->fecha_fin_ajustada,
                ];
            }
            $eventos[] = $evento;
        }

        usort($eventos, static function (array $a, array $b): int {
            $orden = strcmp((string) $b['fecha'], (string) $a['fecha']);
            return $orden ?: strcmp((string) $b['id'], (string) $a['id']);
        });

        return [
            'membresia_id' => $membresiaId,
            'codigo_contrato' => $m->codigo_contrato,
            'estado_actual' => $m->estado,
            'vigencia_actual' => [
                'fecha_inicio' => $m->fecha_inicio,
                'fecha_fin' => $m->fecha_fin,
            ],
            // Para contratos antiguos sin registro de contratación,
            // devolver null en lugar de inventar una fecha original.
            'contratacion_original' => $original,
            'eventos' => $eventos,
            'total_eventos' => count($eventos),
        ];
    }
}
