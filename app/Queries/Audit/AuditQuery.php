<?php

namespace App\Queries\Audit;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class AuditQuery
{
    public function search(array $filters = []): array
    {
        $limit = max(1, min((int) ($filters['limit'] ?? 50), 200));

        return $this->baseQuery($filters)
            ->limit($limit)
            ->get()
            ->map(fn ($row) => $this->mapRow($row))
            ->all();
    }

    public function summary(array $filters = []): array
    {
        $query = $this->baseQuery($filters);
        $rows = (clone $query)->get();

        $groupCount = function (string $field) use ($rows): array {
            return collect($rows)
                ->groupBy(fn ($row) => $row->{$field} ?? 'Sin dato')
                ->map(fn ($items, $key) => ['clave' => $key ?: 'Sin dato', 'total' => $items->count()])
                ->sortByDesc('total')
                ->values()
                ->all();
        };

        return [
            'total' => $rows->count(),
            'por_operacion' => $groupCount('operacion'),
            'por_modulo' => $groupCount('modulo'),
            'por_tabla' => $groupCount('tabla'),
            'por_usuario' => $groupCount('actor_email'),
            'por_persona' => $groupCount('actor_nombre'),
        ];
    }

    private function baseQuery(array $filters): Builder
    {
        $query = DB::table('auditoria.aud_cambios as a')
            ->leftJoin('seguridad.usuarios as au', 'au.id', '=', 'a.actor_usuario_id')
            ->leftJoin('core.personas as p', 'p.id', '=', 'au.persona_id')
            ->leftJoin('seguridad.roles as ar', 'ar.id', '=', 'a.actor_rol_id')
            ->selectRaw("
                a.id,
                a.gimnasio_id,
                a.sede_id,
                a.actor_usuario_id,
                a.actor_rol_id,
                a.actor_persona_id,
                a.operacion,
                a.esquema,
                a.tabla,
                a.modulo,
                a.accion,
                a.registro_id,
                a.datos_antes,
                a.datos_despues,
                a.campos_cambiados,
                a.request_id,
                a.ip,
                a.user_agent,
                a.created_at,
                a.registro_pk,
                a.tipo_dispositivo,
                a.sistema_operativo,
                a.navegador,
                a.equipo_nombre,
                a.equipo_usuario,
                au.email as actor_email,
                ar.nombre as actor_rol_nombre,
                CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, '')) as actor_nombre
            ")
            ->orderByDesc('a.created_at');

        if (!empty($filters['actor_usuario_id'])) {
            $query->where('a.actor_usuario_id', (int) $filters['actor_usuario_id']);
        }

        if (!empty($filters['actor_persona_id'])) {
            $query->where('a.actor_persona_id', (int) $filters['actor_persona_id']);
        }

        if (!empty($filters['actor_rol_id'])) {
            $query->where('a.actor_rol_id', (int) $filters['actor_rol_id']);
        }

        if (!empty($filters['modulo'])) {
            $query->where('a.modulo', $filters['modulo']);
        }

        if (!empty($filters['tabla'])) {
            $query->where('a.tabla', $filters['tabla']);
        }

        if (!empty($filters['operacion'])) {
            $query->where('a.operacion', $filters['operacion']);
        }

        if (!empty($filters['request_id'])) {
            $query->where('a.request_id', $filters['request_id']);
        }

        if (!empty($filters['fecha_desde'])) {
            $query->where('a.created_at', '>=', $filters['fecha_desde'] . ' 00:00:00');
        }

        if (!empty($filters['fecha_hasta'])) {
            $query->where('a.created_at', '<=', $filters['fecha_hasta'] . ' 23:59:59');
        }

        return $query;
    }

    private function mapRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'gimnasio_id' => $row->gimnasio_id ? (int) $row->gimnasio_id : null,
            'sede_id' => $row->sede_id ? (int) $row->sede_id : null,
            'actor_usuario_id' => $row->actor_usuario_id ? (int) $row->actor_usuario_id : null,
            'actor_rol_id' => $row->actor_rol_id ? (int) $row->actor_rol_id : null,
            'actor_persona_id' => $row->actor_persona_id ? (int) $row->actor_persona_id : null,
            'actor_email' => $row->actor_email,
            'actor_nombre' => trim((string) $row->actor_nombre),
            'actor_rol_nombre' => $row->actor_rol_nombre,
            'operacion' => $row->operacion,
            'esquema' => $row->esquema,
            'tabla' => $row->tabla,
            'modulo' => $row->modulo,
            'accion' => $row->accion,
            'registro_id' => $row->registro_id ? (int) $row->registro_id : null,
            'datos_antes' => $row->datos_antes,
            'datos_despues' => $row->datos_despues,
            'campos_cambiados' => $row->campos_cambiados,
            'request_id' => $row->request_id,
            'ip' => $row->ip,
            'user_agent' => $row->user_agent,
            'created_at' => $row->created_at,
            'registro_pk' => $row->registro_pk,
            'tipo_dispositivo' => $row->tipo_dispositivo,
            'sistema_operativo' => $row->sistema_operativo,
            'navegador' => $row->navegador,
            'equipo_nombre' => $row->equipo_nombre,
            'equipo_usuario' => $row->equipo_usuario,
        ];
    }
}
