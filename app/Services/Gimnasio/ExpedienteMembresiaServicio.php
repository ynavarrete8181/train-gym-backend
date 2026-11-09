<?php

namespace App\Services\Gimnasio;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Lectura integral del contrato sin duplicar reglas de otros módulos. */
final class ExpedienteMembresiaServicio
{
    public function consultar(int $membresiaId): array
    {
        $membresia = DB::table('membresias.membresias as m')
            ->leftJoin('membresias.planes as p', 'p.id', '=', 'm.plan_id')
            ->leftJoin('membresias.plan_modalidades as pm', 'pm.id', '=', 'm.modalidad_id')
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', 'm.sede_id')
            ->where('m.id', $membresiaId)
            ->first(['m.*', 'p.nombre as plan_nombre', 'pm.nombre as modalidad_nombre', 's.nombre as sede_nombre']);
        if (! $membresia) {
            throw ValidationException::withMessages(['membresia_id' => 'La membresía no existe.']);
        }

        $periodos = DB::table('membresias.membresia_periodos')->where('membresia_id', $membresiaId)
            ->orderBy('numero_periodo')->get();
        $cuotas = DB::table('membresias.membresia_cuotas as c')
            ->leftJoin('ventas.ventas as v', 'v.id', '=', 'c.venta_id')
            ->where('c.membresia_id', $membresiaId)->orderBy('c.numero_cuota')
            ->get(['c.*', 'v.numero as venta_numero', 'v.estado as venta_estado']);
        $ventas = DB::table('ventas.ventas as v')->where('v.membresia_id', $membresiaId)
            ->orderByDesc('v.fecha_venta')->get(['v.id', 'v.numero', 'v.concepto', 'v.total', 'v.estado', 'v.fecha_venta']);
        $ventaIds = $ventas->pluck('id');
        $pagos = $ventaIds->isEmpty() ? collect() : DB::table('ventas.pagos as p')->whereIn('p.venta_id', $ventaIds)
            ->orderByDesc('p.fecha_pago')->get(['p.id', 'p.venta_id', 'p.numero_comprobante', 'p.metodo_pago',
                'p.monto', 'p.estado', 'p.fecha_pago', 'p.referencia']);
        $comprobantes = $ventaIds->isEmpty() ? collect() : DB::table('ventas.comprobantes as c')->whereIn('c.venta_id', $ventaIds)
            ->orderByDesc('c.emitido_at')->get(['c.id', 'c.venta_id', 'c.pago_id', 'c.tipo_comprobante', 'c.numero',
                'c.estado', 'c.total', 'c.emitido_at', 'c.correo_destino', 'c.enviado_at']);

        // reservas_dia no tiene membresia_id. El criterio se devuelve de forma
        // explícita para no presentar como vínculo una inferencia silenciosa.
        $reservas = DB::table('agenda.reservas_dia as r')
            ->leftJoin('agenda.servicios as sv', 'sv.id', '=', 'r.servicio_id')
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', 'r.sede_id')
            ->where('r.cliente_id', $membresia->deportista_id)
            ->whereBetween('r.fecha', [$membresia->fecha_inicio, $membresia->fecha_fin])
            ->orderByDesc('r.fecha')->orderByDesc('r.hora_inicio')
            ->get(['r.id', 'r.fecha', 'r.hora_inicio', 'r.hora_fin', 'r.estado',
                'sv.nombre as servicio_nombre', 's.nombre as sede_nombre']);
        $accesos = DB::table('acceso.asistencias as a')
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', 'a.sede_id')
            ->where('a.membresia_id', $membresiaId)->orderByDesc('a.fecha_hora')
            ->get(['a.id', 'a.tipo', 'a.metodo', 'a.estado', 'a.fecha_hora', 'a.observaciones', 's.nombre as sede_nombre']);
        $entrenadores = DB::table('entrenamiento.asignaciones_entrenador_cliente as a')
            ->join('entrenamiento.entrenadores as e', 'e.id', '=', 'a.entrenador_id')
            ->leftJoin('seguridad.users as u', 'u.id', '=', 'e.usuario_id')
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', 'a.sede_id')
            ->where('a.membresia_id', $membresiaId)->orderByDesc('a.fecha_inicio')
            ->get(['a.id', 'a.tipo_asignacion', 'a.fecha_inicio', 'a.fecha_fin', 'a.estado', 'a.observaciones',
                'u.name as entrenador_nombre', 'e.especialidad', 's.nombre as sede_nombre']);

        $pagado = (float) $pagos->where('estado', 'CONFIRMADO')->sum('monto');
        $facturado = (float) $ventas->where('estado', '!=', 'ANULADA')->sum('total');
        return [
            'contrato' => $membresia,
            'resumen_financiero' => ['facturado' => $facturado, 'pagado' => $pagado,
                'saldo' => max(0, round($facturado - $pagado, 2)),
                'cuotas_programadas' => $cuotas->where('estado', 'PROGRAMADA')->count()],
            'periodos' => $periodos, 'cuotas' => $cuotas, 'ventas' => $ventas,
            'pagos' => $pagos, 'comprobantes' => $comprobantes,
            'reservas' => $reservas, 'reservas_criterio' => 'DEPORTISTA_DENTRO_VIGENCIA',
            'accesos' => $accesos, 'entrenadores' => $entrenadores,
        ];
    }
}
