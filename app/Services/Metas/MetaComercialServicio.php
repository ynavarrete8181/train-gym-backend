<?php

namespace App\Services\Metas;

use App\Services\Concerns\RegistraAuditoria;
use App\Services\Seguridad\AlcanceOperativoService;
use App\Services\Ventas\Reportes\ResumenComercialServicio;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MetaComercialServicio
{
    use RegistraAuditoria;

    public function __construct(
        private readonly AlcanceOperativoService $alcance,
        private readonly ResumenComercialServicio $resumenComercial,
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

        $items = $query
            ->orderByDesc('m.anio')
            ->orderByDesc('m.mes')
            ->orderBy('s.nombre')
            ->get()
            ->map(fn ($meta) => $this->conSeguimiento($meta, $usuarioId))
            ->filter(fn (array $item) => $this->coincideFiltrosCalculados($item, $filtros))
            ->values();

        $porPagina = (int) ($filtros['per_page'] ?? 10);
        $paginaActual = max(1, (int) ($filtros['page'] ?? 1));
        $total = $items->count();
        $ultimaPagina = max(1, (int) ceil($total / max(1, $porPagina)));
        $paginaActual = min($paginaActual, $ultimaPagina);
        $itemsPagina = $items
            ->slice(($paginaActual - 1) * $porPagina, $porPagina)
            ->values()
            ->all();

        return [
            'datos' => $itemsPagina,
            'meta' => [
                'pagina_actual' => $paginaActual,
                'por_pagina' => $porPagina,
                'total' => $total,
                'ultima_pagina' => $ultimaPagina,
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

    public function todos(array $filtros, int $usuarioId): array
    {
        $pagina = 1;
        $resultado = [];

        do {
            $consulta = $this->listar(
                array_merge($filtros, ['page' => $pagina, 'per_page' => 50]),
                $usuarioId
            );

            $resultado = array_merge($resultado, $consulta['datos']);
            $ultima = (int) ($consulta['meta']['ultima_pagina'] ?? 1);
            $pagina++;
        } while ($pagina <= $ultima);

        return $resultado;
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

        $antes = null;

        if ($id) {
            $sedesPermitidas = $this->alcance->sedesPermitidas($usuarioId, 'maneja_caja');
            $antes = DB::table('metas.metas')
                ->where('id', $id)
                ->whereIn('sede_id', $sedesPermitidas)
                ->first();

            abort_unless($antes, 404, 'Meta comercial no encontrada.');
        }

        $responsablesPermitidos = collect($this->catalogoResponsables($sedeId))
            ->pluck('id')
            ->map(fn ($valor) => (int) $valor)
            ->all();

        foreach ($datos['responsables'] ?? [] as $responsable) {
            if (! in_array((int) $responsable['usuario_id'], $responsablesPermitidos, true)) {
                throw ValidationException::withMessages([
                    'responsables' => 'Uno de los responsables seleccionados no pertenece al alcance comercial de la sede.',
                ]);
            }
        }

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

        $metaId = $id;

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
        $rango = $this->rangoMeta((int) $meta->anio, (int) $meta->mes);

        if ($rango['futuro']) {
            $ventas = 0.0;
            $cobros = 0.0;
            $movimientos = ['nuevas' => 0, 'renovaciones' => 0];
        } else {
            $comercial = $this->resumenComercial->consultar([
                'desde' => $rango['desde'],
                'hasta' => $rango['hasta'],
                'sede_id' => [(int) $meta->sede_id],
            ], $usuarioId);

            $movimientos = $this->movimientosMembresias(
                (int) $meta->sede_id,
                $rango['desde'],
                $rango['hasta']
            );

            $ventas = (float) ($comercial['indicadores']['total_ventas'] ?? 0);
            $cobros = (float) ($comercial['indicadores']['total_cobrado'] ?? 0);
        }

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
            'dias_mes' => Carbon::create((int) $meta->anio, (int) $meta->mes, 1)->daysInMonth,
        ];
    }

    private function seguimientoResponsable(object $fila, object $meta): array
    {
        $rango = $this->rangoMeta((int) $meta->anio, (int) $meta->mes);

        if ($rango['futuro']) {
            return [
                'id' => (int) $fila->id,
                'usuario_id' => (int) $fila->usuario_id,
                'responsable' => $fila->responsable,
                'meta_ventas' => (float) $fila->meta_ventas,
                'real_ventas' => 0.0,
                'cumplimiento_ventas' => 0.0,
                'meta_cobros' => (float) $fila->meta_cobros,
                'real_cobros' => 0.0,
                'cumplimiento_cobros' => 0.0,
                'meta_membresias_nuevas' => (int) $fila->meta_membresias_nuevas,
                'real_membresias_nuevas' => 0,
                'cumplimiento_membresias_nuevas' => 0.0,
                'meta_renovaciones' => (int) $fila->meta_renovaciones,
                'real_renovaciones' => 0,
                'cumplimiento_renovaciones' => 0.0,
            ];
        }

        $ventas = DB::table('ventas.ventas as v')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->where('v.responsable_comercial_id', $fila->usuario_id)
            ->where(DB::raw('COALESCE(c.sede_id, m.sede_id)'), $meta->sede_id)
            ->whereBetween(DB::raw('DATE(v.fecha_venta)'), [$rango['desde'], $rango['hasta']])
            ->where('v.estado', '<>', 'ANULADA')
            ->sum('v.total');

        $cobros = DB::table('ventas.pagos as p')
            ->join('ventas.ventas as v', 'v.id', '=', 'p.venta_id')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'p.caja_id')
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->where('v.responsable_comercial_id', $fila->usuario_id)
            ->where(DB::raw('COALESCE(c.sede_id, m.sede_id)'), $meta->sede_id)
            ->whereBetween(DB::raw('DATE(p.fecha_pago)'), [$rango['desde'], $rango['hasta']])
            ->where('p.estado', 'CONFIRMADO')
            ->sum('p.monto');

        $movimientos = $this->movimientosMembresias(
            (int) $meta->sede_id,
            $rango['desde'],
            $rango['hasta'],
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
        return DB::table('seguridad.users as u')
            ->join('seguridad.cpu_userrole as r', 'r.id_userrole', '=', 'u.usr_tipo')
            ->where('u.usr_estado', 1)
            ->where('r.activo', true)
            ->whereIn('r.role', [
                'RESPONSABLE',
                'SUPERVISOR DE VENTAS',
                'ADMINISTRADOR',
                'SUPERADMINISTRADOR',
            ])
            ->where(function ($q) use ($sedeId): void {
                $q->where('r.role', 'SUPERADMINISTRADOR')
                    ->orWhereExists(function ($sub) use ($sedeId): void {
                        $sub->selectRaw('1')
                            ->from('institucional.usuario_contexto as uc')
                            ->join('institucional.contextos as c', 'c.id_contexto', '=', 'uc.id_contexto')
                            ->whereColumn('uc.id_usuario', 'u.id')
                            ->where('uc.activo', true)
                            ->where('c.activo', true)
                            ->where('c.id_sede', $sedeId);
                    });
            })
            ->select('u.id', 'u.name as nombre', 'r.role as rol')
            ->distinct()
            ->orderBy('u.name')
            ->get()
            ->map(fn ($fila) => [
                'id' => (int) $fila->id,
                'nombre' => $fila->nombre,
                'rol' => $fila->rol,
            ])
            ->all();
    }

    private function rangoMeta(int $anio, int $mes): array
    {
        $inicio = Carbon::create($anio, $mes, 1)->startOfDay();
        $finMes = $inicio->copy()->endOfMonth();
        $hoy = now();

        if ($inicio->isAfter($hoy)) {
            return [
                'desde' => $inicio->toDateString(),
                'hasta' => $inicio->toDateString(),
                'futuro' => true,
            ];
        }

        return [
            'desde' => $inicio->toDateString(),
            'hasta' => $finMes->isAfter($hoy) ? $hoy->toDateString() : $finMes->toDateString(),
            'futuro' => false,
        ];
    }

    private function coincideFiltrosCalculados(array $item, array $filtros): bool
    {
        if (! empty($filtros['periodo'])) {
            $periodo = mb_strtolower(sprintf(
                '%02d/%04d %s %d',
                (int) $item['mes'],
                (int) $item['anio'],
                Carbon::create((int) $item['anio'], (int) $item['mes'], 1)->locale('es')->translatedFormat('F'),
                (int) $item['anio'],
            ));

            if (! str_contains($periodo, mb_strtolower(trim((string) $filtros['periodo'])))) {
                return false;
            }
        }

        foreach ([
            'ventas' => ['real_ventas', 'meta_ventas', 'cumplimiento_ventas'],
            'cobros' => ['real_cobros', 'meta_cobros', 'cumplimiento_cobros'],
            'nuevas' => ['real_membresias_nuevas', 'meta_membresias_nuevas', 'cumplimiento_membresias_nuevas'],
            'renovaciones' => ['real_renovaciones', 'meta_renovaciones', 'cumplimiento_renovaciones'],
        ] as $filtro => $campos) {
            if (empty($filtros[$filtro])) {
                continue;
            }

            $busqueda = mb_strtolower(trim((string) $filtros[$filtro]));
            $coincide = collect($campos)->contains(function (string $campo) use ($item, $busqueda): bool {
                $valor = $item[$campo] ?? '';
                $variantes = [
                    (string) $valor,
                    number_format((float) $valor, 2, '.', ''),
                    number_format((float) $valor, 0, '.', ''),
                ];

                return collect($variantes)->contains(
                    fn (string $texto) => str_contains(mb_strtolower($texto), $busqueda)
                );
            });

            if (! $coincide) {
                return false;
            }
        }

        return true;
    }

    private function porcentaje(float $real, float $meta): float
    {
        if ($meta <= 0) {
            return $real > 0 ? 100.0 : 0.0;
        }

        return round(($real / $meta) * 100, 2);
    }
}
