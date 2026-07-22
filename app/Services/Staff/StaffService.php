<?php

namespace App\Services\Staff;

use App\Queries\Staff\StaffQuery;
use App\Services\Audit\AuditService;
use App\Services\Notificaciones\NotificacionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StaffService
{
    public function __construct(
        private AuditService $auditService,
        private StaffQuery $staffQuery,
        private NotificacionService $notificacionService
    ) {
    }

    public function crearPerfil(Request $request, array $data): array
    {
        $id = DB::transaction(function () use ($data) {
            $id = DB::table('staff.perfiles')->insertGetId([
                'persona_id' => $data['persona_id'],
                'usuario_id' => $data['usuario_id'] ?? null,
                'tipo_staff' => $data['tipo_staff'],
                'especialidad' => $data['especialidad'] ?? null,
                'estado' => $data['estado'] ?? 'ACTIVO',
                'fecha_inicio' => $data['fecha_inicio'] ?? now()->toDateString(),
                'fecha_fin' => $data['fecha_fin'] ?? null,
                'observaciones' => $data['observaciones'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->syncSedes($id, $data['sedes'] ?? []);

            return $id;
        });

        $created = $this->staffQuery->perfil($id);
        $this->auditService->created($request, 'staff_perfiles', $id, $created, [
            'esquema' => 'staff',
            'modulo' => 'staff',
            'accion' => 'crear_perfil_staff',
            'persona_id_afectada' => $data['persona_id'],
        ]);

        return $created ?? [];
    }

    public function crearTurno(Request $request, array $data): array
    {
        $this->validarCoachSede((int) $data['coach_id'], (int) $data['sede_id']);
        $this->validarCruceTurno($data);

        $id = DB::table('staff.turnos_recurrentes')->insertGetId([
            'coach_id' => $data['coach_id'],
            'sede_id' => $data['sede_id'],
            'dia_semana' => $data['dia_semana'],
            'hora_inicio' => $data['hora_inicio'],
            'hora_fin' => $data['hora_fin'],
            'capacidad_atencion' => $data['capacidad_atencion'] ?? 1,
            'activo' => $data['activo'] ?? true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $turno = collect($this->staffQuery->turnos(['coach_id' => $data['coach_id']]))
            ->firstWhere('id', $id);

        $this->auditService->created($request, 'staff_turnos_recurrentes', $id, $turno, [
            'esquema' => 'staff',
            'modulo' => 'staff',
            'accion' => 'crear_turno_coach',
            'sede_id' => $data['sede_id'],
        ]);

        return $turno ?? ['id' => $id];
    }

    public function asignarCliente(Request $request, array $data): array
    {
        $this->validarCoachSede((int) $data['coach_id'], (int) $data['sede_id']);
        $this->validarTurnoCoach($data);
        $membresia = $this->validarClienteMembresia((int) $data['persona_id'], (int) $data['sede_id']);
        $this->validarClienteSinAsignacionActiva((int) $data['persona_id']);
        $this->validarCapacidadTurno($data);

        $id = DB::table('staff.cliente_asignaciones')->insertGetId([
            'coach_id' => $data['coach_id'],
            'persona_id' => $data['persona_id'],
            'socio_id' => $membresia->socio_id,
            'sede_id' => $data['sede_id'],
            'turno_recurrente_id' => $data['turno_recurrente_id'] ?? null,
            'tipo_asignacion' => $data['tipo_asignacion'] ?? 'SEGUIMIENTO',
            'fecha_inicio' => $data['fecha_inicio'] ?? now()->toDateString(),
            'fecha_fin' => $data['fecha_fin'] ?? null,
            'estado' => $data['estado'] ?? 'ACTIVO',
            'objetivo' => $data['objetivo'] ?? null,
            'observaciones' => $data['observaciones'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $asignacion = collect($this->staffQuery->clientesAsignados(['estado' => 'ACTIVO']))
            ->firstWhere('id', $id);

        $this->auditService->created($request, 'staff_cliente_asignaciones', $id, $asignacion, [
            'esquema' => 'staff',
            'modulo' => 'staff',
            'accion' => 'asignar_cliente_coach',
            'persona_id_afectada' => $data['persona_id'],
            'sede_id' => $data['sede_id'],
        ]);

        $this->notificarAsignacionCoach($request, $asignacion ?? ['id' => $id]);

        return $asignacion ?? ['id' => $id];
    }

    public function finalizarAsignacion(Request $request, int $id): void
    {
        $before = collect($this->staffQuery->clientesAsignados())->firstWhere('id', $id);

        DB::table('staff.cliente_asignaciones')
            ->where('id', $id)
            ->update([
                'estado' => 'FINALIZADO',
                'fecha_fin' => now()->toDateString(),
                'updated_at' => now(),
            ]);

        $this->auditService->updated($request, 'staff_cliente_asignaciones', $id, $before, ['estado' => 'FINALIZADO'], [
            'esquema' => 'staff',
            'modulo' => 'staff',
            'accion' => 'finalizar_cliente_coach',
        ]);
    }

    public function actualizarObservaciones(Request $request, int $id, array $data): array
    {
        $before = collect($this->staffQuery->clientesAsignados())->firstWhere('id', $id);

        if (!$before) {
            throw ValidationException::withMessages([
                'asignacion' => 'No se encontró la asignación del cliente.',
            ]);
        }

        DB::table('staff.cliente_asignaciones')
            ->where('id', $id)
            ->update([
                'objetivo' => $data['objetivo'] ?? $before['objetivo'] ?? null,
                'observaciones' => $data['observaciones'] ?? null,
                'updated_at' => now(),
            ]);

        $after = collect($this->staffQuery->clientesAsignados())->firstWhere('id', $id);
        $this->auditService->updated($request, 'staff_cliente_asignaciones', $id, $before, $after, [
            'esquema' => 'staff',
            'modulo' => 'staff',
            'accion' => 'actualizar_observaciones_coach',
            'persona_id_afectada' => $after['persona_id'] ?? null,
            'sede_id' => $after['sede_id'] ?? null,
        ]);

        return $after ?? ['id' => $id];
    }

    private function syncSedes(int $coachId, array $sedes): void
    {
        DB::table('staff.coach_sedes')->where('coach_id', $coachId)->delete();

        $rows = collect($sedes)
            ->filter(fn ($sede) => $sede !== null && $sede !== '')
            ->map(fn ($sede) => [
                'coach_id' => $coachId,
                'sede_id' => (int) $sede,
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->values()
            ->all();

        if ($rows) {
            DB::table('staff.coach_sedes')->insert($rows);
        }
    }

    private function validarCoachSede(int $coachId, int $sedeId): void
    {
        $ok = DB::table('staff.perfiles as sp')
            ->join('staff.coach_sedes as cs', 'cs.coach_id', '=', 'sp.id')
            ->where('sp.id', $coachId)
            ->where('sp.estado', 'ACTIVO')
            ->where('cs.sede_id', $sedeId)
            ->where('cs.activo', true)
            ->exists();

        if (!$ok) {
            throw ValidationException::withMessages([
                'sede_id' => 'El coach seleccionado no está activo o no pertenece a esta sede.',
            ]);
        }
    }

    private function validarCruceTurno(array $data): void
    {
        $exists = DB::table('staff.turnos_recurrentes')
            ->where('coach_id', $data['coach_id'])
            ->where('dia_semana', $data['dia_semana'])
            ->where('activo', true)
            ->where(function ($query) use ($data) {
                $query->where(function ($q) use ($data) {
                    $q->where('hora_inicio', '<', $data['hora_fin'])
                        ->where('hora_fin', '>', $data['hora_inicio']);
                });
            })
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'hora_inicio' => 'El coach ya tiene un turno cruzado en ese día y horario.',
            ]);
        }
    }

    private function validarTurnoCoach(array $data): void
    {
        if (empty($data['turno_recurrente_id'])) {
            return;
        }

        $ok = DB::table('staff.turnos_recurrentes')
            ->where('id', (int) $data['turno_recurrente_id'])
            ->where('coach_id', (int) $data['coach_id'])
            ->where('sede_id', (int) $data['sede_id'])
            ->where('activo', true)
            ->exists();

        if (!$ok) {
            throw ValidationException::withMessages([
                'turno_recurrente_id' => 'El turno no pertenece al coach o sede seleccionada.',
            ]);
        }
    }

    private function validarClienteMembresia(int $personaId, int $sedeId): object
    {
        $membresia = DB::table('socios.socios as s')
            ->join('socios.socio_membresias as sm', 'sm.socio_id', '=', 's.id')
            ->join('socios.membresias as m', 'm.id', '=', 'sm.membresia_id')
            ->where('s.persona_id', $personaId)
            ->where('sm.sede_id', $sedeId)
            ->whereDate('sm.fecha_fin', '>=', now()->toDateString())
            ->orderByDesc('sm.fecha_fin')
            ->select('s.id as socio_id', 'sm.id as socio_membresia_id', 'sm.fecha_fin', 'm.nombre', 'm.duracion_dias')
            ->first();

        if (!$membresia) {
            throw ValidationException::withMessages([
                'persona_id' => 'El cliente no tiene una membresía vigente en la sede seleccionada.',
            ]);
        }

        $nombre = mb_strtoupper((string) $membresia->nombre);
        if ((int) $membresia->duracion_dias <= 1 || str_contains($nombre, 'PASE DIARIO')) {
            throw ValidationException::withMessages([
                'persona_id' => 'Las membresías de pase diario no permiten seguimiento fijo con coach.',
            ]);
        }

        return $membresia;
    }

    private function validarClienteSinAsignacionActiva(int $personaId): void
    {
        $exists = DB::table('staff.cliente_asignaciones')
            ->where('persona_id', $personaId)
            ->where('estado', 'ACTIVO')
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'persona_id' => 'El cliente ya tiene una asignación activa con coach.',
            ]);
        }
    }

    private function validarCapacidadTurno(array $data): void
    {
        if (empty($data['turno_recurrente_id'])) {
            return;
        }

        $turno = DB::table('staff.turnos_recurrentes')
            ->where('id', (int) $data['turno_recurrente_id'])
            ->first();

        if (!$turno) {
            return;
        }

        $ocupados = DB::table('staff.cliente_asignaciones')
            ->where('turno_recurrente_id', (int) $data['turno_recurrente_id'])
            ->where('estado', 'ACTIVO')
            ->count();

        if ($ocupados >= (int) $turno->capacidad_atencion) {
            throw ValidationException::withMessages([
                'turno_recurrente_id' => 'El turno seleccionado ya alcanzó su capacidad.',
            ]);
        }
    }

    private function notificarAsignacionCoach(Request $request, array $asignacion): void
    {
        if (empty($asignacion['persona_id']) || empty($asignacion['coach_id'])) {
            return;
        }

        try {
            $coach = DB::table('staff.perfiles')->where('id', (int) $asignacion['coach_id'])->first();
            $turno = $this->formatearTurno($asignacion);
            $coachNombre = $asignacion['coach_nombre'] ?? 'tu coach';
            $clienteNombre = $asignacion['cliente_nombre'] ?? 'un cliente';
            $sedeNombre = $asignacion['sede_nombre'] ?? 'la sede asignada';

            $this->notificacionService->crear([
                'tipo' => 'COACH_ASIGNADO',
                'titulo' => 'Coach asignado',
                'mensaje' => "Se te asigno {$coachNombre} en {$sedeNombre}. Turno: {$turno}.",
                'prioridad' => 'NORMAL',
                'canal' => 'APP',
                'personas' => [(int) $asignacion['persona_id']],
                'data' => [
                    'dedupe_key' => 'coach-asignado-cliente:' . $asignacion['id'],
                    'categoria' => 'coach',
                    'asignacion_id' => (int) $asignacion['id'],
                    'coach_id' => (int) $asignacion['coach_id'],
                    'turno' => $turno,
                ],
            ], $request->user()?->id);

            if ($coach?->usuario_id) {
                $this->notificacionService->crear([
                    'tipo' => 'CLIENTE_ASIGNADO',
                    'titulo' => 'Cliente asignado',
                    'mensaje' => "Tienes asignado a {$clienteNombre} en {$sedeNombre}. Turno: {$turno}.",
                    'prioridad' => 'NORMAL',
                    'canal' => 'APP',
                    'usuarios' => [(int) $coach->usuario_id],
                    'data' => [
                        'dedupe_key' => 'coach-asignado-entrenador:' . $asignacion['id'],
                        'categoria' => 'coach',
                        'asignacion_id' => (int) $asignacion['id'],
                        'persona_id' => (int) $asignacion['persona_id'],
                        'turno' => $turno,
                    ],
                ], $request->user()?->id);
            }
        } catch (\Throwable) {
            // La asignacion no debe fallar si notificaciones no está disponible.
        }
    }

    private function formatearTurno(array $asignacion): string
    {
        if (empty($asignacion['dia_semana']) || empty($asignacion['hora_inicio']) || empty($asignacion['hora_fin'])) {
            return 'sin turno fijo';
        }

        $dias = [
            1 => 'lunes',
            2 => 'martes',
            3 => 'miercoles',
            4 => 'jueves',
            5 => 'viernes',
            6 => 'sabado',
            7 => 'domingo',
        ];

        return sprintf(
            '%s %s-%s',
            $dias[(int) $asignacion['dia_semana']] ?? 'dia asignado',
            substr((string) $asignacion['hora_inicio'], 0, 5),
            substr((string) $asignacion['hora_fin'], 0, 5)
        );
    }
}
