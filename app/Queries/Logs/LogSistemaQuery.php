<?php

namespace App\Queries\Logs;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class LogSistemaQuery
{
    public function eventos(array $filters = []): array
    {
        $limit = max(1, min((int) ($filters['limit'] ?? 80), 300));

        return $this->eventosQuery($filters)
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'request_id' => $row->request_id,
                'nivel' => $row->nivel,
                'canal' => $row->canal,
                'modulo' => $row->modulo,
                'accion' => $row->accion,
                'mensaje' => $row->mensaje,
                'usuario_id' => $row->usuario_id ? (int) $row->usuario_id : null,
                'persona_id' => $row->persona_id ? (int) $row->persona_id : null,
                'usuario_cedula' => $row->usuario_cedula,
                'persona_cedula' => $row->persona_cedula,
                'sede_id' => $row->sede_id ? (int) $row->sede_id : null,
                'usuario_email' => $row->usuario_email,
                'persona_nombre' => trim((string) $row->persona_nombre),
                'sede_nombre' => $row->sede_nombre,
                'ip' => $row->ip,
                'user_agent' => $row->user_agent,
                'contexto' => $row->contexto,
                'created_at' => $row->created_at,
            ])
            ->all();
    }

    public function resumen(array $filters = []): array
    {
        $rows = $this->eventosQuery($filters)->get();

        $groupCount = function (string $field) use ($rows): array {
            return collect($rows)
                ->groupBy(fn ($row) => $row->{$field} ?: 'Sin dato')
                ->map(fn ($items, $key) => ['clave' => $key, 'total' => $items->count()])
                ->sortByDesc('total')
                ->values()
                ->all();
        };

        return [
            'total' => $rows->count(),
            'por_nivel' => $groupCount('nivel'),
            'por_canal' => $groupCount('canal'),
            'por_modulo' => $groupCount('modulo'),
            'por_accion' => $groupCount('accion'),
        ];
    }

    public function excepciones(array $filters = []): array
    {
        $limit = max(1, min((int) ($filters['limit'] ?? 50), 200));

        $query = DB::table('logs.excepciones as e')
            ->leftJoin('logs.eventos as le', 'le.id', '=', 'e.log_evento_id')
            ->selectRaw('
                e.id,
                e.log_evento_id,
                le.request_id,
                le.modulo,
                le.accion,
                e.exception_class,
                e.exception_message,
                e.archivo,
                e.linea,
                e.stack_trace,
                e.created_at
            ')
            ->orderByDesc('e.created_at');

        if (!empty($filters['request_id'])) {
            $query->where('le.request_id', $filters['request_id']);
        }

        if (!empty($filters['modulo'])) {
            $query->where('le.modulo', $filters['modulo']);
        }

        return $query
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'log_evento_id' => $row->log_evento_id ? (int) $row->log_evento_id : null,
                'request_id' => $row->request_id,
                'modulo' => $row->modulo,
                'accion' => $row->accion,
                'exception_class' => $row->exception_class,
                'exception_message' => $row->exception_message,
                'archivo' => $row->archivo,
                'linea' => $row->linea ? (int) $row->linea : null,
                'stack_trace' => $row->stack_trace,
                'created_at' => $row->created_at,
            ])
            ->all();
    }

    public function integraciones(array $filters = []): array
    {
        $limit = max(1, min((int) ($filters['limit'] ?? 50), 200));
        $query = DB::table('logs.integraciones')->orderByDesc('created_at');

        foreach (['request_id', 'proveedor', 'tipo', 'direccion'] as $field) {
            if (!empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        return $query
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'request_id' => $row->request_id,
                'proveedor' => $row->proveedor,
                'tipo' => $row->tipo,
                'direccion' => $row->direccion,
                'endpoint' => $row->endpoint,
                'metodo' => $row->metodo,
                'status_code' => $row->status_code ? (int) $row->status_code : null,
                'request_payload' => $row->request_payload,
                'response_payload' => $row->response_payload,
                'error' => $row->error,
                'duracion_ms' => $row->duracion_ms ? (int) $row->duracion_ms : null,
                'created_at' => $row->created_at,
            ])
            ->all();
    }

    private function eventosQuery(array $filters): Builder
    {
        $query = DB::table('logs.eventos as le')
            ->leftJoin('seguridad.usuarios as u', 'u.id', '=', 'le.usuario_id')
            ->leftJoin('core.personas as p', 'p.id', '=', 'le.persona_id')
            ->leftJoin('core.sedes as s', 's.id', '=', 'le.sede_id')
            ->selectRaw("
                le.*,
                COALESCE(le.usuario_cedula, u.cedula) as usuario_cedula,
                COALESCE(le.persona_cedula, p.numero_identificacion) as persona_cedula,
                u.email as usuario_email,
                CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, '')) as persona_nombre,
                s.nombre as sede_nombre
            ")
            ->orderByDesc('le.created_at');

        foreach (['nivel', 'canal', 'modulo', 'accion', 'request_id'] as $field) {
            if (!empty($filters[$field])) {
                $query->where("le.$field", $filters[$field]);
            }
        }

        if (!empty($filters['sede_id'])) {
            $query->where('le.sede_id', (int) $filters['sede_id']);
        }

        if (!empty($filters['usuario_id'])) {
            $query->where('le.usuario_id', (int) $filters['usuario_id']);
        }

        if (!empty($filters['cedula'])) {
            $cedula = '%' . trim((string) $filters['cedula']) . '%';
            $query->where(function ($q) use ($cedula) {
                $q->whereRaw("COALESCE(le.usuario_cedula, u.cedula, '') ILIKE ?", [$cedula])
                    ->orWhereRaw("COALESCE(le.persona_cedula, p.numero_identificacion, '') ILIKE ?", [$cedula]);
            });
        }

        if (!empty($filters['buscar'])) {
            $buscar = '%' . trim((string) $filters['buscar']) . '%';
            $query->where(function ($q) use ($buscar) {
                $q->whereRaw("COALESCE(le.usuario_cedula, u.cedula, '') ILIKE ?", [$buscar])
                    ->orWhereRaw("COALESCE(le.persona_cedula, p.numero_identificacion, '') ILIKE ?", [$buscar])
                    ->orWhereRaw("COALESCE(u.email, '') ILIKE ?", [$buscar])
                    ->orWhereRaw("CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, '')) ILIKE ?", [$buscar])
                    ->orWhereRaw("COALESCE(le.mensaje, '') ILIKE ?", [$buscar])
                    ->orWhereRaw("COALESCE(le.request_id, '') ILIKE ?", [$buscar]);
            });
        }

        if (!empty($filters['fecha_desde'])) {
            $query->where('le.created_at', '>=', $filters['fecha_desde'] . ' 00:00:00');
        }

        if (!empty($filters['fecha_hasta'])) {
            $query->where('le.created_at', '<=', $filters['fecha_hasta'] . ' 23:59:59');
        }

        return $query;
    }
}
