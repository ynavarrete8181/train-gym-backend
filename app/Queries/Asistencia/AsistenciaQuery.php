<?php

namespace App\Queries\Asistencia;

use Illuminate\Support\Facades\DB;

class AsistenciaQuery
{
    public function listar(array $filters = []): array
    {
        $query = DB::table('asistencia.registros as ar')
            ->join('core.personas as p', 'p.id', '=', 'ar.persona_id')
            ->join('core.sedes as s', 's.id', '=', 'ar.sede_id')
            ->leftJoin('staff.perfiles as sp', 'sp.id', '=', 'ar.coach_id')
            ->leftJoin('core.personas as coach_persona', 'coach_persona.id', '=', 'sp.persona_id')
            ->selectRaw("
                ar.*,
                COALESCE(ar.persona_cedula, p.numero_identificacion) as persona_cedula,
                COALESCE(ar.registrado_por_usuario_cedula, '') as registrado_por_usuario_cedula,
                CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, '')) as persona_nombre,
                s.nombre as sede_nombre,
                CONCAT(COALESCE(coach_persona.nombres, ''), ' ', COALESCE(coach_persona.apellidos, '')) as coach_nombre
            ")
            ->orderByDesc('ar.fecha_hora');

        foreach (['persona_id', 'sede_id'] as $field) {
            if (!empty($filters[$field])) {
                $query->where("ar.$field", (int) $filters[$field]);
            }
        }

        if (!empty($filters['fecha_desde'])) {
            $query->where('ar.fecha_hora', '>=', $filters['fecha_desde'] . ' 00:00:00');
        }

        if (!empty($filters['fecha_hasta'])) {
            $query->where('ar.fecha_hora', '<=', $filters['fecha_hasta'] . ' 23:59:59');
        }

        if (!empty($filters['cedula'])) {
            $cedula = '%' . trim((string) $filters['cedula']) . '%';
            $query->whereRaw("COALESCE(ar.persona_cedula, p.numero_identificacion, '') ILIKE ?", [$cedula]);
        }

        if (!empty($filters['buscar'])) {
            $buscar = '%' . trim((string) $filters['buscar']) . '%';
            $query->where(function ($q) use ($buscar) {
                $q->whereRaw("COALESCE(ar.persona_cedula, p.numero_identificacion, '') ILIKE ?", [$buscar])
                    ->orWhereRaw("CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, '')) ILIKE ?", [$buscar])
                    ->orWhereRaw("COALESCE(s.nombre, '') ILIKE ?", [$buscar])
                    ->orWhereRaw("COALESCE(ar.metodo, '') ILIKE ?", [$buscar])
                    ->orWhereRaw("COALESCE(ar.request_id, '') ILIKE ?", [$buscar]);
            });
        }

        return $query->limit(200)->get()->map(fn ($row) => [
            'id' => (int) $row->id,
            'persona_id' => (int) $row->persona_id,
            'persona_nombre' => trim((string) $row->persona_nombre),
            'persona_cedula' => $row->persona_cedula,
            'sede_id' => (int) $row->sede_id,
            'sede_nombre' => $row->sede_nombre,
            'reserva_id' => $row->reserva_id ? (int) $row->reserva_id : null,
            'coach_id' => $row->coach_id ? (int) $row->coach_id : null,
            'coach_nombre' => trim((string) $row->coach_nombre) ?: null,
            'staff_cliente_asignacion_id' => $row->staff_cliente_asignacion_id ? (int) $row->staff_cliente_asignacion_id : null,
            'turno_recurrente_id' => $row->turno_recurrente_id ? (int) $row->turno_recurrente_id : null,
            'fecha_hora' => $row->fecha_hora,
            'tipo' => $row->tipo,
            'metodo' => $row->metodo,
            'origen' => $row->origen,
            'estado' => $row->estado,
            'motivo' => $row->motivo,
            'request_id' => $row->request_id,
            'registrado_por_usuario_cedula' => $row->registrado_por_usuario_cedula ?: null,
        ])->all();
    }
}
