<?php

namespace App\Services\Gimnasio\Reportes;

use App\Services\Seguridad\AlcanceOperativoService;
use App\Services\Ventas\Reportes\FiltraColumnasReporte;
use Illuminate\Support\Facades\DB;

/**
 * Reporte de clientes: el padrón con sus datos y su situación actual (membresía vigente, deuda,
 * última asistencia y estado). Una fila por deportista. Las tarjetas cuentan sobre los filtros
 * aplicados y, al tocarlas, filtran la tabla (parámetro `tarjeta`).
 */
class ClientesReporteServicio
{
    use FiltraColumnasReporte;

    private const COLUMNAS = ['cliente', 'cedula', 'telefono', 'tutor', 'sede', 'membresia', 'membresia_estado', 'deuda', 'ultima_asistencia', 'estado'];
    private const TARJETAS = [
        'ACTIVOS' => "estado = 'ACTIVO'",
        'MEMBRESIA_ACTIVA' => "membresia_estado = 'ACTIVA'",
        'CON_DEUDA' => 'deuda > 0',
        'PROSPECTOS' => "estado = 'PROSPECTO'",
        'SIN_MEMBRESIA' => 'membresia IS NULL',
        'SIN_CEDULA' => "cedula = 'Sin documento'",
    ];

    public function __construct(private readonly AlcanceOperativoService $alcance)
    {
    }

