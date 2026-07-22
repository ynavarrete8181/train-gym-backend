<?php

namespace App\Queries\Staff;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class StaffQuery
{
    public function perfiles(array $filters = []): array
    {
        $query = DB::table('staff.perfiles as sp')
            ->join('core.personas as p', 'p.id', '=', 'sp.persona_id')
            ->leftJoin('seguridad.usuarios as u', 'u.id', '=', 'sp.usuario_id')
            ->selectRaw("
                sp.*,
                COALESCE(sp.persona_cedula, p.numero_identificacion) as cedula,
                sp.usuario_cedula,
                CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, '')) as nombre_completo,
                u.email as usuario_email
            ")
            ->orderBy('p.nombres')
            ->orderBy('p.apellidos');

        if (!empty($filters['tipo_staff'])) {
            $query->where('sp.tipo_staff', $filters['tipo_staff']);
        }

        if (!empty($filters['estado'])) {
            $query->where('sp.estado', $filters['estado']);
        }

        if (!empty($filters['buscar'])) {
            $buscar = '%' . trim((string) $filters['buscar']) . '%';
            $query->where(function ($q) use ($buscar) {
                $q->whereRaw("COALESCE(sp.persona_cedula, p.numero_identificacion, '') ILIKE ?", [$buscar])
                    ->orWhereRaw("COALESCE(sp.usuario_cedula, '') ILIKE ?", [$buscar])
                    ->orWhereRaw("COALESCE(p.nombres, '') ILIKE ?", [$buscar])
                    ->orWhereRaw("COALESCE(p.apellidos, '') ILIKE ?", [$buscar])
                    ->orWhereRaw("COALESCE(u.email, '') ILIKE ?", [$buscar]);
            });
        }

