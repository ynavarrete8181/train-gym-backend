<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MiPortalController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $deportista = DB::table('clientes.deportistas')->where('usuario_id', $request->user()->id)->first(['id']);
        if (! $deportista) {
            return ApiResponse::exito('Portal personal consultado.', $this->vacio());
        }

        $id = (int) $deportista->id;
        $asistencias = DB::table('acceso.asistencias as a')
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', 'a.sede_id')
            ->where('a.cliente_id', $id)->orderByDesc('a.fecha_hora')
            ->get(['a.id', 'a.fecha_hora', 'a.tipo', 'a.metodo', 'a.estado', 'a.observaciones', 's.nombre as sede']);

        $reservas = DB::table('agenda.reservas_dia as r')
            ->join('gimnasio.servicios as sv', 'sv.id', '=', 'r.servicio_id')
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', 'r.sede_id')
            ->where('r.cliente_id', $id)->orderBy('r.fecha')->orderBy('r.hora_inicio')
            ->get(['r.id', 'r.fecha', 'r.hora_inicio', 'r.hora_fin', 'r.estado', 'sv.nombre as titulo', 's.nombre as sede'])
            ->map(fn ($r): array => array_merge((array) $r, ['tipo' => 'RESERVA']));
        $sesiones = DB::table('entrenamiento.sesiones as se')
            ->where('se.cliente_id', $id)->orderBy('se.fecha_programada')
            ->get(['se.id', 'se.fecha_programada as fecha', 'se.nombre as titulo', 'se.dia', 'se.semana', 'se.estado'])
            ->map(fn ($s): array => array_merge((array) $s, ['tipo' => 'ENTRENAMIENTO', 'hora_inicio' => null, 'hora_fin' => null, 'sede' => null]));

        $entrenador = DB::table('entrenamiento.asignaciones_entrenador_cliente as a')
            ->join('entrenamiento.entrenadores as e', 'e.id', '=', 'a.entrenador_id')
            ->join('seguridad.users as u', 'u.id', '=', 'e.usuario_id')
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', 'a.sede_id')
            ->leftJoin('agenda.entrenador_horarios as h', 'h.id', '=', 'a.entrenador_horario_id')
            ->where('a.deportista_id', $id)->whereIn('a.estado', ['ACTIVA', 'ACTIVO'])
            ->orderByDesc('a.fecha_inicio')->first(['e.id', 'u.name', 'e.especialidad', 'e.tipo', 'a.tipo_asignacion', 'a.fecha_inicio', 'a.fecha_fin', 'a.observaciones', 's.nombre as sede', 'h.id as horario_id']);
        if ($entrenador) {
            $horarioIds = $entrenador->horario_id
                ? collect([$entrenador->horario_id])
                : DB::table('agenda.entrenador_horarios')->where('entrenador_id', $entrenador->id)->where('activo', true)->pluck('id');
            $entrenador->horarios = DB::table('agenda.entrenador_horario_franjas')->whereIn('entrenador_horario_id', $horarioIds)
                ->where('activo', true)->orderBy('dia_semana')->orderBy('hora_inicio')->get(['dia_semana', 'hora_inicio', 'hora_fin']);
        }

        $controles = DB::table('entrenamiento.progresos_corporales')->where('cliente_id', $id)->orderBy('fecha_registro')->get();
        $primero = $controles->first();
        $ultimo = $controles->last();
        $rm = DB::table('entrenamiento.rm_registros as r')->join('entrenamiento.ejercicios as e', 'e.id', '=', 'r.ejercicio_id')
            ->where('r.cliente_id', $id)->orderByDesc('r.fecha_registro')->get(['e.nombre as ejercicio', 'r.rm_estimado', 'r.fecha_registro']);
        $sesionesCompletadas = DB::table('entrenamiento.sesiones')->where('cliente_id', $id)->where('estado', 'COMPLETADA')->count();
        $sesionesTotal = DB::table('entrenamiento.sesiones')->where('cliente_id', $id)->count();

        return ApiResponse::exito('Portal personal consultado.', [
            'asistencia' => ['total' => $asistencias->count(), 'mes' => $asistencias->filter(fn ($a) => str_starts_with((string) $a->fecha_hora, now()->format('Y-m')))->count(), 'registros' => $asistencias],
            'calendario' => $reservas->concat($sesiones)->sortBy(fn ($i) => ($i['fecha'] ?? '').($i['hora_inicio'] ?? ''))->values(),
            'entrenador' => $entrenador,
            'resultados' => [
                'sesiones_completadas' => $sesionesCompletadas,
                'sesiones_total' => $sesionesTotal,
                'cumplimiento' => $sesionesTotal ? round($sesionesCompletadas / $sesionesTotal * 100) : 0,
                'primer_control' => $primero,
                'ultimo_control' => $ultimo,
                'cambios' => $primero && $ultimo ? [
                    'peso_kg' => round((float) $ultimo->peso_kg - (float) $primero->peso_kg, 2),
                    'cintura_cm' => round((float) $ultimo->cintura_cm - (float) $primero->cintura_cm, 2),
                    'grasa_pct' => round((float) $ultimo->grasa_corporal_pct - (float) $primero->grasa_corporal_pct, 2),
                    'masa_magra_kg' => round((float) $ultimo->masa_magra_kg - (float) $primero->masa_magra_kg, 2),
                ] : null,
                'rm' => $rm->unique('ejercicio')->values(),
            ],
        ]);
    }

    private function vacio(): array
    {
        return ['asistencia' => ['total' => 0, 'mes' => 0, 'registros' => []], 'calendario' => [], 'entrenador' => null, 'resultados' => ['sesiones_completadas' => 0, 'sesiones_total' => 0, 'cumplimiento' => 0, 'primer_control' => null, 'ultimo_control' => null, 'cambios' => null, 'rm' => []]];
    }
}
