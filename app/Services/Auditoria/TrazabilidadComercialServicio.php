<?php

namespace App\Services\Auditoria;

use App\Services\Seguridad\AlcanceOperativoService;
use Illuminate\Support\Facades\DB;

class TrazabilidadComercialServicio
{
    private const TABLAS = [
        'ventas.ventas',
        'ventas.pagos',
        'ventas.cajas',
        'ventas.turnos_caja',
        'cuentas_cobrar.cuentas',
        'cuentas_cobrar.gestiones',
        'cuentas_cobrar.compromisos_pago',
        'membresias.membresias',
        'membresias.membresia_periodos',
    ];

    public function __construct(
        private readonly AlcanceOperativoService $alcance,
    ) {}

    public function consultar(array $filtros, ?int $usuarioId = null): array
    {
        $query = $this->base();
        $this->aplicarAlcance($query, $usuarioId);
        $this->filtrar($query, $filtros);

        $resumen = DB::query()
            ->fromSub(clone $query, 't')
            ->selectRaw('COUNT(*) as total_eventos')
            ->selectRaw("SUM(CASE WHEN tabla = 'ventas.ventas' THEN 1 ELSE 0 END) as ventas")
            ->selectRaw("SUM(CASE WHEN tabla = 'ventas.pagos' THEN 1 ELSE 0 END) as pagos")
            ->selectRaw("SUM(CASE WHEN tabla IN ('membresias.membresias','membresias.membresia_periodos') THEN 1 ELSE 0 END) as membresias")
            ->selectRaw("SUM(CASE WHEN tabla IN ('ventas.cajas','ventas.turnos_caja') THEN 1 ELSE 0 END) as cajas")
            ->selectRaw("SUM(CASE WHEN tabla LIKE 'cuentas_cobrar.%' THEN 1 ELSE 0 END) as cartera")
            ->first();

        $paginador = $query
            ->orderByDesc('e.created_at')
            ->orderByDesc('e.id')
            ->paginate($filtros['per_page'] ?? 10, ['*'], 'page', $filtros['page'] ?? 1);

        $sedesPermitidas = $this->alcance->esGlobal($usuarioId)
            ? DB::table('institucional.sedes')->where('activo', true)->orderBy('nombre')->get(['id_sede as id', 'nombre'])
            : DB::table('institucional.sedes')
                ->whereIn('id_sede', $this->alcance->sedesPermitidas($usuarioId))
                ->where('activo', true)
                ->orderBy('nombre')
                ->get(['id_sede as id', 'nombre']);

        return [
            'datos' => $paginador->items(),
            'meta' => [
                'pagina_actual' => $paginador->currentPage(),
                'por_pagina' => $paginador->perPage(),
                'total' => $paginador->total(),
                'ultima_pagina' => $paginador->lastPage(),
                'resumen' => [
                    'total_eventos' => (int) ($resumen->total_eventos ?? 0),
                    'ventas' => (int) ($resumen->ventas ?? 0),
                    'pagos' => (int) ($resumen->pagos ?? 0),
                    'membresias' => (int) ($resumen->membresias ?? 0),
                    'cajas' => (int) ($resumen->cajas ?? 0),
                    'cartera' => (int) ($resumen->cartera ?? 0),
                ],
                'catalogos' => [
                    'procesos' => $this->opciones($query, 'proceso'),
                    'acciones' => $this->opciones($query, 'accion'),
                    'usuarios' => $this->opciones($query, 'usuario_nombre'),
                    'roles' => $this->opciones($query, 'rol'),
                    'sedes' => $sedesPermitidas,
                ],
            ],
        ];
    }

    public function todos(array $filtros, ?int $usuarioId = null): array
    {
        $pagina = 1;
        $resultado = [];

        do {
            $consulta = $this->consultar(
                array_merge($filtros, ['page' => $pagina, 'per_page' => 50]),
                $usuarioId
            );
            $resultado = array_merge($resultado, $consulta['datos']);
            $ultima = (int) ($consulta['meta']['ultima_pagina'] ?? 1);
            $pagina++;
        } while ($pagina <= $ultima);

        return $resultado;
    }

    private function base()
    {
        $procesoSql = $this->procesoSql();
        $sedeIdSql = $this->sedeIdSql();
        $referenciaSql = $this->referenciaSql();

        return DB::table('auditoria.eventos as e')
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', DB::raw($sedeIdSql))
            ->whereIn('e.tabla', self::TABLAS)
            ->select([
                'e.id',
                'e.usuario_id',
                'e.usuario_nombre',
                'e.rol',
                'e.modulo',
                'e.tabla',
                'e.registro_id',
                'e.accion',
                'e.descripcion',
                'e.datos_antes',
                'e.datos_despues',
                'e.ip',
                'e.user_agent',
                'e.created_at',
            ])
            ->selectRaw("{$procesoSql} as proceso")
            ->selectRaw("{$referenciaSql} as referencia")
            ->selectRaw("{$sedeIdSql} as sede_id")
            ->selectRaw("COALESCE(s.nombre, 'Sin sede') as sede");
    }

