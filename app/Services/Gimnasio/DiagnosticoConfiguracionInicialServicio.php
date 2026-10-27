<?php

namespace App\Services\Gimnasio;

use Illuminate\Support\Facades\DB;

class DiagnosticoConfiguracionInicialServicio
{
    public function ejecutar(): array
    {
        return [
            'planes_activos_sin_servicios' => DB::table('membresias.planes as p')
                ->where('p.activo', true)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('membresias.plan_servicios as ps')->whereColumn('ps.plan_id', 'p.id')->where('ps.activo', true))
                ->count(),
            'entrenadores_activos_sin_servicios' => DB::table('entrenamiento.entrenadores as e')
                ->where('e.estado', 'ACTIVO')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('entrenamiento.entrenador_servicios as es')->whereColumn('es.entrenador_id', 'e.id')->where('es.activo', true))
                ->count(),
            'entrenadores_activos_sin_horario' => DB::table('entrenamiento.entrenadores as e')
                ->where('e.estado', 'ACTIVO')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('agenda.entrenador_horarios as eh')->whereColumn('eh.entrenador_id', 'e.id')->where('eh.activo', true))
                ->count(),
            'membresias_sin_ficha_requerida' => DB::table('membresias.membresias as m')
                ->join('membresias.planes as p', 'p.id', '=', 'm.plan_id')
                ->where('p.requiere_ficha_inicial', true)
                ->whereNotIn('m.estado', ['CANCELADA', 'VENCIDA'])
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('clientes.fichas_iniciales as f')->whereColumn('f.deportista_id', 'm.deportista_id'))
                ->count(),
            'membresias_pendientes_sin_venta' => DB::table('membresias.membresias as m')
                ->join('membresias.planes as p', 'p.id', '=', 'm.plan_id')
                ->where('m.estado', 'PENDIENTE_PAGO')
                ->where('p.generar_venta', true)
                ->where('p.requiere_pago', true)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ventas.ventas as v')->whereColumn('v.membresia_id', 'm.id')->where('v.estado', '!=', 'ANULADA'))
                ->count(),
            'membresias_activas_sin_pago' => DB::table('membresias.membresias as m')
                ->join('membresias.planes as p', 'p.id', '=', 'm.plan_id')
                ->where('m.estado', 'ACTIVA')
                ->where('p.requiere_pago', true)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ventas.ventas as v')->whereColumn('v.membresia_id', 'm.id')->where('v.estado', 'PAGADA'))
                ->count(),
            'asignaciones_activas_sin_membresia' => DB::table('entrenamiento.asignaciones_entrenador_cliente')
                ->where('estado', 'ACTIVO')
                ->whereNull('membresia_id')
                ->count(),
        ];
    }
}
