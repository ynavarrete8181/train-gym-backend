<?php

namespace App\Services\Metas;

use App\Services\Concerns\RegistraAuditoria;
use App\Services\Seguridad\AlcanceOperativoService;
use App\Services\Ventas\Reportes\ResumenComercialServicio;
use App\Services\Ventas\Reportes\VentasResponsableServicio;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MetaComercialServicio
{
    use RegistraAuditoria;

    public function __construct(
        private readonly AlcanceOperativoService $alcance,
        private readonly ResumenComercialServicio $resumenComercial,
        private readonly VentasResponsableServicio $ventasResponsable,
    ) {}

    public function listar(array $filtros, int $usuarioId): array
    {
        $sedesPermitidas = $this->alcance->sedesPermitidas($usuarioId, 'maneja_caja');

        $query = DB::table('metas.metas as m')
            ->join('institucional.sedes as s', 's.id_sede', '=', 'm.sede_id')
            ->whereIn('m.sede_id', $sedesPermitidas)
            ->select('m.*', 's.nombre as sede');

        if (! empty($filtros['anio'])) {
            $query->where('m.anio', (int) $filtros['anio']);
        }

        if (! empty($filtros['mes'])) {
            $query->where('m.mes', (int) $filtros['mes']);
        }

        if (! empty($filtros['estado'])) {
            $query->where('m.estado', $filtros['estado']);
        }

        if (! empty($filtros['sede_id'])) {
            $ids = collect(is_array($filtros['sede_id']) ? $filtros['sede_id'] : [$filtros['sede_id']])
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->intersect($sedesPermitidas)
                ->values()
                ->all();

            if (! empty($ids)) {
                $query->whereIn('m.sede_id', $ids);
            }
        }

        if (! empty($filtros['busqueda'])) {
            $texto = '%' . mb_strtolower((string) $filtros['busqueda']) . '%';
            $query->where(function ($q) use ($texto): void {
                $q->whereRaw('LOWER(s.nombre) LIKE ?', [$texto])
                    ->orWhereRaw("LOWER(COALESCE(m.observaciones, '')) LIKE ?", [$texto]);
            });
        }

        $paginador = $query
            ->orderByDesc('m.anio')
            ->orderByDesc('m.mes')
            ->orderBy('s.nombre')
            ->paginate($filtros['per_page'] ?? 10, ['*'], 'page', $filtros['page'] ?? 1);

        $items = collect($paginador->items())
            ->map(fn ($meta) => $this->conSeguimiento($meta, $usuarioId))
            ->values()
            ->all();

        return [
            'datos' => $items,
            'meta' => [
                'pagina_actual' => $paginador->currentPage(),
                'por_pagina' => $paginador->perPage(),
                'total' => $paginador->total(),
                'ultima_pagina' => $paginador->lastPage(),
                'catalogos' => [
                    'sedes' => DB::table('institucional.sedes')
                        ->whereIn('id_sede', $sedesPermitidas)
                        ->where('activo', true)
                        ->orderBy('nombre')
                        ->get(['id_sede as id', 'nombre']),
                    'estados' => ['ACTIVA', 'INACTIVA'],
                ],
            ],
        ];
    }

    public function guardar(array $datos, int $usuarioId, ?int $id = null): array
    {
        $sedeId = $this->alcance->validarSede(
            $usuarioId,
            (int) $datos['sede_id'],
            'maneja_caja'
        );

        $existentePeriodo = DB::table('metas.metas')
            ->where('sede_id', $sedeId)
            ->where('anio', (int) $datos['anio'])
            ->where('mes', (int) $datos['mes'])
            ->when($id, fn ($q) => $q->where('id', '<>', $id))
            ->exists();

        if ($existentePeriodo) {
            throw ValidationException::withMessages([
                'periodo' => 'Ya existe una meta configurada para esta sede, año y mes.',
            ]);
        }

        $antes = $id ? DB::table('metas.metas')->where('id', $id)->first() : null;

        $payload = [
            'sede_id' => $sedeId,
            'anio' => (int) $datos['anio'],
            'mes' => (int) $datos['mes'],
            'meta_ventas' => round((float) ($datos['meta_ventas'] ?? 0), 2),
            'meta_cobros' => round((float) ($datos['meta_cobros'] ?? 0), 2),
            'meta_membresias_nuevas' => (int) ($datos['meta_membresias_nuevas'] ?? 0),
            'meta_renovaciones' => (int) ($datos['meta_renovaciones'] ?? 0),
            'estado' => $datos['estado'] ?? 'ACTIVA',
            'observaciones' => $datos['observaciones'] ?? null,
            'actualizado_por' => $usuarioId,
            'updated_at' => now(),
        ];

        DB::transaction(function () use ($id, $payload, $datos, $usuarioId, &$metaId): void {
            if ($id) {
                DB::table('metas.metas')->where('id', $id)->update($payload);
                $metaId = $id;
            } else {
                $metaId = DB::table('metas.metas')->insertGetId(array_merge($payload, [
                    'creado_por' => $usuarioId,
                    'created_at' => now(),
                ]));
            }

            DB::table('metas.meta_responsables')->where('meta_id', $metaId)->delete();

            foreach ($datos['responsables'] ?? [] as $responsable) {
                DB::table('metas.meta_responsables')->insert([
                    'meta_id' => $metaId,
                    'usuario_id' => (int) $responsable['usuario_id'],
                    'meta_ventas' => round((float) ($responsable['meta_ventas'] ?? 0), 2),
                    'meta_cobros' => round((float) ($responsable['meta_cobros'] ?? 0), 2),
                    'meta_membresias_nuevas' => (int) ($responsable['meta_membresias_nuevas'] ?? 0),
                    'meta_renovaciones' => (int) ($responsable['meta_renovaciones'] ?? 0),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $despues = DB::table('metas.metas')->where('id', $metaId)->first();

        $this->auditar(
            'metas',
            $id ? 'ACTUALIZAR' : 'CREAR',
            'metas.metas',
            $metaId,
            $antes,
            $despues,
            ($id ? 'Actualizó' : 'Creó') . ' meta comercial mensual.'
        );

        return $this->detalle((int) $metaId, $usuarioId);
    }

    public function detalle(int $id, int $usuarioId): array
    {
        $sedesPermitidas = $this->alcance->sedesPermitidas($usuarioId, 'maneja_caja');

        $meta = DB::table('metas.metas as m')
            ->join('institucional.sedes as s', 's.id_sede', '=', 'm.sede_id')
            ->where('m.id', $id)
            ->whereIn('m.sede_id', $sedesPermitidas)
            ->select('m.*', 's.nombre as sede')
            ->first();

        abort_unless($meta, 404, 'Meta comercial no encontrada.');

        $responsables = DB::table('metas.meta_responsables as mr')
            ->join('seguridad.users as u', 'u.id', '=', 'mr.usuario_id')
            ->where('mr.meta_id', $id)
            ->select('mr.*', 'u.name as responsable')
            ->orderBy('u.name')
            ->get()
            ->map(fn ($fila) => $this->seguimientoResponsable($fila, $meta))
            ->values();

        return [
            'meta' => $this->conSeguimiento($meta, $usuarioId),
            'responsables' => $responsables,
            'catalogos' => [
                'responsables' => $this->catalogoResponsables((int) $meta->sede_id),
            ],
        ];
    }

    public function catalogos(int $usuarioId, ?int $sedeId = null): array
    {
        $sedes = $this->alcance->sedesPermitidas($usuarioId, 'maneja_caja');

        return [
            'sedes' => DB::table('institucional.sedes')
                ->whereIn('id_sede', $sedes)
                ->where('activo', true)
                ->orderBy('nombre')
                ->get(['id_sede as id', 'nombre']),
            'responsables' => $sedeId && in_array($sedeId, $sedes, true)
                ? $this->catalogoResponsables($sedeId)
                : [],
            'estados' => ['ACTIVA', 'INACTIVA'],
        ];
    }

    private function conSeguimiento(object $meta, int $usuarioId): array
    {
        $desde = sprintf('%04d-%02d-01', $meta->anio, $meta->mes);
        $hastaMes = now()->create($meta->anio, $meta->mes, 1)->endOfMonth();
        $hasta = $hastaMes->isFuture() ? min(now(), $hastaMes)->toDateString() : $hastaMes->toDateString();

        $comercial = $this->resumenComercial->consultar([
            'desde' => $desde,
            'hasta' => $hasta,
            'sede_id' => [(int) $meta->sede_id],
        ], $usuarioId);

        $movimientos = $this->movimientosMembresias(
            (int) $meta->sede_id,
            $desde,
            $hasta
        );

        $ventas = (float) ($comercial['indicadores']['total_ventas'] ?? 0);
        $cobros = (float) ($comercial['indicadores']['total_cobrado'] ?? 0);

        return [
            'id' => (int) $meta->id,
            'sede_id' => (int) $meta->sede_id,
            'sede' => $meta->sede,
            'anio' => (int) $meta->anio,
            'mes' => (int) $meta->mes,
            'estado' => $meta->estado,
            'observaciones' => $meta->observaciones,
            'meta_ventas' => (float) $meta->meta_ventas,
            'meta_cobros' => (float) $meta->meta_cobros,
            'meta_membresias_nuevas' => (int) $meta->meta_membresias_nuevas,
            'meta_renovaciones' => (int) $meta->meta_renovaciones,
            'real_ventas' => round($ventas, 2),
            'real_cobros' => round($cobros, 2),
            'real_membresias_nuevas' => (int) $movimientos['nuevas'],
            'real_renovaciones' => (int) $movimientos['renovaciones'],
            'cumplimiento_ventas' => $this->porcentaje($ventas, (float) $meta->meta_ventas),
            'cumplimiento_cobros' => $this->porcentaje($cobros, (float) $meta->meta_cobros),
            'cumplimiento_membresias_nuevas' => $this->porcentaje((float) $movimientos['nuevas'], (float) $meta->meta_membresias_nuevas),
            'cumplimiento_renovaciones' => $this->porcentaje((float) $movimientos['renovaciones'], (float) $meta->meta_renovaciones),
            'dias_transcurridos' => now()->year === (int) $meta->anio && now()->month === (int) $meta->mes ? now()->day : null,
            'dias_mes' => now()->create($meta->anio, $meta->mes, 1)->daysInMonth,
        ];
    }

    private function seguimientoResponsable(object $fila, object $meta): array
    {
        $desde = sprintf('%04d-%02d-01', $meta->anio, $meta->mes);
        $hastaMes = now()->create($meta->anio, $meta->mes, 1)->endOfMonth();
        $hasta = $hastaMes->isFuture() ? min(now(), $hastaMes)->toDateString() : $hastaMes->toDateString();

        $ventas = DB::table('ventas.ventas as v')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->where('v.responsable_comercial_id', $fila->usuario_id)
            ->where(DB::raw('COALESCE(c.sede_id, m.sede_id)'), $meta->sede_id)
            ->whereBetween(DB::raw('DATE(v.fecha_venta)'), [$desde, $hasta])
            ->where('v.estado', '<>', 'ANULADA')
            ->sum('v.total');

        $cobros = DB::table('ventas.pagos as p')
            ->join('ventas.ventas as v', 'v.id', '=', 'p.venta_id')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'p.caja_id')
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->where('v.responsable_comercial_id', $fila->usuario_id)
            ->where(DB::raw('COALESCE(c.sede_id, m.sede_id)'), $meta->sede_id)
            ->whereBetween(DB::raw('DATE(p.fecha_pago)'), [$desde, $hasta])
            ->where('p.estado', 'CONFIRMADO')
            ->sum('p.monto');

        $movimientos = $this->movimientosMembresias(
            (int) $meta->sede_id,
            $desde,
            $hasta,
            (int) $fila->usuario_id
        );

        return [
            'id' => (int) $fila->id,
            'usuario_id' => (int) $fila->usuario_id,
            'responsable' => $fila->responsable,
            'meta_ventas' => (float) $fila->meta_ventas,
            'real_ventas' => round((float) $ventas, 2),
            'cumplimiento_ventas' => $this->porcentaje((float) $ventas, (float) $fila->meta_ventas),
            'meta_cobros' => (float) $fila->meta_cobros,
            'real_cobros' => round((float) $cobros, 2),
            'cumplimiento_cobros' => $this->porcentaje((float) $cobros, (float) $fila->meta_cobros),
            'meta_membresias_nuevas' => (int) $fila->meta_membresias_nuevas,
            'real_membresias_nuevas' => (int) $movimientos['nuevas'],
            'cumplimiento_membresias_nuevas' => $this->porcentaje((float) $movimientos['nuevas'], (float) $fila->meta_membresias_nuevas),
            'meta_renovaciones' => (int) $fila->meta_renovaciones,
            'real_renovaciones' => (int) $movimientos['renovaciones'],
            'cumplimiento_renovaciones' => $this->porcentaje((float) $movimientos['renovaciones'], (float) $fila->meta_renovaciones),
        ];
    }

    private function movimientosMembresias(int $sedeId, string $desde, string $hasta, ?int $responsableId = null): array
    {
        $query = DB::table('membresias.membresia_periodos as mp')
            ->join('membresias.membresias as m', 'm.id', '=', 'mp.membresia_id')
            ->leftJoin('ventas.ventas as v', 'v.id', '=', 'mp.venta_id')
            ->where('m.sede_id', $sedeId)
            ->whereBetween(
                DB::raw('COALESCE(DATE(mp.generado_at), DATE(mp.created_at), mp.fecha_inicio)'),
                [$desde, $hasta]
            );

        if ($responsableId) {
            $query->where('v.responsable_comercial_id', $responsableId);
        }

        $fila = $query
            ->selectRaw("SUM(CASE WHEN mp.numero_periodo = 1 THEN 1 ELSE 0 END) as nuevas")
            ->selectRaw("SUM(CASE WHEN mp.numero_periodo > 1 THEN 1 ELSE 0 END) as renovaciones")
            ->first();

        return [
            'nuevas' => (int) ($fila->nuevas ?? 0),
            'renovaciones' => (int) ($fila->renovaciones ?? 0),
        ];
    }

    private function catalogoResponsables(int $sedeId): array
    {
        return DB::table('ventas.ventas as v')
            ->join('seguridad.users as u', 'u.id', '=', 'v.responsable_comercial_id')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->where(DB::raw('COALESCE(c.sede_id, m.sede_id)'), $sedeId)
            ->whereNotNull('v.responsable_comercial_id')
            ->select('u.id', 'u.name as nombre')
            ->distinct()
            ->orderBy('u.name')
            ->get()
            ->map(fn ($fila) => ['id' => (int) $fila->id, 'nombre' => $fila->nombre])
            ->all();
    }

    private function porcentaje(float $real, float $meta): float
    {
        if ($meta <= 0) {
            return $real > 0 ? 100.0 : 0.0;
        }

        return round(($real / $meta) * 100, 2);
    }
}
