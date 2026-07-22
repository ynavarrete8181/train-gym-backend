<?php

namespace App\Services\Asistencia;

use App\Services\Audit\AuditService;
use App\Services\Notificaciones\AdminNotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AsistenciaService
{
    public function __construct(
        private AuditService $auditService,
        private AdminNotificationService $adminNotificationService
    ) {
    }

    public function registrar(Request $request, array $data): array
    {
        $membresia = $this->membresiaVigente((int) $data['persona_id'], $data['socio_membresia_id'] ?? null);
        $reserva = $this->resolverReserva($data);

        $id = DB::transaction(function () use ($request, $data, $membresia, $reserva) {
            $id = DB::table('asistencia.registros')->insertGetId([
                'persona_id' => $data['persona_id'],
                'sede_id' => $data['sede_id'],
                'reserva_id' => $reserva?->id,
                'socio_membresia_id' => $membresia?->id,
                'coach_id' => $data['coach_id'] ?? null,
                'staff_cliente_asignacion_id' => $data['staff_cliente_asignacion_id'] ?? null,
                'turno_recurrente_id' => $data['turno_recurrente_id'] ?? null,
                'fecha_hora' => $data['fecha_hora'] ?? now(),
                'tipo' => $data['tipo'] ?? 'ENTRADA',
                'metodo' => $data['metodo'] ?? 'MANUAL',
                'origen' => $data['origen'] ?? 'WEB',
                'estado' => 'PERMITIDO',
                'registrado_por_usuario_id' => $request->user()?->id,
                'motivo' => $data['motivo'] ?? null,
                'request_id' => $request->attributes->get('request_id') ?? $request->headers->get('X-Request-ID'),
                'metadata' => isset($data['metadata']) ? json_encode($data['metadata'], JSON_UNESCAPED_UNICODE) : null,
                'created_at' => now(),
            ]);

            if ($reserva) {
                DB::table('reservas.reservas')->where('id', $reserva->id)->update([
                    'estado' => 'ASISTIO',
                    'updated_at' => now(),
                ]);
            }

            return $id;
        });

        $registro = $this->obtener($id);
        $this->auditService->created($request, 'asistencia_registros', $id, $registro, [
            'esquema' => 'asistencia',
            'modulo' => 'asistencia',
            'accion' => 'checkin_permitido',
            'sede_id' => $data['sede_id'],
            'persona_id_afectada' => $data['persona_id'],
        ]);

        try {
            $this->adminNotificationService->asistenciaRegistrada($registro, $request->user()?->id);
        } catch (\Throwable $exception) {
            Log::warning('No se pudo notificar asistencia registrada.', [
                'asistencia_id' => $id,
                'error' => $exception->getMessage(),
            ]);
        }

        return $registro;
    }

    public function obtener(int $id): array
    {
        $row = DB::table('asistencia.registros as ar')
            ->join('core.personas as p', 'p.id', '=', 'ar.persona_id')
            ->join('core.sedes as s', 's.id', '=', 'ar.sede_id')
            ->selectRaw("
                ar.*,
                CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, '')) as persona_nombre,
                s.nombre as sede_nombre
            ")
            ->where('ar.id', $id)
            ->first();

        return [
            'id' => (int) $row->id,
            'persona_id' => (int) $row->persona_id,
            'persona_nombre' => trim((string) $row->persona_nombre),
            'sede_id' => (int) $row->sede_id,
            'sede_nombre' => $row->sede_nombre,
            'reserva_id' => $row->reserva_id ? (int) $row->reserva_id : null,
            'socio_membresia_id' => $row->socio_membresia_id ? (int) $row->socio_membresia_id : null,
            'coach_id' => $row->coach_id ? (int) $row->coach_id : null,
            'staff_cliente_asignacion_id' => $row->staff_cliente_asignacion_id ? (int) $row->staff_cliente_asignacion_id : null,
            'turno_recurrente_id' => $row->turno_recurrente_id ? (int) $row->turno_recurrente_id : null,
            'fecha_hora' => $row->fecha_hora,
            'tipo' => $row->tipo,
            'metodo' => $row->metodo,
            'origen' => $row->origen,
            'estado' => $row->estado,
            'motivo' => $row->motivo,
            'request_id' => $row->request_id,
        ];
    }

    private function membresiaVigente(int $personaId, ?int $socioMembresiaId): ?object
    {
        $query = DB::table('socios.socio_membresias as sm')
            ->join('socios.socios as s', 's.id', '=', 'sm.socio_id')
            ->where('s.persona_id', $personaId)
            ->whereDate('sm.fecha_inicio', '<=', now()->toDateString())
            ->whereDate('sm.fecha_fin', '>=', now()->toDateString());

        if ($socioMembresiaId) {
            $query->where('sm.id', $socioMembresiaId);
        }

        $membresia = $query->orderByDesc('sm.fecha_fin')->select('sm.*')->first();

        if (!$membresia) {
            throw ValidationException::withMessages([
                'persona_id' => 'La persona no tiene una membresía vigente para registrar asistencia.',
            ]);
        }

        return $membresia;
    }

    private function resolverReserva(array $data): ?object
    {
        if (!empty($data['reserva_id'])) {
            return DB::table('reservas.reservas')
                ->where('id', $data['reserva_id'])
                ->where('persona_id', $data['persona_id'])
                ->where('estado', 'RESERVADA')
                ->first();
        }

        $fechaHora = isset($data['fecha_hora']) ? Carbon::parse($data['fecha_hora']) : now();
        $fecha = $fechaHora->toDateString();
        $hora = $fechaHora->format('H:i:s');

        return DB::table('reservas.reservas')
            ->where('persona_id', $data['persona_id'])
            ->where('sede_id', $data['sede_id'])
            ->where('fecha', $fecha)
            ->where('estado', 'RESERVADA')
            ->orderByRaw("
                CASE
                    WHEN hora_inicio <= ? AND hora_fin >= ? THEN 0
                    WHEN hora_inicio > ? THEN 1
                    ELSE 2
                END
            ", [$hora, $hora, $hora])
            ->orderByRaw("ABS(EXTRACT(EPOCH FROM (hora_inicio - ?::time)))", [$hora])
            ->orderBy('hora_inicio')
            ->first();
    }
}
