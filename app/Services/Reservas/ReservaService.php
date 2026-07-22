<?php

namespace App\Services\Reservas;

use App\Services\Audit\AuditService;
use App\Services\Notificaciones\AdminNotificationService;
use App\Services\Notificaciones\NotificacionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReservaService
{
    public function __construct(
        private AuditService $auditService,
        private NotificacionService $notificacionService,
        private AdminNotificationService $adminNotificationService
    ) {
    }

    public function crear(Request $request, array $data): array
    {
        if (empty($data['persona_id']) && $request->user()?->persona_id) {
            $data['persona_id'] = (int) $request->user()->persona_id;
        }

        if (!empty($data['cupo_diario_id'])) {
            $cupo = $this->resolverCupo((int) $data['cupo_diario_id']);
            $data = array_merge($data, [
                'sede_id' => (int) $cupo->sede_id,
                'servicio_id' => (int) $cupo->servicio_id,
                'fecha' => $cupo->fecha,
                'hora_inicio' => substr((string) $cupo->hora_inicio, 0, 5),
                'hora_fin' => substr((string) $cupo->hora_fin, 0, 5),
                'capacidad' => (int) $cupo->capacidad,
            ]);
        }

        if (empty($data['persona_id'])) {
            throw ValidationException::withMessages([
                'persona_id' => 'No se pudo identificar la persona para crear la reserva.',
            ]);
        }

        $membresia = $this->membresiaVigente((int) $data['persona_id'], $data['socio_membresia_id'] ?? null, (int) $data['sede_id'], $data['fecha']);
        $this->validarReservaCruzada($data);
        $this->validarCupo($data);

        $id = DB::table('reservas.reservas')->insertGetId([
            'persona_id' => $data['persona_id'],
            'socio_membresia_id' => $membresia?->id,
            'sede_id' => $data['sede_id'],
            'cupo_diario_id' => $data['cupo_diario_id'] ?? null,
            'coach_usuario_id' => $data['coach_usuario_id'] ?? null,
            'servicio_id' => $data['servicio_id'] ?? null,
            'fecha' => $data['fecha'],
            'hora_inicio' => $data['hora_inicio'],
            'hora_fin' => $data['hora_fin'],
            'estado' => 'RESERVADA',
            'origen' => $data['origen'] ?? 'WEB',
            'created_by_usuario_id' => $request->user()?->id,
            'metadata' => isset($data['metadata']) ? json_encode($data['metadata'], JSON_UNESCAPED_UNICODE) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reserva = $this->obtener($id);
        $this->auditService->created($request, 'reservas', $id, $reserva, [
            'esquema' => 'reservas',
            'modulo' => 'reservas',
            'accion' => 'crear_reserva',
            'sede_id' => $data['sede_id'],
            'persona_id_afectada' => $data['persona_id'],
        ]);

        $this->notificarReserva($request, $reserva);
        $this->adminNotificationService->reservaCreada($reserva, $request->user()?->id);

        return $reserva;
    }

    public function cancelar(Request $request, int $id, ?string $motivo = null): array
    {
        $before = $this->obtener($id);
        if (!$before) {
            throw ValidationException::withMessages(['reserva' => 'No se encontró la reserva.']);
        }

        if ($request->is('api/app/*') && $request->user()?->persona_id && (int) $before['persona_id'] !== (int) $request->user()->persona_id) {
            abort(403, 'No puedes cancelar una reserva de otro cliente.');
        }

        if (!in_array($before['estado'], ['RESERVADA'], true)) {
            throw ValidationException::withMessages([
                'reserva' => 'Solo se pueden cancelar reservas activas.',
            ]);
        }

        DB::table('reservas.reservas')->where('id', $id)->update([
            'estado' => 'CANCELADA',
            'motivo_cancelacion' => $motivo,
            'updated_at' => now(),
        ]);

        $after = $this->obtener($id);
        $this->auditService->updated($request, 'reservas', $id, $before, $after, [
            'esquema' => 'reservas',
            'modulo' => 'reservas',
            'accion' => 'cancelar_reserva',
            'sede_id' => $after['sede_id'] ?? null,
            'persona_id_afectada' => $after['persona_id'] ?? null,
        ]);

        $this->notificarReservaCancelada($request, $after, $motivo);
        $this->adminNotificationService->reservaCancelada($after, $motivo, $request->user()?->id);

        return $after;
    }

    public function obtener(int $id): ?array
    {
        $row = DB::table('reservas.reservas as r')
            ->join('core.personas as p', 'p.id', '=', 'r.persona_id')
            ->join('core.sedes as s', 's.id', '=', 'r.sede_id')
            ->leftJoin('train_gimnasio.tipos_servicios as ts', 'ts.id', '=', 'r.servicio_id')
            ->leftJoin('seguridad.usuarios as cu', 'cu.id', '=', 'r.coach_usuario_id')
            ->selectRaw("
                r.*,
                COALESCE(r.persona_cedula, p.numero_identificacion) as persona_cedula,
                CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, '')) as persona_nombre,
                s.nombre as sede_nombre,
                ts.nombre as servicio_nombre,
                cu.email as coach_email
            ")
            ->where('r.id', $id)
            ->first();

        return $row ? [
            'id' => (int) $row->id,
            'persona_id' => (int) $row->persona_id,
            'persona_nombre' => trim((string) $row->persona_nombre),
            'persona_cedula' => $row->persona_cedula,
            'socio_membresia_id' => $row->socio_membresia_id ? (int) $row->socio_membresia_id : null,
            'sede_id' => (int) $row->sede_id,
            'sede_nombre' => $row->sede_nombre,
            'cupo_diario_id' => $row->cupo_diario_id ? (int) $row->cupo_diario_id : null,
            'coach_usuario_id' => $row->coach_usuario_id ? (int) $row->coach_usuario_id : null,
            'coach_email' => $row->coach_email,
            'servicio_id' => $row->servicio_id ? (int) $row->servicio_id : null,
            'servicio_nombre' => $row->servicio_nombre,
            'fecha' => $row->fecha,
            'hora_inicio' => $row->hora_inicio,
            'hora_fin' => $row->hora_fin,
            'estado' => $row->estado,
            'origen' => $row->origen,
        ] : null;
    }

    public function generarCupos(array $filters = []): int
    {
        $fechaDesde = $filters['fecha_desde'] ?? now()->toDateString();
        $dias = max(1, min((int) ($filters['dias'] ?? 15), 60));
        $generados = 0;

        for ($offset = 0; $offset < $dias; $offset++) {
            $fecha = Carbon::parse($fechaDesde)->addDays($offset);
            $diaSemana = (int) $fecha->dayOfWeekIso;

            $horarios = DB::table('train_gimnasio.horarios_gym as h')
                ->join('train_gimnasio.horarios_gym_dias as hd', 'hd.horario_id', '=', 'h.id')
                ->where('h.activo', true)
                ->where('hd.dia_semana', $diaSemana)
                ->when(!empty($filters['sede_id']), fn ($query) => $query->where('h.sede_id', (int) $filters['sede_id']))
                ->select('h.*')
                ->get();

            foreach ($horarios as $horario) {
                $hora = Carbon::parse($fecha->toDateString() . ' ' . $horario->hora_apertura);
                $cierre = Carbon::parse($fecha->toDateString() . ' ' . $horario->hora_cierre);
                $minutos = max((int) $horario->tiempo_turno_min, 15);

                while ($hora->copy()->addMinutes($minutos)->lessThanOrEqualTo($cierre)) {
                    $fin = $hora->copy()->addMinutes($minutos);
                    DB::table('reservas.cupos_diarios')->upsert([
                        [
                            'horario_id' => (int) $horario->id,
                            'sede_id' => (int) $horario->sede_id,
                            'servicio_id' => (int) $horario->tipo_servicio_id,
                            'fecha' => $fecha->toDateString(),
                            'hora_inicio' => $hora->format('H:i:s'),
                            'hora_fin' => $fin->format('H:i:s'),
                            'capacidad' => (int) $horario->capacidad_maxima,
                            'estado' => 'ABIERTO',
                            'updated_at' => now(),
                            'created_at' => now(),
                        ],
                    ], ['horario_id', 'fecha', 'hora_inicio', 'hora_fin'], ['capacidad', 'estado', 'updated_at']);

                    $generados++;
                    $hora = $fin;
                }
            }
        }

        return $generados;
    }

    private function membresiaVigente(int $personaId, ?int $socioMembresiaId, ?int $sedeId = null, ?string $fecha = null): ?object
    {
        $fechaReserva = $fecha ?: now()->toDateString();
        $query = DB::table('socios.socio_membresias as sm')
            ->join('socios.socios as s', 's.id', '=', 'sm.socio_id')
            ->where('s.persona_id', $personaId)
            ->whereDate('sm.fecha_inicio', '<=', $fechaReserva)
            ->whereDate('sm.fecha_fin', '>=', $fechaReserva);

        if ($socioMembresiaId) {
            $query->where('sm.id', $socioMembresiaId);
        }

        if ($sedeId) {
            $query->where(function ($q) use ($sedeId) {
                $q->whereNull('sm.sede_id')
                    ->orWhere('sm.sede_id', $sedeId);
            });
        }

        $membresia = $query->orderByDesc('sm.fecha_fin')->select('sm.*')->first();

        if (!$membresia) {
            throw ValidationException::withMessages([
                'persona_id' => 'La persona no tiene una membresía vigente para reservar.',
            ]);
        }

        return $membresia;
    }

    private function validarCupo(array $data): void
    {
        $ocupadas = DB::table('reservas.reservas')
            ->where('sede_id', $data['sede_id'])
            ->where('fecha', $data['fecha'])
            ->whereIn('estado', ['RESERVADA', 'ASISTIO'])
            ->where('hora_inicio', '<', $data['hora_fin'])
            ->where('hora_fin', '>', $data['hora_inicio'])
            ->when(!empty($data['cupo_diario_id']), fn ($query) => $query->where('cupo_diario_id', (int) $data['cupo_diario_id']))
            ->count();

        $capacidad = (int) ($data['capacidad'] ?? 1);
        if ($ocupadas >= $capacidad) {
            throw ValidationException::withMessages([
                'hora_inicio' => 'No hay cupo disponible en ese horario.',
            ]);
        }
    }

    private function validarReservaCruzada(array $data): void
    {
        $existe = DB::table('reservas.reservas')
            ->where('persona_id', (int) $data['persona_id'])
            ->where('fecha', $data['fecha'])
            ->whereIn('estado', ['RESERVADA', 'ASISTIO'])
            ->where('hora_inicio', '<', $data['hora_fin'])
            ->where('hora_fin', '>', $data['hora_inicio'])
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages([
                'hora_inicio' => 'Ya tienes una reserva activa en ese rango horario.',
            ]);
        }
    }

    private function resolverCupo(int $id): object
    {
        $cupo = DB::table('reservas.cupos_diarios')->where('id', $id)->first();

        if (!$cupo || $cupo->estado !== 'ABIERTO') {
            throw ValidationException::withMessages([
                'cupo_diario_id' => 'El cupo seleccionado no está disponible.',
            ]);
        }

        return $cupo;
    }

    private function notificarReserva(Request $request, array $reserva): void
    {
        try {
            $this->notificacionService->crear([
                'titulo' => 'Reserva confirmada',
                'mensaje' => "Tu reserva en {$reserva['sede_nombre']} fue confirmada para {$reserva['fecha']} {$reserva['hora_inicio']}.",
                'tipo' => 'RESERVA_CONFIRMADA',
                'canal' => 'APP',
                'prioridad' => 'NORMAL',
                'data' => [
                    'dedupe_key' => 'reserva-confirmada:' . $reserva['id'],
                    'reserva_id' => $reserva['id'],
                    'categoria' => 'reserva',
                ],
                'personas' => [$reserva['persona_id']],
            ], $request->user()?->id);
        } catch (\Throwable) {
            // La reserva no debe fallar si el canal de notificaciones no está disponible.
        }
    }

    private function notificarReservaCancelada(Request $request, array $reserva, ?string $motivo): void
    {
        try {
            $mensaje = "Tu reserva en {$reserva['sede_nombre']} para {$reserva['fecha']} {$reserva['hora_inicio']} fue cancelada.";
            if ($motivo) {
                $mensaje .= " Motivo: {$motivo}.";
            }

            $this->notificacionService->crear([
                'titulo' => 'Reserva cancelada',
                'mensaje' => $mensaje,
                'tipo' => 'RESERVA_CANCELADA',
                'canal' => 'APP',
                'prioridad' => 'ALTA',
                'data' => [
                    'dedupe_key' => 'reserva-cancelada:' . $reserva['id'],
                    'reserva_id' => $reserva['id'],
                    'categoria' => 'reserva',
                    'motivo' => $motivo,
                ],
                'personas' => [$reserva['persona_id']],
            ], $request->user()?->id);
        } catch (\Throwable) {
            // La cancelacion no debe fallar si el canal de notificaciones no está disponible.
        }
    }
}
