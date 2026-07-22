<?php

namespace App\Queries\Reportes;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ReportePremiumQuery
{
    public function dashboard(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $limit = $filters['limit'];

        $asistenciaPorSede = $this->asistenciaPorSede($filters, $limit);
        $asistenciaPorMembresia = $this->asistenciaPorMembresia($filters, $limit);
        $clientesPorCoach = $this->clientesPorCoach($filters, $limit);
        $ingresosPorMembresia = $this->ingresosPorMembresia($filters, $limit);
        $reservasUso = $this->reservasUso($filters, $limit);
        $auditoria = $this->auditoria($filters, $limit);
        $logs = $this->logs($filters, $limit);

        return [
            'filtros' => [
                'fecha_desde' => $filters['fecha_desde'],
                'fecha_hasta' => $filters['fecha_hasta'],
                'sedes' => $this->sedes(),
            ],
            'resumen' => [
                'asistencias' => array_sum(array_column($asistenciaPorSede, 'total')),
                'clientes_con_coach' => array_sum(array_column($clientesPorCoach, 'clientes')),
                'ingresos_membresias' => round(array_sum(array_column($ingresosPorMembresia, 'ingresos')), 2),
                'reservas_total' => array_sum(array_column($reservasUso, 'total')),
                'reservas_usadas' => array_sum(array_column($reservasUso, 'asistieron')),
                'reservas_no_usadas' => array_sum(array_column($reservasUso, 'no_asistieron')),
                'auditorias' => array_sum(array_column($auditoria['por_modulo'], 'total')),
                'errores_tecnicos' => array_sum(array_column($logs['por_error'], 'total')),
            ],
            'asistencia_por_sede' => $asistenciaPorSede,
            'asistencia_por_membresia' => $asistenciaPorMembresia,
            'clientes_por_coach' => $clientesPorCoach,
            'ingresos_por_membresia' => $ingresosPorMembresia,
            'reservas_uso' => $reservasUso,
            'auditoria' => $auditoria,
            'logs' => $logs,
        ];
    }

    private function normalizeFilters(array $filters): array
    {
        $hasta = !empty($filters['fecha_hasta'])
            ? Carbon::parse($filters['fecha_hasta'])->toDateString()
            : now()->toDateString();
        $desde = !empty($filters['fecha_desde'])
            ? Carbon::parse($filters['fecha_desde'])->toDateString()
            : Carbon::parse($hasta)->subDays(30)->toDateString();

        return [
            'fecha_desde' => $desde,
            'fecha_hasta' => $hasta,
            'sede_id' => !empty($filters['sede_id']) ? (int) $filters['sede_id'] : null,
            'buscar' => trim((string) ($filters['buscar'] ?? '')),
            'cedula' => trim((string) ($filters['cedula'] ?? '')),
            'modulo' => trim((string) ($filters['modulo'] ?? '')),
            'accion' => trim((string) ($filters['accion'] ?? '')),
            'nivel' => trim((string) ($filters['nivel'] ?? '')),
            'actor_rol_id' => !empty($filters['actor_rol_id']) ? (int) $filters['actor_rol_id'] : null,
            'limit' => max(3, min((int) ($filters['limit'] ?? 8), 25)),
        ];
    }

    private function sedes(): array
    {
        return DB::table('core.sedes')
            ->select('id', 'nombre')
            ->orderBy('nombre')
            ->get()
            ->map(fn ($row) => ['id' => (int) $row->id, 'nombre' => $row->nombre])
            ->all();
    }

    private function asistenciaBase(array $filters): Builder
    {
        $query = DB::table('asistencia.registros as ar')
            ->join('core.personas as p', 'p.id', '=', 'ar.persona_id')
            ->join('core.sedes as s', 's.id', '=', 'ar.sede_id')
            ->leftJoin('socios.socio_membresias as sm', 'sm.id', '=', 'ar.socio_membresia_id')
            ->leftJoin('socios.membresias as m', 'm.id', '=', 'sm.membresia_id')
            ->whereBetween(DB::raw('DATE(ar.fecha_hora)'), [$filters['fecha_desde'], $filters['fecha_hasta']]);

        if ($filters['sede_id']) {
            $query->where('ar.sede_id', $filters['sede_id']);
        }

        $this->applyPeopleSearch($query, $filters, [
            "COALESCE(ar.persona_cedula, p.numero_identificacion, '')",
            "CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, ''))",
            "COALESCE(s.nombre, '')",
            "COALESCE(m.nombre, '')",
        ]);

        return $query;
    }