        return $query->get()->map(fn ($row) => $this->mapPerfil($row))->all();
    }

    public function turnos(array $filters = []): array
    {
        $query = DB::table('staff.turnos_recurrentes as tr')
            ->join('staff.perfiles as sp', 'sp.id', '=', 'tr.coach_id')
            ->join('core.personas as p', 'p.id', '=', 'sp.persona_id')
            ->join('core.sedes as s', 's.id', '=', 'tr.sede_id')
            ->selectRaw("
                tr.*,
                CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, '')) as coach_nombre,
                s.nombre as sede_nombre
            ")
            ->orderBy('tr.dia_semana')
            ->orderBy('tr.hora_inicio');

        if (!empty($filters['coach_id'])) {
            $query->where('tr.coach_id', (int) $filters['coach_id']);
        }

        if (!empty($filters['sede_id'])) {
            $query->where('tr.sede_id', (int) $filters['sede_id']);
        }

        if (!empty($filters['dia_semana'])) {
            $query->where('tr.dia_semana', (int) $filters['dia_semana']);
        }

        return $query->get()->map(fn ($row) => [
            'id' => (int) $row->id,
            'coach_id' => (int) $row->coach_id,
            'coach_nombre' => trim((string) $row->coach_nombre),
            'sede_id' => (int) $row->sede_id,
            'sede_nombre' => $row->sede_nombre,
            'dia_semana' => (int) $row->dia_semana,
            'hora_inicio' => $row->hora_inicio,
            'hora_fin' => $row->hora_fin,
            'capacidad_atencion' => (int) $row->capacidad_atencion,
            'activo' => (bool) $row->activo,
        ])->all();
    }

    public function perfil(int $id): ?array
    {
        $row = DB::table('staff.perfiles as sp')
            ->join('core.personas as p', 'p.id', '=', 'sp.persona_id')
            ->leftJoin('seguridad.usuarios as u', 'u.id', '=', 'sp.usuario_id')
            ->selectRaw("
                sp.*,
                COALESCE(sp.persona_cedula, p.numero_identificacion) as cedula,
                sp.usuario_cedula,
                CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, '')) as nombre_completo,
                u.email as usuario_email
            ")
            ->where('sp.id', $id)
            ->first();

        return $row ? $this->mapPerfil($row) : null;
    }

    public function clientesAsignados(array $filters = []): array
    {
        $query = DB::table('staff.cliente_asignaciones as ca')
            ->join('staff.perfiles as sp', 'sp.id', '=', 'ca.coach_id')
            ->join('core.personas as coach_persona', 'coach_persona.id', '=', 'sp.persona_id')
            ->join('core.personas as cliente', 'cliente.id', '=', 'ca.persona_id')
            ->leftJoin('socios.socios as socio', 'socio.id', '=', 'ca.socio_id')
            ->join('core.sedes as sede', 'sede.id', '=', 'ca.sede_id')
            ->leftJoin('staff.turnos_recurrentes as turno', 'turno.id', '=', 'ca.turno_recurrente_id')
            ->selectRaw("
                ca.*,
                CONCAT(COALESCE(coach_persona.nombres, ''), ' ', COALESCE(coach_persona.apellidos, '')) as coach_nombre,
                COALESCE(ca.persona_cedula, cliente.numero_identificacion) as cliente_cedula,
                CONCAT(COALESCE(cliente.nombres, ''), ' ', COALESCE(cliente.apellidos, '')) as cliente_nombre,
                socio.codigo_socio,
                sede.nombre as sede_nombre,
                turno.dia_semana,
                turno.hora_inicio,
                turno.hora_fin
            ")
            ->orderBy('coach_persona.nombres')
            ->orderBy('cliente.nombres');

        if (!empty($filters['coach_id'])) {
            $query->where('ca.coach_id', (int) $filters['coach_id']);
        }

        if (!empty($filters['usuario_id'])) {
            $query->where('sp.usuario_id', (int) $filters['usuario_id']);
        }

        if (!empty($filters['sede_id'])) {
            $query->where('ca.sede_id', (int) $filters['sede_id']);
        }

        if (!empty($filters['estado'])) {
            $query->where('ca.estado', $filters['estado']);
        }

        if (!empty($filters['buscar'])) {
            $buscar = '%' . trim((string) $filters['buscar']) . '%';
            $query->where(function ($q) use ($buscar) {
                $q->whereRaw("COALESCE(ca.persona_cedula, cliente.numero_identificacion, '') ILIKE ?", [$buscar])
                    ->orWhereRaw("COALESCE(cliente.nombres, '') ILIKE ?", [$buscar])
                    ->orWhereRaw("COALESCE(cliente.apellidos, '') ILIKE ?", [$buscar])
                    ->orWhereRaw("COALESCE(socio.codigo_socio, '') ILIKE ?", [$buscar])
                    ->orWhereRaw("COALESCE(coach_persona.nombres, '') ILIKE ?", [$buscar])
                    ->orWhereRaw("COALESCE(coach_persona.apellidos, '') ILIKE ?", [$buscar]);
            });
        }

        return $query->get()->map(fn ($row) => [
            'id' => (int) $row->id,
            'coach_id' => (int) $row->coach_id,
            'coach_nombre' => trim((string) $row->coach_nombre),
            'persona_id' => (int) $row->persona_id,
            'cliente_nombre' => trim((string) $row->cliente_nombre),
            'cliente_cedula' => $row->cliente_cedula,
            'codigo_socio' => $row->codigo_socio,
            'socio_id' => $row->socio_id ? (int) $row->socio_id : null,
            'sede_id' => (int) $row->sede_id,
            'sede_nombre' => $row->sede_nombre,
            'turno_recurrente_id' => $row->turno_recurrente_id ? (int) $row->turno_recurrente_id : null,
            'dia_semana' => $row->dia_semana ? (int) $row->dia_semana : null,
            'hora_inicio' => $row->hora_inicio,
            'hora_fin' => $row->hora_fin,
            'tipo_asignacion' => $row->tipo_asignacion,
            'fecha_inicio' => $row->fecha_inicio,
            'fecha_fin' => $row->fecha_fin,
            'estado' => $row->estado,
            'objetivo' => $row->objetivo,
            'observaciones' => $row->observaciones,
        ])->all();
    }

    public function seguimientoClientes(array $filters = []): array
    {
        return collect($this->clientesAsignados($filters))
            ->map(fn (array $asignacion) => $this->enriquecerSeguimiento($asignacion))
            ->all();
    }

    private function enriquecerSeguimiento(array $asignacion): array
    {
        $personaId = (int) $asignacion['persona_id'];
        $hoy = now()->toDateString();

        $membresia = DB::table('socios.socio_membresias as sm')
            ->join('socios.socios as s', 's.id', '=', 'sm.socio_id')
            ->join('socios.membresias as m', 'm.id', '=', 'sm.membresia_id')
            ->where('s.persona_id', $personaId)
            ->whereDate('sm.fecha_inicio', '<=', $hoy)
            ->whereDate('sm.fecha_fin', '>=', $hoy)
            ->orderByDesc('sm.fecha_fin')
            ->select('sm.id', 'sm.fecha_fin', 'm.nombre as nombre')
            ->first();

        $plan = DB::table('entrenamiento.plan_asignaciones as pa')
            ->join('entrenamiento.planes as p', 'p.id', '=', 'pa.plan_id')
            ->where('pa.persona_id', $personaId)
            ->where('pa.estado', 'ACTIVO')
            ->where(function ($query) use ($hoy) {
                $query->whereNull('pa.fecha_inicio')
                    ->orWhereDate('pa.fecha_inicio', '<=', $hoy);
            })
            ->where(function ($query) use ($hoy) {
                $query->whereNull('pa.fecha_fin')
                    ->orWhereDate('pa.fecha_fin', '>=', $hoy);
            })
            ->orderByDesc('pa.fecha_inicio')
            ->orderByDesc('pa.id')
            ->select('pa.id as asignacion_plan_id', 'p.id as plan_id', 'p.nombre', 'p.objetivo', 'p.tipo', 'pa.fecha_inicio', 'pa.fecha_fin')
            ->first();

        $ultimaAsistencia = DB::table('asistencia.registros')
            ->where('persona_id', $personaId)
            ->where('estado', 'PERMITIDO')
            ->orderByDesc('fecha_hora')
            ->value('fecha_hora');

        $asistencias30 = DB::table('asistencia.registros')
            ->where('persona_id', $personaId)
            ->where('estado', 'PERMITIDO')
            ->where('fecha_hora', '>=', now()->subDays(30))
            ->count();

        $evaluacion = DB::table('entrenamiento.evaluaciones')
            ->where('persona_id', $personaId)
            ->whereNotNull('fecha_proxima_evaluacion')
            ->whereDate('fecha_proxima_evaluacion', '>=', $hoy)
            ->orderBy('fecha_proxima_evaluacion')
            ->select('id', 'tipo_evaluacion', 'fecha_evaluacion', 'fecha_proxima_evaluacion')
            ->first();

        $estadoSeguimiento = 'ACTIVO';
        if (!$membresia || ($membresia->fecha_fin && $membresia->fecha_fin < $hoy)) {
            $estadoSeguimiento = 'VENCIDO';
        } elseif (!$plan) {
            $estadoSeguimiento = 'SIN_PLAN';
        } elseif (!$ultimaAsistencia || Carbon::parse($ultimaAsistencia)->lt(now()->subDays(7))) {
            $estadoSeguimiento = 'AUSENTE';
        }

        return [
            ...$asignacion,
            'estado_seguimiento' => $estadoSeguimiento,
            'membresia_actual' => $membresia ? [
                'id' => (int) $membresia->id,
                'nombre' => $membresia->nombre,
                'fecha_fin' => $membresia->fecha_fin,
            ] : null,
            'plan_actual' => $plan ? [
                'asignacion_id' => (int) $plan->asignacion_plan_id,
                'id' => (int) $plan->plan_id,
                'nombre' => $plan->nombre,
                'objetivo' => $plan->objetivo,
                'tipo' => $plan->tipo,
                'fecha_inicio' => $plan->fecha_inicio,
                'fecha_fin' => $plan->fecha_fin,
            ] : null,
            'ultima_asistencia' => $ultimaAsistencia,
            'asistencias_30_dias' => (int) $asistencias30,
            'proxima_evaluacion' => $evaluacion ? [
                'id' => (int) $evaluacion->id,
                'tipo_evaluacion' => $evaluacion->tipo_evaluacion,
                'fecha_evaluacion' => $evaluacion->fecha_evaluacion,
                'fecha_proxima_evaluacion' => $evaluacion->fecha_proxima_evaluacion,
            ] : null,
        ];
    }

    private function mapPerfil(object $row): array
    {
        $sedes = DB::table('staff.coach_sedes as cs')
            ->join('core.sedes as s', 's.id', '=', 'cs.sede_id')
            ->where('cs.coach_id', $row->id)
            ->where('cs.activo', true)
            ->orderBy('s.nombre')
            ->get(['s.id', 's.nombre'])
            ->map(fn ($sede) => ['id' => (int) $sede->id, 'nombre' => $sede->nombre])
            ->all();

        return [
            'id' => (int) $row->id,
            'persona_id' => (int) $row->persona_id,
            'usuario_id' => $row->usuario_id ? (int) $row->usuario_id : null,
            'cedula' => $row->cedula,
            'usuario_cedula' => $row->usuario_cedula ?? null,
            'nombre_completo' => trim((string) $row->nombre_completo),
            'usuario_email' => $row->usuario_email,
            'tipo_staff' => $row->tipo_staff,
            'especialidad' => $row->especialidad,
            'estado' => $row->estado,
            'fecha_inicio' => $row->fecha_inicio,
            'fecha_fin' => $row->fecha_fin,
            'observaciones' => $row->observaciones,
            'sedes' => $sedes,
            'sedes_ids' => collect($sedes)->pluck('id')->values()->all(),
        ];
    }
}
