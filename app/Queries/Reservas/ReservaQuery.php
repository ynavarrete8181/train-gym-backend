<?php

namespace App\Queries\Reservas;

use Illuminate\Support\Facades\DB;

class ReservaQuery
{
    public function membresiasParaReservar(int $personaId, ?string $fecha = null): array
    {
        $fechaReserva = $fecha ?: now()->toDateString();

        return DB::table('socios.socio_membresias as sm')
            ->join('socios.socios as so', 'so.id', '=', 'sm.socio_id')
            ->join('socios.membresias as m', 'm.id', '=', 'sm.membresia_id')
            ->leftJoin('core.sedes as se', 'se.id', '=', 'sm.sede_id')
            ->where('so.persona_id', $personaId)
            ->whereDate('sm.fecha_inicio', '<=', $fechaReserva)
            ->whereDate('sm.fecha_fin', '>=', $fechaReserva)
            ->orderByRaw('CASE WHEN sm.sede_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('se.nombre')
            ->orderBy('m.nombre')
            ->selectRaw("
                sm.id,
                sm.membresia_id,
                sm.sede_id,
                sm.fecha_inicio,
                sm.fecha_fin,
                sm.precio_aplicado,
                m.nombre as membresia_nombre,
                m.duracion_dias,
                se.nombre as sede_nombre
            ")
            ->get()
            ->map(function ($row) {
                $duracionDias = (int) $row->duracion_dias;
                $nombre = (string) $row->membresia_nombre;
                $nombreNormalizado = strtolower($nombre);

                return [
                    'socio_membresia_id' => (int) $row->id,
                    'membresia_id' => (int) $row->membresia_id,
                    'membresia_nombre' => $nombre,
                    'sede_id' => $row->sede_id ? (int) $row->sede_id : null,
                    'sede_nombre' => $row->sede_nombre ?: 'Todas las sedes',
                    'fecha_inicio' => $row->fecha_inicio,
                    'fecha_fin' => $row->fecha_fin,
                    'precio_aplicado' => $row->precio_aplicado !== null ? (float) $row->precio_aplicado : null,
                    'duracion_dias' => $duracionDias,
                    'es_pase_diario' => $duracionDias <= 1 || str_contains($nombreNormalizado, 'pase diario'),
                ];
            })
            ->all();
    }

    public function listar(array $filters = []): array
    {
        $query = DB::table('reservas.reservas as r')
            ->join('core.personas as p', 'p.id', '=', 'r.persona_id')
            ->join('core.sedes as s', 's.id', '=', 'r.sede_id')
            ->leftJoin('train_gimnasio.tipos_servicios as ts', 'ts.id', '=', 'r.servicio_id')
            ->leftJoin('socios.socio_membresias as sm', 'sm.id', '=', 'r.socio_membresia_id')
            ->leftJoin('socios.membresias as m', 'm.id', '=', 'sm.membresia_id')
            ->selectRaw("
                r.*,
                CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, '')) as persona_nombre,
                COALESCE(r.persona_cedula, p.numero_identificacion) as persona_cedula,
                s.nombre as sede_nombre,
                ts.nombre as servicio_nombre,
                m.nombre as membresia_nombre
            ")
            ->orderByDesc('r.fecha')
            ->orderBy('r.hora_inicio');

        foreach (['estado', 'origen'] as $field) {
            if (!empty($filters[$field])) {
                $query->where("r.$field", $filters[$field]);
            }
        }

        foreach (['persona_id', 'sede_id', 'coach_usuario_id'] as $field) {
            if (!empty($filters[$field])) {
                $query->where("r.$field", (int) $filters[$field]);
            }
        }

        if (!empty($filters['membresia_id'])) {
            $query->where('sm.membresia_id', (int) $filters['membresia_id']);
        }

        if (!empty($filters['fecha'])) {
            $query->where('r.fecha', $filters['fecha']);
        }

        if (!empty($filters['cedula'])) {
            $cedula = '%' . trim((string) $filters['cedula']) . '%';
            $query->whereRaw("COALESCE(r.persona_cedula, p.numero_identificacion, '') ILIKE ?", [$cedula]);
        }

        if (!empty($filters['buscar'])) {
            $buscar = '%' . trim((string) $filters['buscar']) . '%';
            $query->where(function ($q) use ($buscar) {
                $q->whereRaw("COALESCE(r.persona_cedula, p.numero_identificacion, '') ILIKE ?", [$buscar])
                    ->orWhereRaw("CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, '')) ILIKE ?", [$buscar])
                    ->orWhereRaw("COALESCE(m.nombre, '') ILIKE ?", [$buscar])
                    ->orWhereRaw("COALESCE(ts.nombre, '') ILIKE ?", [$buscar])
                    ->orWhereRaw("COALESCE(s.nombre, '') ILIKE ?", [$buscar]);
            });
        }

        return $query->limit(200)->get()->map(fn ($row) => [
            'id' => (int) $row->id,
            'persona_id' => (int) $row->persona_id,
            'persona_nombre' => trim((string) $row->persona_nombre),
            'persona_cedula' => $row->persona_cedula,
            'socio_membresia_id' => $row->socio_membresia_id ? (int) $row->socio_membresia_id : null,
            'membresia_nombre' => $row->membresia_nombre,
            'sede_id' => (int) $row->sede_id,
            'sede_nombre' => $row->sede_nombre,
            'cupo_diario_id' => $row->cupo_diario_id ? (int) $row->cupo_diario_id : null,
            'coach_usuario_id' => $row->coach_usuario_id ? (int) $row->coach_usuario_id : null,
            'servicio_id' => $row->servicio_id ? (int) $row->servicio_id : null,
            'servicio_nombre' => $row->servicio_nombre,
            'fecha' => $row->fecha,
            'hora_inicio' => $row->hora_inicio,
            'hora_fin' => $row->hora_fin,
            'estado' => $row->estado,
            'origen' => $row->origen,
        ])->all();
    }

    public function disponibilidad(array $filters = []): array
    {
        $fecha = $filters['fecha'] ?? now()->toDateString();

        $query = DB::table('reservas.cupos_diarios as c')
            ->join('core.sedes as s', 's.id', '=', 'c.sede_id')
            ->join('train_gimnasio.tipos_servicios as ts', 'ts.id', '=', 'c.servicio_id')
            ->leftJoin('reservas.reservas as r', function ($join) {
                $join->on('r.cupo_diario_id', '=', 'c.id');
            })
            ->leftJoin('socios.socio_membresias as sm', 'sm.id', '=', 'r.socio_membresia_id')
            ->leftJoin('socios.membresias as m', 'm.id', '=', 'sm.membresia_id')
            ->where('c.fecha', $fecha)
            ->where('c.estado', 'ABIERTO')
            ->when(!empty($filters['sede_id']), fn ($q) => $q->where('c.sede_id', (int) $filters['sede_id']))
            ->when(!empty($filters['servicio_id']), fn ($q) => $q->where('c.servicio_id', (int) $filters['servicio_id']))
            ->when(!empty($filters['membresia_id']), fn ($q) => $q->where('sm.membresia_id', (int) $filters['membresia_id']))
            ->groupBy('c.id', 's.nombre', 'ts.nombre')
            ->orderBy('c.hora_inicio')
            ->orderBy('ts.nombre')
            ->selectRaw("
                c.*,
                s.nombre as sede_nombre,
                ts.nombre as servicio_nombre,
                COUNT(r.id) FILTER (WHERE r.estado IN ('RESERVADA', 'ASISTIO')) as ocupados,
                COUNT(r.id) FILTER (WHERE r.estado = 'RESERVADA') as reservados,
                COUNT(r.id) FILTER (WHERE r.estado = 'ASISTIO') as asistieron,
                COUNT(r.id) FILTER (WHERE r.estado = 'CANCELADA') as cancelados,
                STRING_AGG(DISTINCT m.nombre, ', ' ORDER BY m.nombre) FILTER (WHERE r.estado IN ('RESERVADA', 'ASISTIO') AND m.nombre IS NOT NULL) as membresias_uso
            ");

        return $query->get()->map(function ($row) {
            $ocupados = (int) $row->ocupados;
            $capacidad = (int) $row->capacidad;
            $terminado = now()->toDateString() > $row->fecha
                || (now()->toDateString() === $row->fecha && now()->format('H:i:s') > (string) $row->hora_fin);
            $reservados = (int) $row->reservados;

            return [
                'id' => (int) $row->id,
                'sede_id' => (int) $row->sede_id,
                'sede_nombre' => $row->sede_nombre,
                'servicio_id' => (int) $row->servicio_id,
                'servicio_nombre' => $row->servicio_nombre,
                'fecha' => $row->fecha,
                'hora_inicio' => substr((string) $row->hora_inicio, 0, 5),
                'hora_fin' => substr((string) $row->hora_fin, 0, 5),
                'capacidad' => $capacidad,
                'ocupados' => $ocupados,
                'reservados' => $reservados,
                'asistieron' => (int) $row->asistieron,
                'no_asistieron' => $terminado ? $reservados : 0,
                'cancelados' => (int) $row->cancelados,
                'disponibles' => max($capacidad - $ocupados, 0),
                'membresias_uso' => $row->membresias_uso ?: 'Sin reservas',
                'estado' => $capacidad > $ocupados ? 'DISPONIBLE' : 'LLENO',
            ];
        })->all();
    }

    public function reporteDiario(array $filters = []): array
    {
        $fecha = $filters['fecha'] ?? now()->toDateString();
        $cupos = $this->disponibilidad($filters);

        $reservas = $this->listar([
            ...$filters,
            'fecha' => $fecha,
            'estado' => null,
        ]);

        $ahoraFecha = now()->toDateString();
        $ahoraHora = now()->format('H:i:s');
        $reservados = 0;
        $asistieron = 0;
        $cancelados = 0;
        $noAsistieron = 0;

        foreach ($reservas as $reserva) {
            if ($reserva['estado'] === 'ASISTIO') {
                $asistieron++;
                continue;
            }

            if ($reserva['estado'] === 'CANCELADA') {
                $cancelados++;
                continue;
            }

            if ($reserva['estado'] === 'RESERVADA') {
                $terminada = $ahoraFecha > $reserva['fecha']
                    || ($ahoraFecha === $reserva['fecha'] && $ahoraHora > (string) $reserva['hora_fin']);

                if ($terminada) {
                    $noAsistieron++;
                } else {
                    $reservados++;
                }
            }
        }

        $porMembresia = collect($reservas)
            ->groupBy(fn ($item) => $item['membresia_nombre'] ?: 'Sin membresía')
            ->map(function ($items, $nombre) use ($ahoraFecha, $ahoraHora) {
                return [
                    'membresia' => $nombre,
                    'reservados' => $items->where('estado', 'RESERVADA')->filter(function ($item) use ($ahoraFecha, $ahoraHora) {
                        return !($ahoraFecha > $item['fecha'] || ($ahoraFecha === $item['fecha'] && $ahoraHora > (string) $item['hora_fin']));
                    })->count(),
                    'asistieron' => $items->where('estado', 'ASISTIO')->count(),
                    'no_asistieron' => $items->where('estado', 'RESERVADA')->filter(function ($item) use ($ahoraFecha, $ahoraHora) {
                        return $ahoraFecha > $item['fecha'] || ($ahoraFecha === $item['fecha'] && $ahoraHora > (string) $item['hora_fin']);
                    })->count(),
                    'cancelados' => $items->where('estado', 'CANCELADA')->count(),
                    'total' => $items->count(),
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->all();

        return [
            'fecha' => $fecha,
            'resumen' => [
                'capacidad' => collect($cupos)->sum('capacidad'),
                'disponibles' => collect($cupos)->sum('disponibles'),
                'reservados' => $reservados,
                'asistieron' => $asistieron,
                'no_asistieron' => $noAsistieron,
                'cancelados' => $cancelados,
                'total_reservas' => count($reservas),
            ],
            'cupos' => $cupos,
            'por_membresia' => $porMembresia,
        ];
    }
}
