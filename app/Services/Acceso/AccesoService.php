<?php

namespace App\Services\Acceso;

use App\Services\Asistencia\AsistenciaService;
use App\Services\Audit\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccesoService
{
    public function __construct(
        private AuditService $auditService,
        private AsistenciaService $asistenciaService
    ) {
    }

    public function credencialApp(Request $request): array
    {
        $user = $request->user();
        if (!$user?->persona_id) {
            throw ValidationException::withMessages([
                'persona_id' => 'El usuario no tiene una persona asociada.',
            ]);
        }

        $personaId = (int) $user->persona_id;
        $membresia = $this->membresiaVigente($personaId);
        $codigo = 'REVIVE|' . $personaId . '|' . Str::uuid();
        $hash = hash('sha256', $codigo);

        DB::table('acceso.credenciales')->insert([
            'persona_id' => $personaId,
            'tipo' => 'QR',
            'codigo_hash' => $hash,
            'estado' => 'ACTIVA',
            'vigencia_inicio' => now(),
            'vigencia_fin' => now()->addMinutes(10),
            'metadata' => json_encode([
                'usuario_id' => $user->id,
                'socio_membresia_id' => $membresia?->id,
                'uso' => 'APP_ACCESO_TEMPORAL',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'persona_id' => $personaId,
            'codigo_qr' => $codigo,
            'vence_en_minutos' => 10,
            'membresia' => $membresia ? [
                'id' => (int) $membresia->id,
                'nombre' => $membresia->membresia_nombre,
                'fecha_fin' => $membresia->fecha_fin,
                'sede_id' => $membresia->sede_id ? (int) $membresia->sede_id : null,
                'sede_nombre' => $membresia->sede_nombre,
            ] : null,
        ];

        $this->auditService->activity($request, 'acceso', 'generar_qr_app', [
            'tabla' => 'acceso_credenciales',
            'operacion' => 'I',
            'persona_id_afectada' => $personaId,
            'datos_despues' => $payload,
        ]);

        return $payload;
    }

    public function validarQr(Request $request, array $data): array
    {
        $codigo = trim((string) $data['codigo_qr']);
        $hash = hash('sha256', $codigo);

        $credencial = DB::table('acceso.credenciales')
            ->where('codigo_hash', $hash)
            ->where('tipo', 'QR')
            ->first();

        if (!$credencial) {
            return $this->denegarQr($request, $data, 'QR no reconocido.');
        }

        if ($credencial->estado !== 'ACTIVA') {
            return $this->denegarQr($request, $data, 'La credencial no está activa.', $credencial);
        }

        if ($credencial->vigencia_fin && now()->greaterThan($credencial->vigencia_fin)) {
            return $this->denegarQr($request, $data, 'El QR expiró. Solicita uno nuevo desde la app.', $credencial);
        }

        $membresia = $this->membresiaVigente((int) $credencial->persona_id);
        if (!$membresia) {
            return $this->denegarQr($request, $data, 'No existe una membresía vigente para permitir el ingreso.', $credencial);
        }

        if (!$this->membresiaPermiteSede($membresia, $data['sede_id'] ?? null)) {
            $sedeSeleccionada = $this->nombreSede((int) $data['sede_id']);
            $sedeMembresia = $membresia->sede_nombre ?: 'otra sede';

            return $this->denegarQr(
                $request,
                $data,
                "La membresía pertenece a {$sedeMembresia} y no permite ingreso en {$sedeSeleccionada}.",
                $credencial
            );
        }

        DB::table('acceso.credenciales')
            ->where('id', $credencial->id)
            ->update([
                'estado' => 'USADA',
                'ultimo_uso_at' => now(),
                'updated_at' => now(),
            ]);

        $esPaseDiario = $this->esPaseDiario($membresia);
        $asignacionCoach = !$esPaseDiario && !empty($data['sede_id'])
            ? $this->asignacionCoachActiva((int) $credencial->persona_id, (int) $data['sede_id'])
            : null;
        $asistencia = null;
        if (!empty($data['sede_id']) && ($data['registrar_asistencia'] ?? true)) {
            $asistencia = $this->asistenciaService->registrar($request, [
                'persona_id' => (int) $credencial->persona_id,
                'sede_id' => (int) $data['sede_id'],
                'socio_membresia_id' => (int) $membresia->id,
                'coach_id' => $asignacionCoach['coach_id'] ?? null,
                'staff_cliente_asignacion_id' => $asignacionCoach['id'] ?? null,
                'turno_recurrente_id' => $asignacionCoach['turno_recurrente_id'] ?? null,
                'tipo' => 'ENTRADA',
                'metodo' => 'QR',
                'origen' => $data['origen'] ?? 'WEB',
                'metadata' => [
                    'credencial_id' => (int) $credencial->id,
                    'dispositivo_id' => $data['dispositivo_id'] ?? null,
                    'flujo_checkin' => $esPaseDiario ? 'PASE_DIARIO_GENERAL' : 'MEMBRESIA_REGULAR',
                    'coach_asignado' => $asignacionCoach,
                ],
            ]);
        }

        $reservaVinculada = !empty($asistencia['reserva_id'])
            ? $this->reservaVinculada((int) $asistencia['reserva_id'])
            : null;

        $this->registrarEventoAcceso($request, $data, $credencial, 'ENTRADA_PERMITIDA', 'PROCESADO', null, $asistencia['id'] ?? null);

        $response = [
            'permitido' => true,
            'mensaje' => $reservaVinculada ? 'Acceso permitido. Reserva marcada como asistida.' : 'Acceso permitido. Asistencia general registrada.',
            'persona_id' => (int) $credencial->persona_id,
            'membresia' => [
                'id' => (int) $membresia->id,
                'nombre' => $membresia->membresia_nombre,
                'fecha_fin' => $membresia->fecha_fin,
                'sede_id' => $membresia->sede_id ? (int) $membresia->sede_id : null,
                'sede_nombre' => $membresia->sede_nombre,
                'es_pase_diario' => $esPaseDiario,
            ],
            'asistencia' => $asistencia,
            'reserva' => $reservaVinculada,
            'tipo_checkin' => $reservaVinculada ? 'RESERVA' : 'GENERAL',
            'seguimiento_personalizado' => !$esPaseDiario && !empty($asignacionCoach),
            'coach_asignado' => $asignacionCoach,
        ];

        $this->auditService->activity($request, 'acceso', 'validar_qr_permitido', [
            'tabla' => 'acceso_credenciales',
            'operacion' => 'U',
            'registro_id' => $credencial->id,
            'persona_id_afectada' => $credencial->persona_id,
            'sede_id' => $data['sede_id'] ?? null,
            'datos_despues' => $response,
        ]);

        return $response;
    }

    private function membresiaVigente(int $personaId): ?object
    {
        return DB::table('socios.socio_membresias as sm')
            ->join('socios.socios as s', 's.id', '=', 'sm.socio_id')
            ->join('socios.membresias as m', 'm.id', '=', 'sm.membresia_id')
            ->leftJoin('core.sedes as se', 'se.id', '=', 'sm.sede_id')
            ->where('s.persona_id', $personaId)
            ->whereDate('sm.fecha_inicio', '<=', now()->toDateString())
            ->whereDate('sm.fecha_fin', '>=', now()->toDateString())
            ->orderByDesc('sm.fecha_fin')
            ->select('sm.*', 'm.nombre as membresia_nombre', 'se.nombre as sede_nombre')
            ->first();
    }

    private function membresiaPermiteSede(object $membresia, mixed $sedeId): bool
    {
        if (empty($sedeId) || empty($membresia->sede_id)) {
            return true;
        }

        return (int) $membresia->sede_id === (int) $sedeId;
    }

    private function esPaseDiario(object $membresia): bool
    {
        $nombre = Str::of((string) $membresia->membresia_nombre)->lower()->ascii()->toString();

        return str_contains($nombre, 'pase diario');
    }

    private function reservaVinculada(int $reservaId): ?array
    {
        $row = DB::table('reservas.reservas as r')
            ->leftJoin('train_gimnasio.tipos_servicios as ts', 'ts.id', '=', 'r.servicio_id')
            ->leftJoin('socios.socio_membresias as sm', 'sm.id', '=', 'r.socio_membresia_id')
            ->leftJoin('socios.membresias as m', 'm.id', '=', 'sm.membresia_id')
            ->where('r.id', $reservaId)
            ->selectRaw("
                r.id,
                r.fecha,
                r.hora_inicio,
                r.hora_fin,
                r.estado,
                ts.nombre as servicio_nombre,
                m.nombre as membresia_nombre
            ")
            ->first();

        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'fecha' => $row->fecha,
            'hora_inicio' => $row->hora_inicio,
            'hora_fin' => $row->hora_fin,
            'estado' => $row->estado,
            'servicio_nombre' => $row->servicio_nombre,
            'membresia_nombre' => $row->membresia_nombre,
        ];
    }

    private function nombreSede(int $sedeId): string
    {
        return DB::table('core.sedes')->where('id', $sedeId)->value('nombre') ?: "sede #{$sedeId}";
    }

    private function asignacionCoachActiva(int $personaId, int $sedeId): ?array
    {
        $row = DB::table('staff.cliente_asignaciones as ca')
            ->join('staff.perfiles as sp', 'sp.id', '=', 'ca.coach_id')
            ->join('core.personas as coach_persona', 'coach_persona.id', '=', 'sp.persona_id')
            ->leftJoin('staff.turnos_recurrentes as tr', 'tr.id', '=', 'ca.turno_recurrente_id')
            ->where('ca.persona_id', $personaId)
            ->where('ca.sede_id', $sedeId)
            ->where('ca.estado', 'ACTIVO')
            ->whereDate('ca.fecha_inicio', '<=', now()->toDateString())
            ->where(function ($query) {
                $query->whereNull('ca.fecha_fin')
                    ->orWhereDate('ca.fecha_fin', '>=', now()->toDateString());
            })
            ->orderByDesc('ca.fecha_inicio')
            ->selectRaw("
                ca.id,
                ca.coach_id,
                ca.turno_recurrente_id,
                ca.tipo_asignacion,
                ca.objetivo,
                CONCAT(COALESCE(coach_persona.nombres, ''), ' ', COALESCE(coach_persona.apellidos, '')) as coach_nombre,
                tr.dia_semana,
                tr.hora_inicio,
                tr.hora_fin
            ")
            ->first();

        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'coach_id' => (int) $row->coach_id,
            'coach_nombre' => trim((string) $row->coach_nombre),
            'turno_recurrente_id' => $row->turno_recurrente_id ? (int) $row->turno_recurrente_id : null,
            'tipo_asignacion' => $row->tipo_asignacion,
            'objetivo' => $row->objetivo,
            'dia_semana' => $row->dia_semana ? (int) $row->dia_semana : null,
            'hora_inicio' => $row->hora_inicio,
            'hora_fin' => $row->hora_fin,
        ];
    }

    private function denegarQr(Request $request, array $data, string $motivo, ?object $credencial = null): array
    {
        $this->registrarEventoAcceso($request, $data, $credencial, 'ENTRADA_DENEGADA', 'ERROR', $motivo);

        $response = [
            'permitido' => false,
            'mensaje' => $motivo,
            'persona_id' => $credencial?->persona_id ? (int) $credencial->persona_id : null,
        ];

        $this->auditService->activity($request, 'acceso', 'validar_qr_denegado', [
            'tabla' => 'acceso_credenciales',
            'operacion' => 'U',
            'registro_id' => $credencial?->id,
            'persona_id_afectada' => $credencial?->persona_id,
            'sede_id' => $data['sede_id'] ?? null,
            'datos_despues' => $response,
        ]);

        return $response;
    }

    private function registrarEventoAcceso(
        Request $request,
        array $data,
        ?object $credencial,
        string $tipoEvento,
        string $estado,
        ?string $error = null,
        ?int $asistenciaRegistroId = null
    ): void {
        try {
            DB::table('acceso.eventos')->insert([
                'dispositivo_id' => $data['dispositivo_id'] ?? null,
                'persona_id' => $credencial?->persona_id,
                'fecha_hora' => now(),
                'tipo_evento' => $tipoEvento,
                'estado_procesamiento' => $estado,
                'asistencia_registro_id' => $asistenciaRegistroId,
                'request_id' => $request->attributes->get('request_id') ?? $request->headers->get('X-Request-ID'),
                'payload_raw' => json_encode([
                    'sede_id' => $data['sede_id'] ?? null,
                    'origen' => $data['origen'] ?? null,
                    'credencial_id' => $credencial?->id,
                ], JSON_UNESCAPED_UNICODE),
                'error' => $error,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable) {
            //
        }
    }
}
