<?php

namespace App\Services\Notificaciones;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminNotificationService
{
    public function __construct(private NotificacionService $notificacionService)
    {
    }

    public function asistenciaRegistrada(array $registro, ?int $createdByUserId = null): void
    {
        $id = (int) ($registro['id'] ?? 0);
        $persona = trim((string) ($registro['persona_nombre'] ?? 'Cliente'));
        $sede = trim((string) ($registro['sede_nombre'] ?? 'Sede'));
        $fecha = (string) ($registro['fecha_hora'] ?? now());

        $this->notificarAdmins([
            'tipo' => 'ASISTENCIA_REGISTRADA',
            'titulo' => 'Asistencia registrada',
            'mensaje' => "{$persona} registró asistencia en {$sede}.",
            'prioridad' => 'NORMAL',
            'data' => [
                'dedupe_key' => "admin:asistencia:{$id}",
                'modulo' => 'asistencia',
                'asistencia_id' => $id,
                'persona_id' => $registro['persona_id'] ?? null,
                'sede_id' => $registro['sede_id'] ?? null,
                'fecha_hora' => $fecha,
                'ruta_web' => '/reportes/asistencias',
            ],
        ], $createdByUserId);
    }

    public function ventaRegistrada(object|array $venta, ?int $createdByUserId = null, bool $actualizada = false): void
    {
        $venta = $this->toArray($venta);
        $id = (int) ($venta['id'] ?? 0);
        $referencia = trim((string) ($venta['referencia'] ?? ('Venta #' . $id)));
        $sede = trim((string) ($venta['sede_nombre'] ?? 'Sede'));
        $cajero = trim((string) ($venta['cajero_nombre'] ?? 'Caja'));
        $total = number_format((float) ($venta['total'] ?? 0), 2, ',', '.');
        $accion = $actualizada ? 'actualizó' : 'registró';

        $this->notificarAdmins([
            'tipo' => $actualizada ? 'VENTA_ACTUALIZADA' : 'VENTA_REGISTRADA',
            'titulo' => $actualizada ? 'Venta actualizada' : 'Venta generada',
            'mensaje' => "{$cajero} {$accion} {$referencia} por $" . $total . " en {$sede}.",
            'prioridad' => 'NORMAL',
            'data' => array_filter([
                'dedupe_key' => $actualizada ? null : "admin:venta:new:{$id}",
                'modulo' => 'ventas',
                'venta_id' => $id,
                'referencia' => $referencia,
                'sede_id' => $venta['sede_id'] ?? null,
                'total' => (float) ($venta['total'] ?? 0),
                'estado_pago' => $venta['estado_pago'] ?? null,
                'ruta_web' => '/gimnasio/ventas-realizadas',
            ], fn ($value) => $value !== null),
        ], $createdByUserId);
    }

    public function devolucionRegistrada(object|array $devolucion, object|array|null $venta, ?int $createdByUserId = null): void
    {
        $devolucion = $this->toArray($devolucion);
        $venta = $this->toArray($venta ?? []);
        $id = (int) ($devolucion['id'] ?? 0);
        $ventaId = (int) ($devolucion['venta_id'] ?? ($venta['id'] ?? 0));
        $tipo = strtoupper((string) ($devolucion['tipo'] ?? 'DEVOLUCION'));
        $total = number_format((float) ($devolucion['monto_total'] ?? 0), 2, ',', '.');
        $referencia = trim((string) ($venta['referencia'] ?? ('Venta #' . $ventaId)));

        $this->notificarAdmins([
            'tipo' => $tipo === 'ANULACION' ? 'VENTA_ANULADA' : 'DEVOLUCION_REGISTRADA',
            'titulo' => $tipo === 'ANULACION' ? 'Venta anulada' : 'Devolución registrada',
            'mensaje' => "{$referencia} tuvo " . strtolower($tipo) . " por $" . $total . '.',
            'prioridad' => 'ALTA',
            'data' => [
                'dedupe_key' => "admin:devolucion:{$id}",
                'modulo' => 'ventas',
                'devolucion_id' => $id,
                'venta_id' => $ventaId,
                'tipo' => $tipo,
                'monto_total' => (float) ($devolucion['monto_total'] ?? 0),
                'sede_id' => $venta['sede_id'] ?? null,
                'ruta_web' => '/gimnasio/ventas-devoluciones',
            ],
        ], $createdByUserId);
    }