    private function asistenciaPorSede(array $filters, int $limit): array
    {
        return $this->asistenciaBase($filters)
            ->selectRaw("
                s.id as sede_id,
                s.nombre as sede_nombre,
                COUNT(*) as total,
                COUNT(DISTINCT ar.persona_id) as clientes,
                COUNT(*) FILTER (WHERE ar.estado = 'PERMITIDO') as permitidos,
                COUNT(*) FILTER (WHERE ar.reserva_id IS NOT NULL) as con_reserva
            ")
            ->groupBy('s.id', 's.nombre')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'sede_id' => (int) $row->sede_id,
                'sede_nombre' => $row->sede_nombre,
                'total' => (int) $row->total,
                'clientes' => (int) $row->clientes,
                'permitidos' => (int) $row->permitidos,
                'con_reserva' => (int) $row->con_reserva,
            ])
            ->all();
    }

    private function asistenciaPorMembresia(array $filters, int $limit): array
    {
        return $this->asistenciaBase($filters)
            ->selectRaw("
                COALESCE(m.nombre, 'Sin membresía') as membresia_nombre,
                COUNT(*) as total,
                COUNT(DISTINCT ar.persona_id) as clientes,
                COUNT(*) FILTER (WHERE ar.reserva_id IS NOT NULL) as con_reserva
            ")
            ->groupByRaw("COALESCE(m.nombre, 'Sin membresía')")
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'membresia_nombre' => $row->membresia_nombre,
                'total' => (int) $row->total,
                'clientes' => (int) $row->clientes,
                'con_reserva' => (int) $row->con_reserva,
            ])
            ->all();
    }

    private function clientesPorCoach(array $filters, int $limit): array
    {
        $query = DB::table('staff.cliente_asignaciones as ca')
            ->join('staff.perfiles as sp', 'sp.id', '=', 'ca.coach_id')
            ->join('core.personas as coach', 'coach.id', '=', 'sp.persona_id')
            ->join('core.personas as cliente', 'cliente.id', '=', 'ca.persona_id')
            ->leftJoin('core.sedes as s', 's.id', '=', 'ca.sede_id')
            ->leftJoin('staff.turnos_recurrentes as tr', 'tr.id', '=', 'ca.turno_recurrente_id')
            ->where('ca.estado', 'ACTIVO')
            ->where(function ($q) use ($filters) {
                $q->whereNull('ca.fecha_inicio')->orWhere('ca.fecha_inicio', '<=', $filters['fecha_hasta']);
            })
            ->where(function ($q) use ($filters) {
                $q->whereNull('ca.fecha_fin')->orWhere('ca.fecha_fin', '>=', $filters['fecha_desde']);
            });

        if ($filters['sede_id']) {
            $query->where('ca.sede_id', $filters['sede_id']);
        }

        $this->applyPeopleSearch($query, $filters, [
            "COALESCE(ca.persona_cedula, cliente.numero_identificacion, '')",
            "CONCAT(COALESCE(cliente.nombres, ''), ' ', COALESCE(cliente.apellidos, ''))",
            "CONCAT(COALESCE(coach.nombres, ''), ' ', COALESCE(coach.apellidos, ''))",
            "COALESCE(s.nombre, '')",
        ]);

        return $query
            ->selectRaw("
                sp.id as coach_id,
                CONCAT(COALESCE(coach.nombres, ''), ' ', COALESCE(coach.apellidos, '')) as coach_nombre,
                COALESCE(s.nombre, 'Todas las sedes') as sede_nombre,
                COUNT(DISTINCT ca.persona_id) as clientes,
                COUNT(*) FILTER (WHERE ca.tipo_asignacion = 'SEGUIMIENTO_PERSONALIZADO') as personalizados,
                COUNT(*) FILTER (WHERE ca.turno_recurrente_id IS NOT NULL) as con_turno
            ")
            ->groupBy('sp.id', 'coach.nombres', 'coach.apellidos', 's.nombre')
            ->orderByDesc('clientes')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'coach_id' => (int) $row->coach_id,
                'coach_nombre' => trim((string) $row->coach_nombre),
                'sede_nombre' => $row->sede_nombre,
                'clientes' => (int) $row->clientes,
                'personalizados' => (int) $row->personalizados,
                'con_turno' => (int) $row->con_turno,
            ])
            ->all();
    }

    private function ingresosPorMembresia(array $filters, int $limit): array
    {
        $query = DB::table('ventas.venta_detalles as vd')
            ->join('ventas.ventas as v', 'v.id', '=', 'vd.venta_id')
            ->leftJoin('socios.membresias as m', 'm.id', '=', DB::raw('COALESCE(vd.membresia_id, v.membresia_id)'))
            ->leftJoin('core.sedes as s', 's.id', '=', 'v.sede_id')
            ->whereRaw("(COALESCE(vd.tipo_detalle, '') = 'MEMBRESIA' OR vd.membresia_id IS NOT NULL OR v.membresia_id IS NOT NULL)")
            ->whereBetween('v.fecha_consumo', [$filters['fecha_desde'], $filters['fecha_hasta']])
            ->whereNotIn('v.estado_pago', ['BORRADOR', 'ANULADO']);

        if ($filters['sede_id']) {
            $query->where('v.sede_id', $filters['sede_id']);
        }

        $this->applySearch($query, $filters, [
            "COALESCE(v.persona_cedula, '')",
            "COALESCE(m.nombre, '')",
            "COALESCE(s.nombre, '')",
            "COALESCE(v.referencia, '')",
        ]);

        return $query
            ->selectRaw("
                COALESCE(m.nombre, 'Sin membresía') as membresia_nombre,
                COUNT(DISTINCT v.id) as ventas,
                SUM(COALESCE(vd.subtotal, 0)) as ingresos,
                AVG(NULLIF(vd.precio_unitario, 0)) as ticket_promedio
            ")
            ->groupByRaw("COALESCE(m.nombre, 'Sin membresía')")
            ->orderByDesc('ingresos')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'membresia_nombre' => $row->membresia_nombre,
                'ventas' => (int) $row->ventas,
                'ingresos' => round((float) $row->ingresos, 2),
                'ticket_promedio' => round((float) $row->ticket_promedio, 2),
            ])
            ->all();
    }

    private function reservasUso(array $filters, int $limit): array
    {
        $query = DB::table('reservas.reservas as r')
            ->join('core.personas as p', 'p.id', '=', 'r.persona_id')
            ->join('core.sedes as s', 's.id', '=', 'r.sede_id')
            ->leftJoin('train_gimnasio.tipos_servicios as ts', 'ts.id', '=', 'r.servicio_id')
            ->leftJoin('socios.socio_membresias as sm', 'sm.id', '=', 'r.socio_membresia_id')
            ->leftJoin('socios.membresias as m', 'm.id', '=', 'sm.membresia_id')
            ->whereBetween('r.fecha', [$filters['fecha_desde'], $filters['fecha_hasta']]);

        if ($filters['sede_id']) {
            $query->where('r.sede_id', $filters['sede_id']);
        }

        $this->applyPeopleSearch($query, $filters, [
            "COALESCE(r.persona_cedula, p.numero_identificacion, '')",
            "CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, ''))",
            "COALESCE(s.nombre, '')",
            "COALESCE(ts.nombre, '')",
            "COALESCE(m.nombre, '')",
        ]);

        return $query
            ->selectRaw("
                s.nombre as sede_nombre,
                COALESCE(ts.nombre, 'Servicio general') as servicio_nombre,
                COUNT(*) as total,
                COUNT(*) FILTER (WHERE r.estado = 'ASISTIO') as asistieron,
                COUNT(*) FILTER (WHERE r.estado = 'CANCELADA') as canceladas,
                COUNT(*) FILTER (
                    WHERE r.estado = 'RESERVADA'
                      AND (r.fecha < CURRENT_DATE OR (r.fecha = CURRENT_DATE AND r.hora_fin < CURRENT_TIME))
                ) as no_asistieron
            ")
            ->groupBy('s.nombre', 'ts.nombre')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'sede_nombre' => $row->sede_nombre,
                'servicio_nombre' => $row->servicio_nombre,
                'total' => (int) $row->total,
                'asistieron' => (int) $row->asistieron,
                'canceladas' => (int) $row->canceladas,
                'no_asistieron' => (int) $row->no_asistieron,
            ])
            ->all();
    }

    private function auditoria(array $filters, int $limit): array
    {
        return [
            'por_usuario' => $this->auditBase($filters)
                ->selectRaw("
                    COALESCE(au.email, CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, '')), 'Sistema') as clave,
                    COALESCE(a.actor_usuario_cedula, au.cedula, p.numero_identificacion) as cedula,
                    COUNT(*) as total
                ")
                ->groupByRaw("COALESCE(au.email, CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, '')), 'Sistema'), COALESCE(a.actor_usuario_cedula, au.cedula, p.numero_identificacion)")
                ->orderByDesc('total')
                ->limit($limit)
                ->get()
                ->map(fn ($row) => ['clave' => trim((string) $row->clave), 'cedula' => $row->cedula, 'total' => (int) $row->total])
                ->all(),
            'por_rol' => $this->groupAudit($filters, "COALESCE(ar.nombre, 'Sin rol')", $limit),
            'por_accion' => $this->groupAudit($filters, "COALESCE(a.accion, a.operacion, 'Sin acción')", $limit),
            'por_modulo' => $this->groupAudit($filters, "COALESCE(a.modulo, a.esquema, 'Sin módulo')", $limit),
            'por_fecha' => $this->groupAudit($filters, "DATE(a.created_at)::TEXT", $limit, 'clave'),
        ];
    }

    private function logs(array $filters, int $limit): array
    {
        return [
            'por_severidad' => $this->groupLogs($filters, "COALESCE(le.nivel, 'Sin severidad')", $limit),
            'por_ruta' => $this->groupLogs($filters, "COALESCE(le.contexto->>'ruta', le.contexto->>'route', le.contexto->>'url', le.contexto->>'path', 'Sin ruta')", $limit),
            'por_usuario' => $this->logsBase($filters)
                ->selectRaw("
                    COALESCE(u.email, CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, '')), 'Sistema') as clave,
                    COALESCE(le.usuario_cedula, u.cedula, le.persona_cedula, p.numero_identificacion) as cedula,
                    COUNT(*) as total
                ")
                ->groupByRaw("COALESCE(u.email, CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, '')), 'Sistema'), COALESCE(le.usuario_cedula, u.cedula, le.persona_cedula, p.numero_identificacion)")
                ->orderByDesc('total')
                ->limit($limit)
                ->get()
                ->map(fn ($row) => ['clave' => trim((string) $row->clave), 'cedula' => $row->cedula, 'total' => (int) $row->total])
                ->all(),
            'por_error' => $this->erroresTecnicos($filters, $limit),
        ];
    }

    private function auditBase(array $filters): Builder
    {
        $query = DB::table('auditoria.aud_cambios as a')
            ->leftJoin('seguridad.usuarios as au', 'au.id', '=', 'a.actor_usuario_id')
            ->leftJoin('core.personas as p', 'p.id', '=', 'au.persona_id')
            ->leftJoin('seguridad.roles as ar', 'ar.id', '=', 'a.actor_rol_id')
            ->whereBetween(DB::raw('DATE(a.created_at)'), [$filters['fecha_desde'], $filters['fecha_hasta']]);

        if ($filters['sede_id']) {
            $query->where('a.sede_id', $filters['sede_id']);
        }
        if ($filters['actor_rol_id']) {
            $query->where('a.actor_rol_id', $filters['actor_rol_id']);
        }
        if ($filters['modulo'] !== '') {
            $query->where('a.modulo', $filters['modulo']);
        }
        if ($filters['accion'] !== '') {
            $query->where('a.accion', $filters['accion']);
        }

        $this->applyPeopleSearch($query, $filters, [
            "COALESCE(a.actor_usuario_cedula, au.cedula, p.numero_identificacion, '')",
            "COALESCE(a.actor_persona_cedula, '')",
            "COALESCE(au.email, '')",
            "CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, ''))",
            "COALESCE(a.modulo, '')",
            "COALESCE(a.accion, '')",
            "COALESCE(a.operacion, '')",
        ]);

        return $query;
    }

    private function logsBase(array $filters): Builder
    {
        $query = DB::table('logs.eventos as le')
            ->leftJoin('seguridad.usuarios as u', 'u.id', '=', 'le.usuario_id')
            ->leftJoin('core.personas as p', 'p.id', '=', 'le.persona_id')
            ->whereBetween(DB::raw('DATE(le.created_at)'), [$filters['fecha_desde'], $filters['fecha_hasta']]);

        if ($filters['sede_id']) {
            $query->where('le.sede_id', $filters['sede_id']);
        }
        if ($filters['modulo'] !== '') {
            $query->where('le.modulo', $filters['modulo']);
        }
        if ($filters['accion'] !== '') {
            $query->where('le.accion', $filters['accion']);
        }
        if ($filters['nivel'] !== '') {
            $query->where('le.nivel', $filters['nivel']);
        }

        $this->applyPeopleSearch($query, $filters, [
            "COALESCE(le.usuario_cedula, u.cedula, '')",
            "COALESCE(le.persona_cedula, p.numero_identificacion, '')",
            "COALESCE(u.email, '')",
            "CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, ''))",
            "COALESCE(le.modulo, '')",
            "COALESCE(le.accion, '')",
            "COALESCE(le.mensaje, '')",
            "COALESCE(le.contexto->>'ruta', le.contexto->>'route', le.contexto->>'url', le.contexto->>'path', '')",
        ]);

        return $query;
    }

    private function groupAudit(array $filters, string $expression, int $limit, string $alias = 'clave'): array
    {
        return $this->auditBase($filters)
            ->selectRaw("{$expression} as {$alias}, COUNT(*) as total")
            ->groupByRaw($expression)
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => ['clave' => (string) $row->{$alias}, 'total' => (int) $row->total])
            ->all();
    }

    private function groupLogs(array $filters, string $expression, int $limit): array
    {
        return $this->logsBase($filters)
            ->selectRaw("{$expression} as clave, COUNT(*) as total")
            ->groupByRaw($expression)
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => ['clave' => (string) $row->clave, 'total' => (int) $row->total])
            ->all();
    }

    private function erroresTecnicos(array $filters, int $limit): array
    {
        return DB::table('logs.excepciones as e')
            ->leftJoin('logs.eventos as le', 'le.id', '=', 'e.log_evento_id')
            ->whereBetween(DB::raw('DATE(e.created_at)'), [$filters['fecha_desde'], $filters['fecha_hasta']])
            ->when($filters['modulo'] !== '', fn ($q) => $q->where('le.modulo', $filters['modulo']))
            ->when($filters['accion'] !== '', fn ($q) => $q->where('le.accion', $filters['accion']))
            ->when($filters['nivel'] !== '', fn ($q) => $q->where('le.nivel', $filters['nivel']))
            ->when($filters['buscar'] !== '', function ($q) use ($filters) {
                $buscar = '%' . $filters['buscar'] . '%';
                $q->where(function ($inner) use ($buscar) {
                    $inner->whereRaw("COALESCE(e.exception_class, '') ILIKE ?", [$buscar])
                        ->orWhereRaw("COALESCE(e.exception_message, '') ILIKE ?", [$buscar])
                        ->orWhereRaw("COALESCE(e.archivo, '') ILIKE ?", [$buscar])
                        ->orWhereRaw("COALESCE(le.mensaje, '') ILIKE ?", [$buscar]);
                });
            })
            ->selectRaw("
                COALESCE(e.exception_class, 'Error técnico') as clave,
                LEFT(COALESCE(e.exception_message, le.mensaje, 'Sin detalle'), 180) as detalle,
                COUNT(*) as total,
                MAX(e.created_at) as ultimo_evento
            ")
            ->groupByRaw("COALESCE(e.exception_class, 'Error técnico'), LEFT(COALESCE(e.exception_message, le.mensaje, 'Sin detalle'), 180)")
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'clave' => $row->clave,
                'detalle' => $row->detalle,
                'total' => (int) $row->total,
                'ultimo_evento' => $row->ultimo_evento,
            ])
            ->all();
    }

    private function applyPeopleSearch(Builder $query, array $filters, array $columns): void
    {
        if ($filters['cedula'] !== '') {
            $cedula = '%' . $filters['cedula'] . '%';
            $query->where(function ($q) use ($cedula, $columns) {
                foreach ($columns as $column) {
                    $q->orWhereRaw("{$column} ILIKE ?", [$cedula]);
                }
            });
        }

        $this->applySearch($query, $filters, $columns);
    }

    private function applySearch(Builder $query, array $filters, array $columns): void
    {
        if ($filters['buscar'] === '') {
            return;
        }

        $buscar = '%' . $filters['buscar'] . '%';
        $query->where(function ($q) use ($buscar, $columns) {
            foreach ($columns as $column) {
                $q->orWhereRaw("{$column} ILIKE ?", [$buscar]);
            }
        });
    }
}
