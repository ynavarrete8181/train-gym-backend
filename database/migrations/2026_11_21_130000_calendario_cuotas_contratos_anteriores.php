<?php

use App\Services\Auditoria\AuditoriaServicio;
use App\Services\Gimnasio\ContratoCuotasServicio;
use App\Services\Gimnasio\EmisionCuotasServicio;
use App\Services\Gimnasio\HistorialComercialMembresiaServicio;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los contratos creados antes del calendario de cuotas (MEMB-000002 a 000008) se pasan al
 * mismo modelo que el resto, para que se renueven y se administren igual:
 *  - períodos contratados = los períodos que ya tienen, con una cuota por período enlazada
 *    a su venta (las ventas y pagos no cambian);
 *  - día de pago = el registrado o, si falta, el día de inicio;
 *  - condiciones de la contratación en el historial;
 *  - deja de usarse el cobro programado anterior (la renovación sigue la regla general).
 * Las cuotas sin venta (Ezequiel y Daniela Palma) se emiten con el descuento vigente,
 * como lo hace el sistema cada noche. Un contrato que ya tenga calendario no se toca.
 */
return new class extends Migration
{
    private const CONTRATOS = ['MEMB-000002', 'MEMB-000003', 'MEMB-000004', 'MEMB-000005',
        'MEMB-000006', 'MEMB-000007', 'MEMB-000008'];

    public function up(): void
    {
        DB::transaction(function (): void {
            $auditoria = app(AuditoriaServicio::class);
            $historial = app(HistorialComercialMembresiaServicio::class);

            foreach (self::CONTRATOS as $codigo) {
                $m = DB::table('membresias.membresias')->where('codigo_contrato', $codigo)->lockForUpdate()->first();
                if (! $m || $m->cantidad_periodos_contratados !== null
                    || ! in_array($m->estado, ['ACTIVA', 'PENDIENTE_PAGO'], true)
                    || DB::table('membresias.membresia_cuotas')->where('membresia_id', $m->id)->exists()) {
                    continue;
                }
                $plan = DB::table('membresias.planes')->where('id', $m->plan_id)->first();
                if (! $plan || $plan->tipo_producto === 'PASE_DIARIO') {
                    continue;
                }
                $modalidad = $m->modalidad_id ? DB::table('membresias.plan_modalidades')->where('id', $m->modalidad_id)->first() : null;
                $periodos = DB::table('membresias.membresia_periodos')->where('membresia_id', $m->id)
                    ->where('estado', '!=', 'CANCELADA')->orderBy('numero_periodo')->get();
                if ($periodos->isEmpty()) {
                    continue;
                }

                $inicio = substr((string) $periodos->first()->fecha_inicio, 0, 10);
                $fin = substr((string) $periodos->last()->fecha_fin, 0, 10);
                $diaPago = (int) ($m->dia_pago ?: Carbon::parse($inicio)->day);
                $primerPago = ContratoCuotasServicio::primeraFechaPagoPorDia($inicio, $diaPago);
                $precio = round((float) $periodos->last()->precio, 2);

                foreach ($periodos->values() as $i => $p) {
                    $venta = $p->venta_id ? DB::table('ventas.ventas')->where('id', $p->venta_id)->first() : null;
                    $vence = ContratoCuotasServicio::vencimientoMensual($primerPago, $i, $diaPago);
                    $finPeriodo = substr((string) $p->fecha_fin, 0, 10);
                    DB::table('membresias.membresia_cuotas')->insert([
                        'membresia_id' => $m->id,
                        'periodo_id' => $p->id,
                        'numero_cuota' => $i + 1,
                        'monto' => round((float) $p->precio, 2),
                        'fecha_programada_emision' => substr((string) $p->fecha_inicio, 0, 10),
                        'fecha_vencimiento' => $vence <= $finPeriodo ? $vence : $finPeriodo,
                        'estado' => $venta ? 'EMITIDA' : 'PROGRAMADA',
                        'venta_id' => $venta?->id,
                        'emitida_at' => $venta?->created_at,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $servicios = DB::table('membresias.plan_servicios as ps')
                    ->join('servicios.servicios as s', 's.id', '=', 'ps.servicio_id')
                    ->where('ps.plan_id', $m->plan_id)->where('ps.activo', true)->orderBy('s.id')
                    ->get(['s.id', 's.nombre'])->map(fn ($s) => ['id' => (int) $s->id, 'nombre' => (string) $s->nombre])->all();
                $tipo = $modalidad?->tipo_duracion ?? $plan->tipo_duracion;
                $duracion = (int) ($modalidad?->duracion ?? $plan->duracion);
                $condiciones = [
                    'version' => 1,
                    'servicios_incluidos' => $servicios,
                    'capturado_at' => now()->toIso8601String(),
                    'plan_id' => (int) $m->plan_id,
                    'plan_nombre' => (string) $plan->nombre,
                    'modalidad_id' => $modalidad?->id ? (int) $modalidad->id : null,
                    'modalidad_nombre' => $modalidad?->nombre,
                    'tipo_producto' => $plan->tipo_producto,
                    'requiere_pago' => (bool) ($plan->requiere_pago ?? true),
                    'requiere_entrenador' => (bool) ($plan->requiere_entrenador ?? false),
                    'renovable' => (bool) ($plan->renovable ?? false),
                    'modelo_cobro_modalidad' => $modalidad?->modelo_cobro,
                    'momento_cobro_modalidad' => $modalidad?->momento_cobro,
                    'sede_id' => (int) $m->sede_id,
                    'tipo_duracion' => $tipo,
                    'duracion' => $duracion,
                    'dias_por_semana' => $modalidad?->dias_por_semana,
                    'usos_por_semana' => $modalidad?->usos_por_semana,
                    'uso_ilimitado' => $modalidad?->uso_ilimitado,
                    'precio_periodo' => number_format($precio, 2, '.', ''),
                    'cantidad_periodos' => $periodos->count(),
                    'total_bruto_referencial' => number_format(round($precio * $periodos->count(), 2), 2, '.', ''),
                    'fecha_inicio' => $inicio,
                    'fecha_primer_pago' => $primerPago,
                    'dia_pago' => $diaPago,
                    'programacion_vencimientos' => 'DIA_FIJO_MENSUAL',
                ];

                $antes = (array) $m;
                DB::table('membresias.membresias')->where('id', $m->id)->update([
                    'fecha_inicio' => $inicio,
                    'fecha_fin' => $fin,
                    'cantidad_periodos_contratados' => $periodos->count(),
                    'dia_pago' => $diaPago,
                    'condiciones_contratadas' => json_encode($condiciones, JSON_THROW_ON_ERROR),
                    'generar_venta_automatica' => false,
                    'proxima_fecha_cobro' => null,
                    'updated_at' => now(),
                ]);
                $historial->registrarContrato($m->id, 'CONTRATACION', $condiciones + ['fecha_fin' => $fin],
                    'Condiciones originales de la contratación.');
                $auditoria->registrar([
                    'modulo' => 'membresias', 'tabla' => 'membresias.membresias', 'accion' => 'FIJAR_CONDICIONES_CONTRATADAS',
                    'registro_id' => $m->id,
                    'datos_antes' => array_intersect_key($antes, array_flip(['fecha_inicio', 'fecha_fin', 'cantidad_periodos_contratados',
                        'dia_pago', 'generar_venta_automatica', 'proxima_fecha_cobro'])),
                    'datos_despues' => ['fecha_inicio' => $inicio, 'fecha_fin' => $fin, 'cantidad_periodos_contratados' => $periodos->count(),
                        'dia_pago' => $diaPago, 'generar_venta_automatica' => false, 'proxima_fecha_cobro' => null],
                    'descripcion' => mb_substr("{$codigo}: calendario de {$periodos->count()} cuota(s), pago el día {$diaPago} de cada mes.", 0, 255),
                ]);
            }

            // Las cuotas que quedaron sin venta se emiten como cada noche, con su descuento.
            app(EmisionCuotasServicio::class)->procesar();
        });
    }

    public function down(): void
    {
        // Corrección de datos: se revierte con el respaldo previo, no automáticamente.
    }
};