    public function reservaCreada(array $reserva, ?int $createdByUserId = null): void
    {
        $id = (int) ($reserva['id'] ?? 0);
        $persona = trim((string) ($reserva['persona_nombre'] ?? 'Cliente'));
        $sede = trim((string) ($reserva['sede_nombre'] ?? 'Sede'));
        $fecha = trim((string) ($reserva['fecha'] ?? ''));
        $hora = substr((string) ($reserva['hora_inicio'] ?? ''), 0, 5);

        $this->notificarAdmins([
            'tipo' => 'RESERVA_ADMIN_CONFIRMADA',
            'titulo' => 'Reserva confirmada',
            'mensaje' => "{$persona} reservó en {$sede} para {$fecha} {$hora}.",
            'prioridad' => 'NORMAL',
            'data' => [
                'dedupe_key' => "admin:reserva:creada:{$id}",
                'modulo' => 'reservas',
                'reserva_id' => $id,
                'persona_id' => $reserva['persona_id'] ?? null,
                'sede_id' => $reserva['sede_id'] ?? null,
                'fecha' => $fecha,
                'hora_inicio' => $reserva['hora_inicio'] ?? null,
                'ruta_web' => '/gimnasio/reservas',
            ],
        ], $createdByUserId);
    }

    public function reservaCancelada(array $reserva, ?string $motivo = null, ?int $createdByUserId = null): void
    {
        $id = (int) ($reserva['id'] ?? 0);
        $persona = trim((string) ($reserva['persona_nombre'] ?? 'Cliente'));
        $sede = trim((string) ($reserva['sede_nombre'] ?? 'Sede'));
        $fecha = trim((string) ($reserva['fecha'] ?? ''));
        $hora = substr((string) ($reserva['hora_inicio'] ?? ''), 0, 5);
        $detalleMotivo = $motivo ? " Motivo: {$motivo}." : '';

        $this->notificarAdmins([
            'tipo' => 'RESERVA_ADMIN_CANCELADA',
            'titulo' => 'Reserva cancelada',
            'mensaje' => "{$persona} canceló su reserva en {$sede} para {$fecha} {$hora}.{$detalleMotivo}",
            'prioridad' => 'ALTA',
            'data' => [
                'dedupe_key' => "admin:reserva:cancelada:{$id}",
                'modulo' => 'reservas',
                'reserva_id' => $id,
                'persona_id' => $reserva['persona_id'] ?? null,
                'sede_id' => $reserva['sede_id'] ?? null,
                'fecha' => $fecha,
                'hora_inicio' => $reserva['hora_inicio'] ?? null,
                'motivo' => $motivo,
                'ruta_web' => '/gimnasio/reservas',
            ],
        ], $createdByUserId);
    }

    public function notificarAdmins(array $payload, ?int $createdByUserId = null): void
    {
        try {
            $dedupeKey = (string) ($payload['data']['dedupe_key'] ?? '');
            if ($dedupeKey !== '' && $this->notificacionService->existeDedupeKey($dedupeKey)) {
                return;
            }

            $admins = $this->adminUserIds();
            if (empty($admins) && $createdByUserId) {
                $admins = [(int) $createdByUserId];
            }

            if (empty($admins)) {
                return;
            }

            $this->notificacionService->crear([
                'tipo' => $payload['tipo'] ?? 'ADMIN_ALERTA',
                'titulo' => $payload['titulo'],
                'mensaje' => $payload['mensaje'],
                'data' => $payload['data'] ?? [],
                'prioridad' => $payload['prioridad'] ?? 'NORMAL',
                'canal' => 'APP',
                'usuarios' => $admins,
            ], $createdByUserId);
        } catch (\Throwable $exception) {
            Log::warning('No se pudo crear notificacion administrativa.', [
                'tipo' => $payload['tipo'] ?? null,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function adminUserIds(): array
    {
        if (!$this->tableExists('seguridad.usuarios') || !$this->tableExists('seguridad.usuario_roles') || !$this->tableExists('seguridad.roles')) {
            return [];
        }

        return DB::table('seguridad.usuarios as u')
            ->join('seguridad.usuario_roles as ur', 'ur.usuario_id', '=', 'u.id')
            ->join('seguridad.roles as r', 'r.id', '=', 'ur.rol_id')
            ->where('u.estado', 'ACTIVO')
            ->where(function ($query) {
                $query->whereIn(DB::raw('UPPER(COALESCE(r.codigo, \'\'))'), ['ADMINISTRADOR', 'ADMIN', 'SUPERADMIN', 'SUPER_ADMIN'])
                    ->orWhereRaw("UPPER(COALESCE(r.nombre, '')) LIKE '%ADMIN%'");
            })
            ->pluck('u.id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function tableExists(string $table): bool
    {
        $row = DB::selectOne('SELECT to_regclass(?) as table_name', [$table]);

        return !empty($row?->table_name);
    }

    private function toArray(object|array $value): array
    {
        return is_array($value) ? $value : (array) $value;
    }
}