    public function consultar(array $filtros, int $usuarioId): array
    {
        $global = $this->alcance->esGlobal($usuarioId);
        $sedesPermitidas = $this->alcance->sedesPermitidas($usuarioId);
        $sedes = collect(is_array($filtros['sede_id'] ?? null) ? $filtros['sede_id'] : [$filtros['sede_id'] ?? null])
            ->filter()->map(fn ($id) => (int) $id)->values()->all();

        // Membresía de referencia: la vigente (activa, pendiente o congelada) más reciente; si no hay, la última.
        $membresiaSql = "(SELECT m.id FROM membresias.membresias m WHERE m.deportista_id = d.id
            ORDER BY CASE WHEN m.estado IN ('ACTIVA','PENDIENTE_PAGO','CONGELADA') THEN 0 ELSE 1 END, m.fecha_fin DESC NULLS LAST, m.id DESC LIMIT 1)";
        $deudaSql = "(SELECT COALESCE(SUM(GREATEST(v.total - COALESCE((SELECT SUM(pg.monto) FROM ventas.pagos pg
            WHERE pg.venta_id = v.id AND pg.estado = 'CONFIRMADO'), 0), 0)), 0)
            FROM ventas.ventas v WHERE v.cliente_id = d.id AND v.estado IN ('PENDIENTE','PARCIAL'))";

        $query = DB::table('clientes.deportistas as d')
            ->join('personas.personas as p', 'p.id', '=', 'd.persona_id')
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', 'd.sede_principal_id')
            ->leftJoin('personas.personas as tutor', 'tutor.id', '=', DB::raw('(SELECT r.representante_persona_id FROM clientes.deportista_representantes r
                WHERE r.deportista_id = d.id AND r.activo ORDER BY r.responsable_pago DESC, r.es_principal DESC, r.id LIMIT 1)'))
            ->leftJoin('membresias.membresias as m', 'm.id', '=', DB::raw($membresiaSql))
            ->leftJoin('membresias.planes as plan', 'plan.id', '=', 'm.plan_id')
            ->leftJoin('membresias.plan_modalidades as modalidad', 'modalidad.id', '=', 'm.modalidad_id')
            ->selectRaw('d.id, d.codigo_deportista as codigo, d.created_at::date as registrado')
            ->selectRaw("COALESCE(NULLIF(TRIM(p.nombre_completo), ''), 'Sin nombre') as cliente")
            ->selectRaw("COALESCE(NULLIF(TRIM(p.identificacion), ''), 'Sin documento') as cedula")
            ->selectRaw('COALESCE(p.fecha_nacimiento, d.fecha_nacimiento) as fecha_nacimiento')
            ->selectRaw("DATE_PART('year', AGE(COALESCE(p.fecha_nacimiento, d.fecha_nacimiento)))::int as edad")
            ->selectRaw("COALESCE(NULLIF(TRIM(tutor.telefono), ''), NULLIF(TRIM(p.telefono), ''), NULLIF(TRIM(d.telefono), ''), 'Sin teléfono') as telefono")
            ->selectRaw("COALESCE(NULLIF(TRIM(p.email), ''), NULLIF(TRIM(tutor.email), '')) as correo")
            ->selectRaw("COALESCE(NULLIF(TRIM(tutor.nombre_completo), ''), 'Sin tutor') as tutor")
            ->selectRaw("COALESCE(s.nombre, 'Sin sede') as sede, d.sede_principal_id as sede_id")
            ->selectRaw("CASE WHEN m.id IS NULL THEN NULL ELSE plan.nombre || COALESCE(' · ' || modalidad.nombre, '') END as membresia")
            ->selectRaw('m.codigo_contrato as contrato, m.estado as membresia_estado, m.fecha_fin::date as vence')
            ->selectRaw("ROUND({$deudaSql}, 2) as deuda")
            ->selectRaw('(SELECT MAX(a.fecha_hora)::date FROM acceso.asistencias a WHERE a.cliente_id = d.id) as ultima_asistencia')
            ->selectRaw('d.estado');

        if (! $global) {
            $query->where(fn ($q) => $q->whereIn('d.sede_principal_id', $sedesPermitidas ?: [0])
                ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('membresias.membresia_sedes as ms')
                    ->join('membresias.membresias as mm', 'mm.id', '=', 'ms.membresia_id')
                    ->whereColumn('mm.deportista_id', 'd.id')->whereIn('ms.sede_id', $sedesPermitidas ?: [0])));
        }
        if ($sedes) {
            $query->whereIn('d.sede_principal_id', $sedes);
        }
        if (! empty($filtros['busqueda'])) {
            $texto = '%'.mb_strtolower(trim((string) $filtros['busqueda'])).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(p.nombre_completo) LIKE ?', [$texto])
                ->orWhereRaw('LOWER(COALESCE(p.identificacion, \'\')) LIKE ?', [$texto])
                ->orWhereRaw('LOWER(d.codigo_deportista) LIKE ?', [$texto])
                ->orWhereRaw('LOWER(COALESCE(tutor.nombre_completo, \'\')) LIKE ?', [$texto]));
        }

        [$reporte, $opcionesFiltro] = $this->filtrarColumnas($query, $filtros, self::COLUMNAS);

        $resumen = (clone $reporte)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("COUNT(*) FILTER (WHERE estado = 'ACTIVO') as activos")
            ->selectRaw("COUNT(*) FILTER (WHERE membresia_estado = 'ACTIVA') as membresia_activa")
            ->selectRaw("COUNT(*) FILTER (WHERE membresia_estado = 'PENDIENTE_PAGO') as membresia_pendiente")
            ->selectRaw('COUNT(*) FILTER (WHERE deuda > 0) as con_deuda, COALESCE(SUM(deuda), 0) as deuda_total')
            ->selectRaw("COUNT(*) FILTER (WHERE estado = 'PROSPECTO') as prospectos")
            ->selectRaw('COUNT(*) FILTER (WHERE membresia IS NULL) as sin_membresia')
            ->selectRaw("COUNT(*) FILTER (WHERE cedula = 'Sin documento') as sin_cedula")
            ->first();

        if (! empty($filtros['tarjeta']) && isset(self::TARJETAS[$filtros['tarjeta']])) {
            $reporte->whereRaw(self::TARJETAS[$filtros['tarjeta']]);
        }

        $paginador = $reporte->orderBy('cliente')
            ->paginate($filtros['per_page'] ?? 10, ['*'], 'page', $filtros['page'] ?? 1);

        return [
            'datos' => $paginador->items(),
            'meta' => [
                'pagina_actual' => $paginador->currentPage(),
                'por_pagina' => $paginador->perPage(),
                'total' => $paginador->total(),
                'ultima_pagina' => $paginador->lastPage(),
                'resumen' => [
                    'total' => (int) $resumen->total,
                    'activos' => (int) $resumen->activos,
                    'membresia_activa' => (int) $resumen->membresia_activa,
                    'membresia_pendiente' => (int) $resumen->membresia_pendiente,
                    'con_deuda' => (int) $resumen->con_deuda,
                    'deuda_total' => round((float) $resumen->deuda_total, 2),
                    'prospectos' => (int) $resumen->prospectos,
                    'sin_membresia' => (int) $resumen->sin_membresia,
                    'sin_cedula' => (int) $resumen->sin_cedula,
                ],
                'opciones_filtro' => $opcionesFiltro,
                'catalogos' => [
                    'sedes' => DB::table('institucional.sedes')->where('activo', true)
                        ->when(! $global, fn ($q) => $q->whereIn('id_sede', $sedesPermitidas ?: [0]))
                        ->orderBy('nombre')->get(['id_sede as id', 'nombre']),
                ],
            ],
        ];
    }
}
