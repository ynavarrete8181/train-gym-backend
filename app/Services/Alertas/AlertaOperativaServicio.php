<?php

namespace App\Services\Alertas;

use App\Services\Seguridad\AlcanceOperativoService;
use App\Services\Seguridad\AvisoUsuarioService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AlertaOperativaServicio
{
    private const TIPOS_GESTIONADOS = [
        'CARTERA_VENCIDA',
        'MEMBRESIAS_POR_VENCER',
        'CONCILIACION_CAJA',
        'DIFERENCIA_CAJA',
    ];

    public function __construct(
        private readonly AlcanceOperativoService $alcance,
        private readonly AvisoUsuarioService $avisos,
    ) {}

    public function procesar(): array
    {
        $detectadas = collect()
            ->merge($this->detectarCarteraVencida())
            ->merge($this->detectarMembresiasPorVencer())
            ->merge($this->detectarConciliacionesPendientes())
            ->merge($this->detectarDiferenciasCajaHoy())
            ->values();

        $identidadesActivas = [];

        foreach ($detectadas as $alerta) {
            $identidad = $this->identidad($alerta);
            $identidadesActivas[] = $identidad;
            $this->registrarOActualizar($alerta);
        }

        $resueltas = $this->resolverAusentes($identidadesActivas);

        return [
            'detectadas' => $detectadas->count(),
            'resueltas' => $resueltas,
            'tipos' => $detectadas->countBy('tipo')->all(),
        ];
    }

    public function listar(array $filtros, int $usuarioId): array
    {
        $sedesPermitidas = $this->alcance->sedesPermitidas($usuarioId);

        $query = DB::table('notificaciones.alertas_operativas as a')
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', 'a.sede_id')
            ->where(function ($q) use ($sedesPermitidas, $usuarioId): void {
                if ($this->alcance->esGlobal($usuarioId)) {
                    return;
                }

                if (empty($sedesPermitidas)) {
                    $q->whereRaw('1 = 0');
                    return;
                }

                $q->whereIn('a.sede_id', $sedesPermitidas);
            })
            ->select('a.*', DB::raw("COALESCE(s.nombre, 'Sin sede') as sede"));

        if (! empty($filtros['id'])) {
            $query->where('a.id', (int) $filtros['id']);
        }

        if (! empty($filtros['estado'])) {
            $valores = is_array($filtros['estado']) ? $filtros['estado'] : [$filtros['estado']];
            $query->whereIn('a.estado', array_filter($valores));
        }

        if (! empty($filtros['tipo'])) {
            $valores = is_array($filtros['tipo']) ? $filtros['tipo'] : [$filtros['tipo']];
            $query->whereIn('a.tipo', array_filter($valores));
        }

        if (! empty($filtros['nivel'])) {
            $valores = is_array($filtros['nivel']) ? $filtros['nivel'] : [$filtros['nivel']];
            $query->whereIn('a.nivel', array_filter($valores));
        }

        if (! empty($filtros['sede_id'])) {
            $solicitadas = collect(is_array($filtros['sede_id']) ? $filtros['sede_id'] : [$filtros['sede_id']])
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->intersect($sedesPermitidas)
                ->values()
                ->all();

            if (! empty($solicitadas)) {
                $query->whereIn('a.sede_id', $solicitadas);
            }
        }

        if (! empty($filtros['busqueda'])) {
            $texto = '%' . mb_strtolower((string) $filtros['busqueda']) . '%';
            $query->where(function ($q) use ($texto): void {
                $q->whereRaw("LOWER(COALESCE(a.titulo, '')) LIKE ?", [$texto])
                    ->orWhereRaw("LOWER(COALESCE(a.mensaje, '')) LIKE ?", [$texto])
                    ->orWhereRaw("LOWER(COALESCE(a.tipo, '')) LIKE ?", [$texto])
                    ->orWhereRaw("LOWER(COALESCE(s.nombre, '')) LIKE ?", [$texto]);
            });
        }

        $resumenQuery = clone $query;
        $resumen = DB::query()
            ->fromSub($resumenQuery, 'alertas')
            ->selectRaw("SUM(CASE WHEN estado = 'ACTIVA' THEN 1 ELSE 0 END) as activas")
            ->selectRaw("SUM(CASE WHEN estado = 'RESUELTA' THEN 1 ELSE 0 END) as resueltas")
            ->selectRaw("SUM(CASE WHEN estado = 'ACTIVA' AND nivel = 'ERROR' THEN 1 ELSE 0 END) as criticas")
            ->selectRaw("SUM(CASE WHEN estado = 'ACTIVA' AND nivel = 'WARNING' THEN 1 ELSE 0 END) as advertencias")
            ->first();

        $paginador = $query
            ->orderByRaw("CASE WHEN a.estado = 'ACTIVA' THEN 0 ELSE 1 END")
            ->orderByDesc('a.ultima_deteccion_at')
            ->paginate($filtros['per_page'] ?? 10, ['*'], 'page', $filtros['page'] ?? 1);

        return [
            'datos' => $paginador->items(),
            'meta' => [
                'pagina_actual' => $paginador->currentPage(),
                'por_pagina' => $paginador->perPage(),
                'total' => $paginador->total(),
                'ultima_pagina' => $paginador->lastPage(),
                'resumen' => [
                    'activas' => (int) ($resumen->activas ?? 0),
                    'resueltas' => (int) ($resumen->resueltas ?? 0),
                    'criticas' => (int) ($resumen->criticas ?? 0),
                    'advertencias' => (int) ($resumen->advertencias ?? 0),
                ],
                'catalogos' => [
                    'tipos' => self::TIPOS_GESTIONADOS,
                    'niveles' => ['INFO', 'WARNING', 'ERROR'],
                    'estados' => ['ACTIVA', 'RESUELTA'],
                    'sedes' => DB::table('institucional.sedes')
                        ->whereIn('id_sede', $sedesPermitidas)
                        ->where('activo', true)
                        ->orderBy('nombre')
                        ->get(['id_sede as id', 'nombre']),
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

    private function detectarCarteraVencida(): Collection
    {
        $pagadoSql = "(SELECT COALESCE(SUM(pg.monto), 0) FROM ventas.pagos pg WHERE pg.venta_id = v.id AND pg.estado = 'CONFIRMADO')";
        $saldoSql = "GREATEST(v.total - {$pagadoSql}, 0)";

        return DB::table('cuentas_cobrar.cuentas as cc')
            ->join('ventas.ventas as v', 'v.id', '=', 'cc.venta_id')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->whereDate('cc.fecha_vencimiento', '<', now()->toDateString())
            ->where('v.estado', '<>', 'ANULADA')
            ->whereRaw("{$saldoSql} > 0")
            ->whereNotNull(DB::raw('COALESCE(c.sede_id, m.sede_id)'))
            ->groupByRaw('COALESCE(c.sede_id, m.sede_id)')
            ->selectRaw('COALESCE(c.sede_id, m.sede_id) as sede_id')
            ->selectRaw('COUNT(*) as cantidad')
            ->selectRaw("SUM({$saldoSql}) as saldo")
            ->get()
            ->map(fn ($fila) => [
                'clave' => 'CARTERA_VENCIDA',
                'tipo' => 'CARTERA_VENCIDA',
                'nivel' => 'WARNING',
                'sede_id' => (int) $fila->sede_id,
                'titulo' => 'Cartera vencida',
                'mensaje' => sprintf(
                    '%d cuenta(s) mantienen un saldo vencido de $%s.',
                    (int) $fila->cantidad,
                    number_format((float) $fila->saldo, 2, '.', ',')
                ),
                'referencia_tipo' => 'SEDE',
                'referencia_id' => (int) $fila->sede_id,
                'vista' => 'REPORTES-CARTERA-VENCIDA',
                'contexto' => ['cantidad' => (int) $fila->cantidad, 'saldo' => round((float) $fila->saldo, 2)],
            ]);
    }

    private function detectarMembresiasPorVencer(): Collection
    {
        return DB::table('membresias.membresia_periodos as mp')
            ->join('membresias.membresias as m', 'm.id', '=', 'mp.membresia_id')
            ->whereBetween('mp.fecha_fin', [now()->toDateString(), now()->addDays(7)->toDateString()])
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('membresias.membresia_periodos as siguiente')
                    ->whereColumn('siguiente.membresia_id', 'mp.membresia_id')
                    ->whereColumn('siguiente.numero_periodo', '>', 'mp.numero_periodo');
            })
            ->whereNotIn(DB::raw("UPPER(COALESCE(m.estado, ''))"), ['ANULADA', 'CANCELADA', 'FINALIZADA'])
            ->whereNotNull('m.sede_id')
            ->groupBy('m.sede_id')
            ->select('m.sede_id')
            ->selectRaw('COUNT(*) as cantidad')
            ->get()
            ->map(fn ($fila) => [
                'clave' => 'MEMBRESIAS_POR_VENCER',
                'tipo' => 'MEMBRESIAS_POR_VENCER',
                'nivel' => 'INFO',
                'sede_id' => (int) $fila->sede_id,
                'titulo' => 'Membresías próximas a vencer',
                'mensaje' => (int) $fila->cantidad . ' membresía(s) vencen dentro de los próximos 7 días.',
                'referencia_tipo' => 'SEDE',
                'referencia_id' => (int) $fila->sede_id,
                'vista' => 'REPORTES-MEMBRESIAS-POR-VENCER',
                'contexto' => ['cantidad' => (int) $fila->cantidad],
            ]);
    }

    private function detectarConciliacionesPendientes(): Collection
    {
        return DB::table('ventas.turnos_caja')
            ->where('estado', 'CERRADA')
            ->where('requiere_arqueo', true)
            ->whereNotNull('sede_id')
            ->groupBy('sede_id')
            ->select('sede_id')
            ->selectRaw('COUNT(*) as cantidad')
            ->get()
            ->map(fn ($fila) => [
                'clave' => 'CONCILIACION_CAJA',
                'tipo' => 'CONCILIACION_CAJA',
                'nivel' => 'ERROR',
                'sede_id' => (int) $fila->sede_id,
                'titulo' => 'Conciliaciones de caja pendientes',
                'mensaje' => (int) $fila->cantidad . ' cierre(s) de caja requieren conciliación.',
                'referencia_tipo' => 'SEDE',
                'referencia_id' => (int) $fila->sede_id,
                'vista' => 'REPORTES-CONCILIACION-CAJA',
                'contexto' => ['cantidad' => (int) $fila->cantidad],
            ]);
    }

    private function detectarDiferenciasCajaHoy(): Collection
    {
        return DB::table('ventas.turnos_caja')
            ->where('estado', 'CERRADA')
            ->whereDate('fecha_cierre', now()->toDateString())
            ->whereRaw('ABS(COALESCE(diferencia, 0)) > 0.01')
            ->whereNotNull('sede_id')
            ->groupBy('sede_id')
            ->select('sede_id')
            ->selectRaw('COUNT(*) as cantidad')
            ->selectRaw('SUM(COALESCE(diferencia, 0)) as diferencia')
            ->get()
            ->map(fn ($fila) => [
                'clave' => 'DIFERENCIA_CAJA',
                'tipo' => 'DIFERENCIA_CAJA',
                'nivel' => 'WARNING',
                'sede_id' => (int) $fila->sede_id,
                'titulo' => 'Diferencias de caja detectadas',
                'mensaje' => sprintf(
                    '%d cierre(s) presentan una diferencia acumulada de $%s hoy.',
                    (int) $fila->cantidad,
                    number_format((float) $fila->diferencia, 2, '.', ',')
                ),
                'referencia_tipo' => 'SEDE',
                'referencia_id' => (int) $fila->sede_id,
                'vista' => 'REPORTES-CONCILIACION-CAJA',
                'contexto' => ['cantidad' => (int) $fila->cantidad, 'diferencia' => round((float) $fila->diferencia, 2)],
            ]);
    }

    private function registrarOActualizar(array $alerta): void
    {
        $existente = DB::table('notificaciones.alertas_operativas')
            ->where('clave', $alerta['clave'])
            ->where('sede_id', $alerta['sede_id'])
            ->where('referencia_tipo', $alerta['referencia_tipo'])
            ->where('referencia_id', $alerta['referencia_id'])
            ->first();

        $ahora = now();
        $debeNotificar = ! $existente
            || $existente->estado === 'RESUELTA'
            || ! $existente->ultima_notificacion_at
            || $ahora->diffInHours(\Carbon\Carbon::parse($existente->ultima_notificacion_at)) >= 24;

        $datos = [
            'tipo' => $alerta['tipo'],
            'nivel' => $alerta['nivel'],
            'titulo' => $alerta['titulo'],
            'mensaje' => $alerta['mensaje'],
            'vista' => $alerta['vista'],
            'estado' => 'ACTIVA',
            'contexto' => json_encode($alerta['contexto'], JSON_UNESCAPED_UNICODE),
            'ultima_deteccion_at' => $ahora,
            'resuelta_at' => null,
            'updated_at' => $ahora,
        ];

        if ($debeNotificar) {
            $datos['ultima_notificacion_at'] = $ahora;
        }

        if ($existente) {
            DB::table('notificaciones.alertas_operativas')->where('id', $existente->id)->update($datos);
            $alertaId = (int) $existente->id;
        } else {
            $alertaId = (int) DB::table('notificaciones.alertas_operativas')->insertGetId(array_merge($datos, [
                'clave' => $alerta['clave'],
                'sede_id' => $alerta['sede_id'],
                'referencia_tipo' => $alerta['referencia_tipo'],
                'referencia_id' => $alerta['referencia_id'],
                'detectada_at' => $ahora,
                'created_at' => $ahora,
            ]));
        }

        if ($debeNotificar) {
            $this->notificar($alertaId, $alerta);
        }
    }

    private function notificar(int $alertaId, array $alerta): void
    {
        foreach ($this->destinatarios((int) $alerta['sede_id']) as $usuarioId) {
            $this->avisos->registrar(
                $usuarioId,
                'ALERTA_OPERATIVA',
                $alerta['titulo'],
                $alerta['mensaje'],
                'DASHBOARD-ALERTAS',
                $alerta['tipo'],
                $alertaId,
            );
        }
    }

    private function destinatarios(int $sedeId): array
    {
        $roles = ['SUPERADMINISTRADOR', 'ADMINISTRADOR', 'SUPERVISOR DE VENTAS'];

        return DB::table('seguridad.users as u')
            ->join('seguridad.cpu_userrole as r', 'r.id_userrole', '=', 'u.usr_tipo')
            ->where('u.usr_estado', 1)
            ->where('r.activo', true)
            ->whereIn('r.role', $roles)
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
            ->distinct()
            ->pluck('u.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function resolverAusentes(array $identidadesActivas): int
    {
        $activas = DB::table('notificaciones.alertas_operativas')
            ->where('estado', 'ACTIVA')
            ->whereIn('tipo', self::TIPOS_GESTIONADOS)
            ->get();

        $resueltas = 0;

        foreach ($activas as $alerta) {
            $identidad = implode(':', [
                $alerta->clave,
                $alerta->sede_id ?? '0',
                $alerta->referencia_tipo ?? '-',
                $alerta->referencia_id ?? '0',
            ]);

            if (! in_array($identidad, $identidadesActivas, true)) {
                DB::table('notificaciones.alertas_operativas')
                    ->where('id', $alerta->id)
                    ->update([
                        'estado' => 'RESUELTA',
                        'resuelta_at' => now(),
                        'updated_at' => now(),
                    ]);

                $resueltas++;
            }
        }

        return $resueltas;
    }

    private function identidad(array $alerta): string
    {
        return implode(':', [
            $alerta['clave'],
            $alerta['sede_id'] ?? 0,
            $alerta['referencia_tipo'] ?? '-',
            $alerta['referencia_id'] ?? 0,
        ]);
    }
}
