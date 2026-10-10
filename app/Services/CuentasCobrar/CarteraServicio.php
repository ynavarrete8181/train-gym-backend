<?php

namespace App\Services\CuentasCobrar;

use App\Services\Concerns\RegistraAuditoria;
use App\Services\Seguridad\AlcanceOperativoService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CarteraServicio
{
    use RegistraAuditoria;

    public function __construct(private readonly AlcanceOperativoService $alcance)
    {
    }

    public function listar(array $filtros, ?int $usuarioId = null)
    {
        $query = $this->consultaBase($usuarioId);

        if (! empty($filtros['busqueda'])) {
            $texto = '%' . mb_strtolower((string) $filtros['busqueda']) . '%';
            $query->where(function ($q) use ($texto): void {
                $q->whereRaw('LOWER(v.numero) LIKE ?', [$texto])
                    ->orWhereRaw("LOWER(COALESCE(NULLIF(TRIM(p.nombre_completo), ''), NULLIF(TRIM(u.name), ''), '')) LIKE ?", [$texto])
                    ->orWhereRaw("LOWER(COALESCE(NULLIF(TRIM(p.identificacion), ''), NULLIF(TRIM(u.cedula), '')) LIKE ?", [$texto])
                    ->orWhereRaw("LOWER(COALESCE(pl.nombre, '')) LIKE ?", [$texto])
                    ->orWhereRaw("LOWER(COALESCE(resp.name, '')) LIKE ?", [$texto]);
            });
        }

        if (! empty($filtros['estado'])) {
            $estados = is_array($filtros['estado']) ? $filtros['estado'] : [$filtros['estado']];
            $query->where(function ($q) use ($estados): void {
                foreach ($estados as $estado) {
                    $q->orWhereRaw($this->expresionEstadoCartera() . ' = ?', [mb_strtoupper((string) $estado)]);
                }
            });
        }

        if (! empty($filtros['responsable_id'])) {
            $query->where('cc.responsable_id', (int) $filtros['responsable_id']);
        }

        if (! empty($filtros['prioridad'])) {
            $query->where('cc.prioridad', mb_strtoupper((string) $filtros['prioridad']));
        }

        if (! empty($filtros['desde'])) {
            $query->whereDate('cc.fecha_vencimiento', '>=', $filtros['desde']);
        }

        if (! empty($filtros['hasta'])) {
            $query->whereDate('cc.fecha_vencimiento', '<=', $filtros['hasta']);
        }

        return $query
            ->orderByRaw("CASE WHEN {$this->expresionEstadoCartera()} = 'VENCIDA' THEN 0 ELSE 1 END")
            ->orderBy('cc.fecha_vencimiento')
            ->orderByDesc('cc.id')
            ->paginate($filtros['per_page'] ?? 10, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function resumen(?int $usuarioId = null): array
    {
        $base = $this->consultaBase($usuarioId);

        $filas = (clone $base)->get();

        return [
            'cuentas_abiertas' => $filas->whereIn('estado_cartera', ['PENDIENTE', 'PARCIAL', 'VENCIDA'])->count(),
            'vencidas' => $filas->where('estado_cartera', 'VENCIDA')->count(),
            'saldo_total' => round((float) $filas->whereIn('estado_cartera', ['PENDIENTE', 'PARCIAL', 'VENCIDA'])->sum('saldo_pendiente'), 2),
            'saldo_vencido' => round((float) $filas->where('estado_cartera', 'VENCIDA')->sum('saldo_pendiente'), 2),
            'compromisos_pendientes' => DB::table('cuentas_cobrar.compromisos_pago as cp')
                ->join('cuentas_cobrar.cuentas as cc', 'cc.id', '=', 'cp.cuenta_id')
                ->join('ventas.ventas as v', 'v.id', '=', 'cc.venta_id')
                ->leftJoin('ventas.cajas as caja', 'caja.id', '=', 'v.caja_id')
                ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
                ->where('cp.estado', 'PENDIENTE')
                ->whereIn(DB::raw('COALESCE(caja.sede_id, m.sede_id)'), $this->alcance->sedesPermitidas($usuarioId, 'maneja_caja'))
                ->count(),
        ];
    }

    public function detalle(int $id, ?int $usuarioId = null): object
    {
        $cuenta = $this->consultaBase($usuarioId)->where('cc.id', $id)->first();

        if (! $cuenta) {
            throw ValidationException::withMessages([
                'cuenta_id' => 'La cuenta por cobrar no existe o no pertenece a una sede autorizada.',
            ]);
        }

        $cuenta->gestiones = DB::table('cuentas_cobrar.gestiones as g')
            ->leftJoin('seguridad.users as u', 'u.id', '=', 'g.usuario_id')
            ->where('g.cuenta_id', $id)
            ->orderByDesc('g.gestion_at')
            ->get([
                'g.id',
                'g.tipo',
                'g.resultado',
                'g.detalle',
                'g.gestion_at',
                'g.proxima_gestion_at',
                'g.usuario_id',
                'u.name as usuario_nombre',
            ]);

        $cuenta->compromisos = DB::table('cuentas_cobrar.compromisos_pago as cp')
            ->leftJoin('seguridad.users as u', 'u.id', '=', 'cp.creado_por')
            ->leftJoin('ventas.pagos as pg', 'pg.id', '=', 'cp.pago_id')
            ->where('cp.cuenta_id', $id)
            ->orderByDesc('cp.fecha_compromiso')
            ->orderByDesc('cp.id')
            ->get([
                'cp.*',
                'u.name as creado_por_nombre',
                'pg.codigo_cobro',
            ]);

        return $cuenta;
    }

    public function actualizar(int $id, array $datos, int $usuarioId): object
    {
        $cuenta = $this->detalle($id, $usuarioId);
        $antes = DB::table('cuentas_cobrar.cuentas')->where('id', $id)->first();

        $actualizar = [];

        if (array_key_exists('fecha_vencimiento', $datos)) {
            $actualizar['fecha_vencimiento'] = $datos['fecha_vencimiento'];
        }
        if (array_key_exists('prioridad', $datos)) {
            $actualizar['prioridad'] = mb_strtoupper((string) $datos['prioridad']);
        }
        if (array_key_exists('responsable_id', $datos)) {
            $actualizar['responsable_id'] = $datos['responsable_id'] ?: null;
        }
        if (array_key_exists('proxima_gestion_at', $datos)) {
            $actualizar['proxima_gestion_at'] = $datos['proxima_gestion_at'] ?: null;
        }
        if (array_key_exists('observaciones', $datos)) {
            $actualizar['observaciones'] = $datos['observaciones'];
        }

        if (! empty($actualizar)) {
            $actualizar['updated_at'] = now();
            DB::table('cuentas_cobrar.cuentas')->where('id', $id)->update($actualizar);
        }

        $despues = DB::table('cuentas_cobrar.cuentas')->where('id', $id)->first();
        $this->auditar('cuentas_cobrar', 'ACTUALIZAR', 'cuentas_cobrar.cuentas', $id, $antes, $despues, 'Actualización de gestión de cartera.');

        return $this->detalle($id, $usuarioId);
    }

    public function registrarGestion(int $cuentaId, array $datos, int $usuarioId): object
    {
        $this->detalle($cuentaId, $usuarioId);

        return DB::transaction(function () use ($cuentaId, $datos, $usuarioId): object {
            $gestionAt = $datos['gestion_at'] ?? now();

            $id = DB::table('cuentas_cobrar.gestiones')->insertGetId([
                'cuenta_id' => $cuentaId,
                'usuario_id' => $usuarioId,
                'tipo' => mb_strtoupper((string) $datos['tipo']),
                'resultado' => ! empty($datos['resultado']) ? mb_strtoupper((string) $datos['resultado']) : null,
                'detalle' => $datos['detalle'],
                'gestion_at' => $gestionAt,
                'proxima_gestion_at' => $datos['proxima_gestion_at'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('cuentas_cobrar.cuentas')->where('id', $cuentaId)->update([
                'ultima_gestion_at' => $gestionAt,
                'proxima_gestion_at' => $datos['proxima_gestion_at'] ?? null,
                'updated_at' => now(),
            ]);

            $gestion = DB::table('cuentas_cobrar.gestiones')->where('id', $id)->first();
            $this->auditar('cuentas_cobrar', 'GESTIONAR', 'cuentas_cobrar.gestiones', $id, null, $gestion, 'Gestión registrada sobre cuenta por cobrar.');

            return $gestion;
        });
    }

    public function registrarCompromiso(int $cuentaId, array $datos, int $usuarioId): object
    {
        $cuenta = $this->detalle($cuentaId, $usuarioId);
        $monto = round((float) $datos['monto'], 2);

        if ($monto > (float) $cuenta->saldo_pendiente + 0.00001) {
            throw ValidationException::withMessages([
                'monto' => 'El compromiso no puede superar el saldo pendiente de la cuenta.',
            ]);
        }

        $id = DB::table('cuentas_cobrar.compromisos_pago')->insertGetId([
            'cuenta_id' => $cuentaId,
            'creado_por' => $usuarioId,
            'monto' => $monto,
            'fecha_compromiso' => $datos['fecha_compromiso'],
            'estado' => 'PENDIENTE',
            'observaciones' => $datos['observaciones'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $compromiso = DB::table('cuentas_cobrar.compromisos_pago')->where('id', $id)->first();
        $this->auditar('cuentas_cobrar', 'CREAR_COMPROMISO', 'cuentas_cobrar.compromisos_pago', $id, null, $compromiso, 'Compromiso de pago registrado.');

        return $compromiso;
    }

    public function actualizarCompromiso(int $id, string $estado, int $usuarioId): object
    {
        $compromiso = DB::table('cuentas_cobrar.compromisos_pago')->where('id', $id)->first();
        if (! $compromiso) {
            throw ValidationException::withMessages(['compromiso_id' => 'El compromiso no existe.']);
        }

        $this->detalle((int) $compromiso->cuenta_id, $usuarioId);

        $estado = mb_strtoupper($estado);
        $antes = $compromiso;

        DB::table('cuentas_cobrar.compromisos_pago')->where('id', $id)->update([
            'estado' => $estado,
            'cumplido_at' => $estado === 'CUMPLIDO' ? now() : null,
            'updated_at' => now(),
        ]);

        $despues = DB::table('cuentas_cobrar.compromisos_pago')->where('id', $id)->first();
        $this->auditar('cuentas_cobrar', 'ACTUALIZAR_COMPROMISO', 'cuentas_cobrar.compromisos_pago', $id, $antes, $despues);

        return $despues;
    }

    public function sincronizarVenta(int $ventaId): void
    {
        $venta = DB::table('ventas.ventas')->where('id', $ventaId)->first();
        if (! $venta) {
            return;
        }

        $existente = DB::table('cuentas_cobrar.cuentas')->where('venta_id', $ventaId)->first();
        $pagado = round((float) DB::table('ventas.pagos')
            ->where('venta_id', $ventaId)
            ->where('estado', 'CONFIRMADO')
            ->sum('monto'), 2);
        $saldo = max(0, round((float) $venta->total - $pagado, 2));
        $estadoVenta = mb_strtoupper((string) $venta->estado);

        if ($estadoVenta === 'ANULADA') {
            if ($existente) {
                DB::table('cuentas_cobrar.cuentas')->where('id', $existente->id)->update([
                    'estado' => 'ANULADA',
                    'cerrada_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            return;
        }

        if ($saldo <= 0 || $estadoVenta === 'PAGADA') {
            if ($existente) {
                DB::table('cuentas_cobrar.cuentas')->where('id', $existente->id)->update([
                    'estado' => 'PAGADA',
                    'cerrada_at' => $existente->cerrada_at ?: now(),
                    'updated_at' => now(),
                ]);
                $this->cumplirCompromisosPorPago((int) $existente->id, $ventaId);
            }
            return;
        }

        if (! in_array($estadoVenta, ['PENDIENTE', 'PARCIAL'], true)) {
            return;
        }

        if ($existente) {
            DB::table('cuentas_cobrar.cuentas')->where('id', $existente->id)->update([
                'estado' => 'ABIERTA',
                'cerrada_at' => null,
                'updated_at' => now(),
            ]);
            return;
        }

        DB::table('cuentas_cobrar.cuentas')->insert([
            'venta_id' => $ventaId,
            'fecha_vencimiento' => $this->resolverFechaVencimiento($ventaId, $venta),
            'estado' => 'ABIERTA',
            'prioridad' => 'NORMAL',
            'responsable_id' => $venta->responsable_comercial_id ?: null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function consultaBase(?int $usuarioId)
    {
        $pagadoSql = "(SELECT COALESCE(SUM(pg.monto), 0) FROM ventas.pagos pg WHERE pg.venta_id = v.id AND pg.estado = 'CONFIRMADO')";
        $saldoSql = "GREATEST(v.total - {$pagadoSql}, 0)";
        $estadoSql = $this->expresionEstadoCartera($pagadoSql, $saldoSql);

        $query = DB::table('cuentas_cobrar.cuentas as cc')
            ->join('ventas.ventas as v', 'v.id', '=', 'cc.venta_id')
            ->leftJoin('clientes.deportistas as d', 'd.id', '=', 'v.cliente_id')
            ->leftJoin('personas.personas as p', 'p.id', '=', 'd.persona_id')
            ->leftJoin('seguridad.users as u', 'u.id', '=', 'd.usuario_id')
            ->leftJoin('ventas.cajas as caja', 'caja.id', '=', 'v.caja_id')
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->leftJoin('membresias.planes as pl', 'pl.id', '=', 'm.plan_id')
            ->leftJoin('membresias.plan_modalidades as pm', 'pm.id', '=', 'm.modalidad_id')
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', DB::raw('COALESCE(caja.sede_id, m.sede_id)'))
            ->leftJoin('seguridad.users as resp', 'resp.id', '=', 'cc.responsable_id')
            ->select(
                'cc.*',
                'v.numero as venta_numero',
                'v.tipo_venta',
                'v.concepto',
                'v.total as venta_total',
                'v.fecha_venta',
                'v.estado as venta_estado',
                'v.cliente_id',
                'v.membresia_id',
                's.id_sede as sede_id',
                's.nombre as sede_nombre',
                'pl.nombre as plan_nombre',
                'pm.nombre as modalidad_nombre',
                'resp.name as responsable_nombre',
                DB::raw("COALESCE(NULLIF(TRIM(p.nombre_completo), ''), NULLIF(TRIM(u.name), ''), 'Consumidor final') as cliente_nombre"),
                DB::raw("COALESCE(NULLIF(TRIM(p.identificacion), ''), NULLIF(TRIM(u.cedula), '')) as cliente_identificacion"),
                DB::raw("COALESCE(NULLIF(TRIM(p.email), ''), NULLIF(TRIM(u.email), '')) as cliente_email"),
                DB::raw("{$pagadoSql} as total_pagado"),
                DB::raw("{$saldoSql} as saldo_pendiente"),
                DB::raw("{$estadoSql} as estado_cartera"),
                DB::raw("CASE WHEN cc.estado = 'ABIERTA' AND cc.fecha_vencimiento < CURRENT_DATE AND {$saldoSql} > 0 THEN (CURRENT_DATE - cc.fecha_vencimiento) ELSE 0 END as dias_vencidos"),
                DB::raw("(SELECT MAX(g.gestion_at) FROM cuentas_cobrar.gestiones g WHERE g.cuenta_id = cc.id) as ultima_gestion_real"),
                DB::raw("(SELECT MIN(cp.fecha_compromiso) FROM cuentas_cobrar.compromisos_pago cp WHERE cp.cuenta_id = cc.id AND cp.estado = 'PENDIENTE') as proximo_compromiso_fecha"),
                DB::raw("(SELECT SUM(cp.monto) FROM cuentas_cobrar.compromisos_pago cp WHERE cp.cuenta_id = cc.id AND cp.estado = 'PENDIENTE') as compromisos_pendientes_monto")
            );

        $sedes = $this->alcance->sedesPermitidas($usuarioId, 'maneja_caja');
        if (empty($sedes)) {
            $query->whereRaw('1 = 0');
        } else {
            $query->whereIn(DB::raw('COALESCE(caja.sede_id, m.sede_id)'), $sedes);
        }

        return $query;
    }

    private function expresionEstadoCartera(?string $pagadoSql = null, ?string $saldoSql = null): string
    {
        $pagadoSql ??= "(SELECT COALESCE(SUM(pg.monto), 0) FROM ventas.pagos pg WHERE pg.venta_id = v.id AND pg.estado = 'CONFIRMADO')";
        $saldoSql ??= "GREATEST(v.total - {$pagadoSql}, 0)";

        return "CASE
            WHEN cc.estado = 'ANULADA' OR v.estado = 'ANULADA' THEN 'ANULADA'
            WHEN cc.estado = 'PAGADA' OR {$saldoSql} <= 0 OR v.estado = 'PAGADA' THEN 'PAGADA'
            WHEN cc.fecha_vencimiento < CURRENT_DATE THEN 'VENCIDA'
            WHEN {$pagadoSql} > 0 THEN 'PARCIAL'
            ELSE 'PENDIENTE'
        END";
    }

    private function resolverFechaVencimiento(int $ventaId, object $venta): string
    {
        if ($venta->membresia_id) {
            $datos = DB::table('membresias.membresias as m')
                ->leftJoin('membresias.membresia_periodos as mp', function ($join) use ($ventaId): void {
                    $join->on('mp.membresia_id', '=', 'm.id')
                        ->where('mp.venta_id', '=', $ventaId);
                })
                ->where('m.id', $venta->membresia_id)
                ->select('m.fecha_inicio', 'm.dias_gracia', 'mp.fecha_inicio as periodo_inicio')
                ->first();

            if ($datos) {
                return Carbon::parse($datos->periodo_inicio ?: $datos->fecha_inicio)
                    ->addDays((int) ($datos->dias_gracia ?? 0))
                    ->toDateString();
            }
        }

        return Carbon::parse($venta->fecha_venta)->toDateString();
    }

    private function cumplirCompromisosPorPago(int $cuentaId, int $ventaId): void
    {
        $ultimoPagoId = DB::table('ventas.pagos')
            ->where('venta_id', $ventaId)
            ->where('estado', 'CONFIRMADO')
            ->orderByDesc('fecha_pago')
            ->orderByDesc('id')
            ->value('id');

        DB::table('cuentas_cobrar.compromisos_pago')
            ->where('cuenta_id', $cuentaId)
            ->where('estado', 'PENDIENTE')
            ->whereDate('fecha_compromiso', '<=', now()->toDateString())
            ->update([
                'estado' => 'CUMPLIDO',
                'pago_id' => $ultimoPagoId,
                'cumplido_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
