<?php

namespace App\Http\Controllers\Personas;

use App\Http\Controllers\Controller;
use App\Queries\Ventas\VentaDebtQuery;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppDashboardController extends Controller
{
    public function __construct(private VentaDebtQuery $ventaDebtQuery)
    {
    }

    public function getDashboardSummary(Request $request)
    {
        // 1. Obtener la persona actual
        $personaId = null;
        $persona = null;
        $cedula = null;
        if ($request->user()) {
            $personaId = $request->user()->persona_id;
            $persona = DB::table('core.personas')->where('id', $personaId)->first();
            $cedula = $persona?->numero_identificacion ?? $request->user()?->cedula ?? null;
        } else {
            // Mock para entorno local si no hay token
            $persona = DB::table('core.personas')->where('nombres', 'like', '%Yandry%')->first();
            $personaId = $persona ? $persona->id : null;
            $cedula = $persona?->numero_identificacion;
        }

        // --- MÉTRICAS DE ENTRENAMIENTO (PLAN) ---
        $planActivo = null;
        if ($personaId) {
            // Buscar si tiene asignación directa
            $asignacion = DB::table('entrenamiento.plan_asignaciones')
                ->where('persona_id', $personaId)
                ->where('estado', 'ACTIVO')
                ->orderBy('id', 'desc')
                ->first();
                
            if ($asignacion) {
                $planActivo = DB::table('entrenamiento.planes')
                    ->where('id', $asignacion->plan_id)
                    ->first();
            } else {
                $planActivo = DB::table('entrenamiento.planes')
                    ->where('persona_id', $personaId)
                    ->where('estado', 'ACTIVO')
                    ->orderBy('id', 'desc')
                    ->first();
            }
        }

        // Si no tiene plan propio, ya no usamos un plan grupal de respaldo global.
        // Se respeta si no tiene plan para que la vista se adapte o se oculte.

        $planResumen = null;
        if ($planActivo) {
            // Contar días configurados para el plan activo en la nueva tabla
            $diasConfigurados = DB::table('entrenamiento.plan_dias')
                ->where('plan_id', $planActivo->id)
                ->count();
            $diaActual = $this->resolvePlanDaySummary($planActivo, $personaId, $cedula);
                
            $planResumen = [
                'id' => $planActivo->id,
                'nombre' => $planActivo->nombre,
                'diasConfigurados' => $diasConfigurados,
                'estado' => $planActivo->estado,
                'semanaActual' => $diaActual['semana'],
                'diaActual' => $diaActual['dia'],
                'diaActualLabel' => $diaActual['dia_label'],
                'ejerciciosTotal' => $diaActual['ejercicios_total'],
                'ejerciciosCompletados' => $diaActual['ejercicios_completados'],
                'porcentaje' => $diaActual['porcentaje'],
            ];
        }

        // --- MÉTRICAS DE SUSCRIPCIÓN / MEMBRESÍA ---
        $membresiaActiva = null;
        if ($personaId) {
            $socio = DB::table('socios.socios')->where('persona_id', $personaId)->first();
            if ($socio) {
                $membresiaActiva = DB::table('socios.socio_membresias as sm')
                    ->join('socios.membresias as m', 'sm.membresia_id', '=', 'm.id')
                    ->join('core.estados as est', 'sm.estado_id', '=', 'est.id')
                    ->where('sm.socio_id', $socio->id)
                    ->whereIn(DB::raw('UPPER(est.nombre)'), ['ACTIVO', 'VIGENTE'])
                    ->select('m.nombre', 'sm.fecha_fin', 'est.nombre as estado')
                    ->orderBy('sm.fecha_fin', 'desc')
                    ->first();
            }
        }

        // --- MÉTRICAS DE FACTURACIÓN (VENTAS) ---
        $ultimaFactura = null;
        if ($personaId) {
            $ultimaFactura = DB::table('ventas.ventas')
                ->where('persona_id', $personaId)
                ->orderBy('fecha', 'desc')
                ->select('id', 'fecha', 'total', 'estado', 'estado_pago', 'tipo_venta', 'referencia', 'saldo_pendiente')
                ->first();
        }

        $deudaResumen = $personaId
            ? $this->ventaDebtQuery->resumenPorPersona($personaId)
            : [
                'tiene_deuda' => false,
                'cantidad' => 0,
                'saldo_total' => 0,
                'saldo_consumo' => 0,
                'saldo_membresia' => 0,
                'items' => [],
            ];

        // --- MÉTRICAS DE RM (Récords Personales) ---
        $records = [];
        if ($personaId) {
            $records = DB::table('entrenamiento.rm_registros as rm')
                ->join('entrenamiento.ejercicios as e', 'e.id', '=', 'rm.ejercicio_id')
                ->where('rm.persona_id', $personaId)
                ->orderBy('rm.rm_estimado', 'desc')
                ->select('e.nombre as ejercicio', 'rm.rm_estimado', 'rm.fecha_registro')
                ->take(3)
                ->get();
        }

        // --- ESTADÍSTICAS SEMANALES (ACTIVIDAD) ---
        $estadisticas = [
            'diasEntrenados' => 0,
            'volumenSemanal' => 0
        ];
        
        if ($planActivo) {
            $inicioSemana = date('Y-m-d', strtotime('monday this week'));
            $finSemana = date('Y-m-d', strtotime('sunday this week'));

            $ejecucionesSemana = DB::table('entrenamiento.plan_ejecuciones')
                ->where('plan_id', $planActivo->id)
                ->whereBetween('fecha_ejecucion', [$inicioSemana, $finSemana])
                ->whereIn('estado', ['COMPLETADO', 'PARCIAL'])
                ->when($cedula, fn ($query) => $query->where('cedula', $cedula))
                ->when(!$cedula && $personaId, fn ($query) => $query->where('persona_id', $personaId))
                ->get();

            $diasUnicos = [];
            $volumenTotal = 0;

            foreach ($ejecucionesSemana as $ejecucion) {
                // Contar días únicos
                $diasUnicos[$ejecucion->fecha_ejecucion] = true;

                // Calcular volumen (carga * reps)
                if (!empty($ejecucion->repeticiones_reales)) {
                    $series = json_decode($ejecucion->repeticiones_reales, true) ?? [];
                    foreach ($series as $serie) {
                        if (isset($serie['completado']) && $serie['completado']) {
                            $carga = floatval($serie['carga'] ?? 0);
                            $reps = intval($serie['reps'] ?? $serie['repeticiones'] ?? 0);
                            $volumenTotal += ($carga * $reps);
                        }
                    }
                }
            }

            $estadisticas['diasEntrenados'] = count($diasUnicos);
            $estadisticas['volumenSemanal'] = round($volumenTotal, 1);
        }

        return response()->json([
            'message' => 'Dashboard obtenido exitosamente',
            'data' => [
                'usuario' => $persona ? $persona->nombres : 'Usuario',
                'plan' => $planResumen,
                'membresia' => $membresiaActiva,
                'factura' => $ultimaFactura,
                'deuda' => $deudaResumen,
                'records' => $records,
                'estadisticas' => $estadisticas
            ]
        ]);
    }

    private function resolvePlanDaySummary(object $plan, ?int $personaId, ?string $cedula): array
    {
        $dias = DB::table('entrenamiento.plan_dias')
            ->where('plan_id', $plan->id)
            ->select('id', 'semana', 'dia')
            ->orderBy('semana')
            ->orderByRaw($this->dayOrderSql('dia'))
            ->get();

        if ($dias->isEmpty()) {
            return [
                'semana' => 1,
                'dia' => $this->currentDayKey(),
                'dia_label' => $this->dayLabel($this->currentDayKey()),
                'ejercicios_total' => 0,
                'ejercicios_completados' => 0,
                'porcentaje' => 0,
            ];
        }

        $totalWeeks = max(1, (int) $dias->max('semana'));
        $week = $this->resolveCurrentWeek($plan->fecha_inicio ?? null, $totalWeeks);
        $today = $this->currentDayKey();
        $todayOrder = $this->dayOrder($today);
        $diasSemana = $dias->filter(fn ($dia) => (int) $dia->semana === $week)->values();

        if ($diasSemana->isEmpty()) {
            $week = (int) $dias->max('semana');
            $diasSemana = $dias->filter(fn ($dia) => (int) $dia->semana === $week)->values();
        }

        $selected = $diasSemana->first(fn ($dia) => $this->normalizeDay($dia->dia) === $today);

        if (!$selected) {
            $selected = $diasSemana
                ->filter(fn ($dia) => $this->dayOrder($dia->dia) <= $todayOrder)
                ->sortByDesc(fn ($dia) => $this->dayOrder($dia->dia))
                ->first();
        }

        $selected = $selected ?: $diasSemana->first();
        $selectedDay = $this->normalizeDay($selected->dia ?? $today);
        $selectedWeek = (int) ($selected->semana ?? $week);

        $ejercicioIds = DB::table('entrenamiento.plan_ejercicios as pe')
            ->join('entrenamiento.plan_bloques as pb', 'pb.id', '=', 'pe.plan_bloque_id')
            ->where('pb.plan_dia_id', $selected->id)
            ->pluck('pe.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $total = count($ejercicioIds);
        $completed = 0;

        if ($total > 0) {
            $query = DB::table('entrenamiento.plan_ejecuciones')
                ->where('plan_id', $plan->id)
                ->whereIn('plan_ejercicio_id', $ejercicioIds)
                ->where('semana', $selectedWeek)
                ->where('dia', $selectedDay)
                ->whereIn('estado', ['COMPLETADO', 'PARCIAL', 'COMPLETADO_CON_AJUSTE']);

            if ($cedula) {
                $query->where('cedula', $cedula);
            } elseif ($personaId) {
                $query->where('persona_id', $personaId);
            }

            $completed = (int) $query->distinct()->count('plan_ejercicio_id');
        }

        return [
            'semana' => $selectedWeek,
            'dia' => $selectedDay,
            'dia_label' => $this->dayLabel($selectedDay),
            'ejercicios_total' => $total,
            'ejercicios_completados' => min($completed, $total),
            'porcentaje' => $total > 0 ? (int) round((min($completed, $total) / $total) * 100) : 0,
        ];
    }

    private function resolveCurrentWeek(?string $planStartDate, int $totalWeeks): int
    {
        if (!$planStartDate) {
            return 1;
        }

        try {
            $start = Carbon::parse($planStartDate)->startOfDay();
            $today = Carbon::today();
            $daysElapsed = (int) max(0, $start->diffInDays($today, false));
            $currentWeek = intdiv($daysElapsed, 7) + 1;
            return min(max(1, $currentWeek), max(1, $totalWeeks));
        } catch (\Throwable) {
            return 1;
        }
    }

    private function currentDayKey(): string
    {
        $days = ["domingo", "lunes", "martes", "miercoles", "jueves", "viernes", "sabado"];
        return $days[(int) date('w')];
    }

    private function normalizeDay(?string $day): string
    {
        $value = strtolower(trim((string) $day));
        return str_replace(['miércoles', 'sábado'], ['miercoles', 'sabado'], $value);
    }

    private function dayLabel(?string $day): string
    {
        return [
            'lunes' => 'Lunes',
            'martes' => 'Martes',
            'miercoles' => 'Miercoles',
            'jueves' => 'Jueves',
            'viernes' => 'Viernes',
            'sabado' => 'Sabado',
            'domingo' => 'Domingo',
        ][$this->normalizeDay($day)] ?? 'Dia';
    }

    private function dayOrder(?string $day): int
    {
        return [
            'lunes' => 1,
            'martes' => 2,
            'miercoles' => 3,
            'jueves' => 4,
            'viernes' => 5,
            'sabado' => 6,
            'domingo' => 7,
        ][$this->normalizeDay($day)] ?? 8;
    }

    private function dayOrderSql(string $column): string
    {
        return "
            CASE LOWER({$column})
                WHEN 'lunes' THEN 1
                WHEN 'martes' THEN 2
                WHEN 'miercoles' THEN 3
                WHEN 'miércoles' THEN 3
                WHEN 'jueves' THEN 4
                WHEN 'viernes' THEN 5
                WHEN 'sabado' THEN 6
                WHEN 'sábado' THEN 6
                WHEN 'domingo' THEN 7
                ELSE 8
            END
        ";
    }
}