    private function aplicarAlcance($query, ?int $usuarioId): void
    {
        if ($this->alcance->esGlobal($usuarioId)) {
            return;
        }

        $sedes = $this->alcance->sedesPermitidas($usuarioId);

        if (empty($sedes)) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->whereIn(DB::raw($this->sedeIdSql()), $sedes);
    }

    private function filtrar($query, array $filtros): void
    {
        if (! empty($filtros['busqueda'])) {
            $texto = '%' . mb_strtolower((string) $filtros['busqueda']) . '%';
            $query->where(function ($q) use ($texto): void {
                $q->whereRaw("LOWER(COALESCE(e.usuario_nombre, '')) LIKE ?", [$texto])
                    ->orWhereRaw("LOWER(COALESCE(e.descripcion, '')) LIKE ?", [$texto])
                    ->orWhereRaw("LOWER(COALESCE(e.accion, '')) LIKE ?", [$texto])
                    ->orWhereRaw("LOWER(COALESCE(e.tabla, '')) LIKE ?", [$texto])
                    ->orWhereRaw("LOWER(COALESCE(e.registro_id, '')) LIKE ?", [$texto]);
            });
        }

        if (! empty($filtros['desde'])) {
            $query->where('e.created_at', '>=', $filtros['desde'] . ' 00:00:00');
        }

        if (! empty($filtros['hasta'])) {
            $query->where('e.created_at', '<=', $filtros['hasta'] . ' 23:59:59');
        }

        $this->whereInOTexto($query, DB::raw($this->procesoSql()), $filtros['proceso'] ?? null);
        $this->whereInOTexto($query, 'e.accion', $filtros['accion'] ?? null);
        $this->whereInOTexto($query, 'e.usuario_nombre', $filtros['usuario'] ?? null);
        $this->whereInOTexto($query, 'e.rol', $filtros['rol'] ?? null);

        if (! empty($filtros['sede_id'])) {
            $ids = is_array($filtros['sede_id']) ? $filtros['sede_id'] : [$filtros['sede_id']];
            $query->whereIn(DB::raw($this->sedeIdSql()), array_map('intval', array_filter($ids)));
        }

        if (! empty($filtros['referencia'])) {
            $query->whereRaw(
                'LOWER(' . $this->referenciaSql() . ') LIKE ?',
                ['%' . mb_strtolower((string) $filtros['referencia']) . '%']
            );
        }

        if (! empty($filtros['descripcion'])) {
            $query->whereRaw(
                "LOWER(COALESCE(e.descripcion, '')) LIKE ?",
                ['%' . mb_strtolower((string) $filtros['descripcion']) . '%']
            );
        }
    }

    private function procesoSql(): string
    {
        return "CASE
            WHEN e.tabla = 'ventas.ventas' THEN 'VENTAS'
            WHEN e.tabla = 'ventas.pagos' THEN 'PAGOS'
            WHEN e.tabla IN ('ventas.cajas','ventas.turnos_caja') THEN 'CAJA'
            WHEN e.tabla LIKE 'cuentas_cobrar.%' THEN 'CARTERA'
            WHEN e.tabla IN ('membresias.membresias','membresias.membresia_periodos') THEN 'MEMBRESIAS'
            ELSE 'COMERCIAL'
        END";
    }

