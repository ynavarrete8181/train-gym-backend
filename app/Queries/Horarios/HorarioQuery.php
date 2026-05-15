<?php

namespace App\Queries\Horarios;

use Illuminate\Support\Facades\DB;

class HorarioQuery
{
    public function all(): array
    {
        return array_map(
            fn ($row) => $this->mapRow($row),
            DB::select($this->baseSql() . ' ORDER BY h.id DESC')
        );
    }

    public function find(int $id): ?array
    {
        $result = DB::select($this->baseSql('WHERE h.id = ?') . ' LIMIT 1', [$id]);
        $row = $result[0] ?? null;

        return $row ? $this->mapRow($row) : null;
    }

    private function mapRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'horario_id' => (int) $row->id,
            'sede_id' => (int) $row->sede_id,
            'tipo_servicio_id' => (int) $row->tipo_servicio_id,
            'hora_apertura' => $row->hora_apertura,
            'hora_cierre' => $row->hora_cierre,
            'dias_laborables' => json_decode($row->dias_laborables ?? '[]', true) ?? [],
            'capacidad_maxima' => (int) $row->capacidad_maxima,
            'tiempo_turno_min' => (int) $row->tiempo_turno_min,
            'tipo_usuario' => (int) $row->tipo_usuario,
            'activo' => (bool) $row->activo,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
            'tipo_servicio_nombre' => $row->tipo_servicio_nombre,
            'tipo_servicio_descripcion' => $row->tipo_servicio_descripcion,
            'tipo_servicio_breve_desc' => $row->tipo_servicio_breve_desc,
            'categoria_id' => $row->categoria_id ? (int) $row->categoria_id : null,
            'categoria_nombre' => $row->categoria_nombre,
            'categoria_descripcion' => $row->categoria_descripcion,
        ];
    }

    private function baseSql(string $where = ''): string
    {
        return "
            SELECT
                h.id,
                h.sede_id,
                h.tipo_servicio_id,
                h.hora_apertura,
                h.hora_cierre,
                COALESCE(
                    json_agg(d.dia_semana ORDER BY d.dia_semana) FILTER (WHERE d.dia_semana IS NOT NULL),
                    '[]'::json
                )::text AS dias_laborables,
                h.capacidad_maxima,
                h.tiempo_turno_min,
                h.tipo_usuario,
                h.activo,
                h.created_at,
                h.updated_at,
                ts.nombre AS tipo_servicio_nombre,
                ts.descripcion AS tipo_servicio_descripcion,
                ts.breve_desc AS tipo_servicio_breve_desc,
                ts.categoria_id,
                cs.nombre AS categoria_nombre,
                cs.descripcion AS categoria_descripcion
            FROM train_gimnasio.horarios_gym h
            LEFT JOIN train_gimnasio.horarios_gym_dias d
                ON d.horario_id = h.id
            LEFT JOIN train_gimnasio.tipos_servicios ts
                ON ts.id = h.tipo_servicio_id
            LEFT JOIN train_gimnasio.categoria_servicios cs
                ON cs.id = ts.categoria_id
            {$where}
            GROUP BY h.id, ts.id, cs.id
        ";
    }
}