    private function sedeIdSql(): string
    {
        return "CASE
            WHEN e.tabla = 'ventas.ventas' THEN (
                SELECT COALESCE(c.sede_id, m.sede_id)
                FROM ventas.ventas v
                LEFT JOIN ventas.cajas c ON c.id = v.caja_id
                LEFT JOIN membresias.membresias m ON m.id = v.membresia_id
                WHERE v.id::text = e.registro_id LIMIT 1
            )
            WHEN e.tabla = 'ventas.pagos' THEN (
                SELECT COALESCE(cp.sede_id, cv.sede_id, m.sede_id)
                FROM ventas.pagos p
                LEFT JOIN ventas.cajas cp ON cp.id = p.caja_id
                LEFT JOIN ventas.ventas v ON v.id = p.venta_id
                LEFT JOIN ventas.cajas cv ON cv.id = v.caja_id
                LEFT JOIN membresias.membresias m ON m.id = v.membresia_id
                WHERE p.id::text = e.registro_id LIMIT 1
            )
            WHEN e.tabla = 'ventas.cajas' THEN (
                SELECT c.sede_id FROM ventas.cajas c WHERE c.id::text = e.registro_id LIMIT 1
            )
            WHEN e.tabla = 'ventas.turnos_caja' THEN (
                SELECT t.sede_id FROM ventas.turnos_caja t WHERE t.id::text = e.registro_id LIMIT 1
            )
            WHEN e.tabla = 'membresias.membresias' THEN (
                SELECT m.sede_id FROM membresias.membresias m WHERE m.id::text = e.registro_id LIMIT 1
            )
            WHEN e.tabla = 'membresias.membresia_periodos' THEN (
                SELECT m.sede_id
                FROM membresias.membresia_periodos p
                JOIN membresias.membresias m ON m.id = p.membresia_id
                WHERE p.id::text = e.registro_id LIMIT 1
            )
            WHEN e.tabla LIKE 'cuentas_cobrar.%' THEN (
                SELECT COALESCE(c.sede_id, m.sede_id)
                FROM cuentas_cobrar.cuentas cc
                JOIN ventas.ventas v ON v.id = cc.venta_id
                LEFT JOIN ventas.cajas c ON c.id = v.caja_id
                LEFT JOIN membresias.membresias m ON m.id = v.membresia_id
                WHERE cc.id::text = COALESCE(
                    CASE WHEN e.tabla = 'cuentas_cobrar.cuentas' THEN e.registro_id END,
                    CASE WHEN e.tabla = 'cuentas_cobrar.gestiones' THEN (
                        SELECT g.cuenta_id::text FROM cuentas_cobrar.gestiones g
                        WHERE g.id::text = e.registro_id LIMIT 1
                    ) END,
                    CASE WHEN e.tabla = 'cuentas_cobrar.compromisos_pago' THEN (
                        SELECT cp.cuenta_id::text FROM cuentas_cobrar.compromisos_pago cp
                        WHERE cp.id::text = e.registro_id LIMIT 1
                    ) END
                )
                LIMIT 1
            )
            ELSE NULL
        END";
    }

    private function referenciaSql(): string
    {
        return "CASE
            WHEN e.tabla = 'ventas.ventas' THEN COALESCE(
                (SELECT v.numero FROM ventas.ventas v WHERE v.id::text = e.registro_id LIMIT 1),
                '#' || e.registro_id
            )
            WHEN e.tabla = 'ventas.pagos' THEN COALESCE(
                (SELECT p.codigo_cobro FROM ventas.pagos p WHERE p.id::text = e.registro_id LIMIT 1),
                'Pago #' || e.registro_id
            )
            WHEN e.tabla = 'ventas.cajas' THEN COALESCE(
                (SELECT c.codigo FROM ventas.cajas c WHERE c.id::text = e.registro_id LIMIT 1),
                'Caja #' || e.registro_id
            )
            WHEN e.tabla = 'ventas.turnos_caja' THEN 'Turno #' || e.registro_id
            WHEN e.tabla = 'membresias.membresias' THEN COALESCE(
                (SELECT m.codigo_contrato FROM membresias.membresias m WHERE m.id::text = e.registro_id LIMIT 1),
                'Membresía #' || e.registro_id
            )
            WHEN e.tabla = 'membresias.membresia_periodos' THEN 'Período #' || e.registro_id
            WHEN e.tabla = 'cuentas_cobrar.cuentas' THEN 'Cuenta #' || e.registro_id
            WHEN e.tabla = 'cuentas_cobrar.gestiones' THEN 'Gestión #' || e.registro_id
            WHEN e.tabla = 'cuentas_cobrar.compromisos_pago' THEN 'Compromiso #' || e.registro_id
            ELSE COALESCE(e.registro_id, '—')
        END";
    }

    private function opciones($query, string $campo)
    {
        return DB::query()
            ->fromSub(clone $query, 'opciones')
            ->distinct()
            ->orderBy($campo)
            ->pluck($campo)
            ->filter()
            ->values();
    }

    private function whereInOTexto($query, $columna, mixed $valor): void
    {
        if (empty($valor)) {
            return;
        }

        if (is_array($valor)) {
            $query->whereIn($columna, array_filter($valor));
            return;
        }

        if ($columna instanceof \Illuminate\Database\Query\Expression) {
            $query->whereRaw(
                'LOWER(' . $this->procesoSql() . ') LIKE ?',
                ['%' . mb_strtolower((string) $valor) . '%']
            );
            return;
        }

        $query->whereRaw(
            "LOWER({$columna}) LIKE ?",
            ['%' . mb_strtolower((string) $valor) . '%']
        );
    }
}
