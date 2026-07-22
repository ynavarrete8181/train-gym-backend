<?php

namespace App\Services\Notificaciones;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NotificacionGlobalService
{
    public function __construct(private NotificacionService $notificacionService)
    {
    }

    public function generar(?Carbon $fecha = null): array
    {
        $fecha = ($fecha ?? now())->copy()->startOfDay();

        return [
            'cumpleanos' => 0,
            'pagos_pendientes' => $this->generarPagosPendientes($fecha),
            'recordatorios_reserva' => $this->generarRecordatoriosReserva($fecha),
            'membresias_por_vencer' => $this->generarMembresiasPorVencer($fecha),
            'clientes_ausentes' => $this->generarClientesAusentes($fecha),
        ];
    }

    public function generarCumpleanos(Carbon $fecha): int
    {
        if (!$this->hasNotificationTables() || !Schema::hasColumn('core.personas', 'fecha_nacimiento')) {
            return 0;
        }

        $config = $this->configuracionCumpleanos();
        if (!$config['activo']) {
            return 0;
        }

        $personas = DB::table('core.personas as p')
            ->join('seguridad.usuarios as u', 'u.persona_id', '=', 'p.id')
            ->whereNotNull('p.fecha_nacimiento')
            ->where('u.estado', 'ACTIVO')
            ->whereRaw('EXTRACT(MONTH FROM p.fecha_nacimiento) = ?', [$fecha->month])
            ->whereRaw('EXTRACT(DAY FROM p.fecha_nacimiento) = ?', [$fecha->day])
            ->select('p.id', 'p.nombres', 'p.apellidos')
            ->distinct()
            ->get();

        $creadas = 0;

        foreach ($personas as $persona) {
            $nombre = trim((string) $persona->nombres);
            $dedupeKey = sprintf('cumpleanos:%s:%s', $fecha->toDateString(), $persona->id);
            $mensaje = str_replace('{nombre}', $nombre !== '' ? $nombre : 'socio', $config['mensaje']);

            if ($this->existeDedupeKey($dedupeKey)) {
                continue;
            }

            $this->notificacionService->crear([
                'tipo' => 'CUMPLEANOS',
                'titulo' => $config['titulo'],
                'mensaje' => $mensaje,
                'prioridad' => 'NORMAL',
                'canal' => 'APP',
                'personas' => [(int) $persona->id],
                'data' => [
                    'dedupe_key' => $dedupeKey,
                    'fecha' => $fecha->toDateString(),
                    'persona_id' => (int) $persona->id,
                    'categoria' => 'cumpleanos',
                ],
            ]);

            $creadas++;
        }

        return $creadas;
    }

    public function debeGenerarCumpleanosAhora(?Carbon $fecha = null): bool
    {
        $fecha = $fecha ?? now();
        $config = $this->configuracionCumpleanos();

        return $config['activo'] && $fecha->format('H:i') === substr($config['hora_envio'], 0, 5);
    }

    public function configuracionCumpleanos(): array
    {
        $default = [
            'activo' => true,
            'hora_envio' => config('services.notificaciones.globales_hora', '07:00'),
            'titulo' => 'Feliz cumpleanos de parte de Revive',
            'mensaje' => 'Hola {nombre}, todo el equipo Revive te desea un feliz cumpleanos. Que tengas un excelente dia.',
        ];

        if (!$this->tableExists('notificaciones.configuracion_cumpleanos')) {
            return $default;
        }

        $config = DB::table('notificaciones.configuracion_cumpleanos')->where('id', 1)->first();

        if (!$config) {
            return $default;
        }

        return [
            'activo' => (bool) $config->activo,
            'hora_envio' => substr((string) $config->hora_envio, 0, 5),
            'titulo' => (string) ($config->titulo ?: $default['titulo']),
            'mensaje' => (string) ($config->mensaje ?: $default['mensaje']),
        ];
    }

    public function guardarConfiguracionCumpleanos(array $data, ?int $updatedByUserId = null): array
    {
        if (!$this->tableExists('notificaciones.configuracion_cumpleanos')) {
            return $this->configuracionCumpleanos();
        }

        DB::table('notificaciones.configuracion_cumpleanos')->updateOrInsert(
            ['id' => 1],
            [
                'activo' => (bool) ($data['activo'] ?? true),
                'hora_envio' => $data['hora_envio'],
                'titulo' => $data['titulo'],
                'mensaje' => $data['mensaje'],
                'updated_by_usuario_id' => $updatedByUserId,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return $this->configuracionCumpleanos();
    }

    public function historialCumpleanos(int $limit = 100): array
    {
        if (!$this->hasNotificationTables()) {
            return [];
        }

        return DB::table('notificaciones.destinatarios as d')
            ->join('notificaciones.notificaciones as n', 'n.id', '=', 'd.notificacion_id')
            ->leftJoin('core.personas as p', 'p.id', '=', 'd.persona_id')
            ->where('n.tipo', 'CUMPLEANOS')
            ->selectRaw("
                d.id as destinatario_id,
                n.id as notificacion_id,
                d.persona_id,
                CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, '')) as persona_nombre,
                n.titulo,
                n.mensaje,
                n.data,
                d.canal,
                d.estado,
                d.entregada_at,
                d.leida_at,
                d.error,
                n.created_at,
                (
                    SELECT COUNT(*)
                    FROM notificaciones.dispositivos_push dp
                    WHERE dp.activo = TRUE
                      AND (
                        (dp.persona_id IS NOT NULL AND dp.persona_id = d.persona_id)
                        OR (dp.usuario_id IS NOT NULL AND dp.usuario_id = d.usuario_id)
                      )
                ) as dispositivos_activos
            ")
            ->orderByDesc('n.created_at')
            ->limit($limit)
            ->get()
            ->map(function ($item) {
                $data = json_decode($item->data ?? '{}', true) ?: [];

                return [
                    'destinatario_id' => (int) $item->destinatario_id,
                    'notificacion_id' => (int) $item->notificacion_id,
                    'persona_id' => $item->persona_id ? (int) $item->persona_id : null,
                    'persona_nombre' => trim((string) $item->persona_nombre) ?: 'Socio',
                    'titulo' => $item->titulo,
                    'mensaje' => $item->mensaje,
                    'fecha_cumpleanos' => $data['fecha'] ?? null,
                    'canal' => $item->canal,
                    'estado' => $item->estado,
                    'entregada_at' => $item->entregada_at,
                    'leida_at' => $item->leida_at,
                    'error' => $item->error,
                    'created_at' => $item->created_at,
                    'dispositivos_activos' => (int) $item->dispositivos_activos,
                ];
            })
            ->all();
    }

    public function reenviarCumpleanos(int $destinatarioId): bool
    {
        if (!$this->hasNotificationTables()) {
            return false;
        }

        $destinatario = DB::table('notificaciones.destinatarios as d')
            ->join('notificaciones.notificaciones as n', 'n.id', '=', 'd.notificacion_id')
            ->where('d.id', $destinatarioId)
            ->where('n.tipo', 'CUMPLEANOS')
            ->select('d.id')
            ->first();

        if (!$destinatario) {
            return false;
        }

        return DB::table('notificaciones.destinatarios')
            ->where('id', $destinatarioId)
            ->update([
                'estado' => 'PENDIENTE',
                'entregada_at' => null,
                'error' => null,
                'updated_at' => now(),
            ]) > 0;
    }

    private function generarPagosPendientes(Carbon $fecha): int
    {
        if (
            !$this->hasNotificationTables()
            || !Schema::hasColumn('ventas.ventas', 'estado_pago')
            || !Schema::hasColumn('ventas.ventas', 'saldo_pendiente')
        ) {
            return 0;
        }

        $deudas = DB::table('ventas.ventas as v')
            ->join('core.personas as p', 'p.id', '=', 'v.persona_id')
            ->join('seguridad.usuarios as u', 'u.persona_id', '=', 'p.id')
            ->where('u.estado', 'ACTIVO')
            ->whereIn('v.estado_pago', ['PENDIENTE', 'ABONADO'])
            ->where('v.saldo_pendiente', '>', 0)
            ->groupBy('p.id', 'p.nombres')
            ->selectRaw('p.id as persona_id, p.nombres, COUNT(v.id) as cantidad, SUM(v.saldo_pendiente) as saldo_total')
            ->get();

        $creadas = 0;

        foreach ($deudas as $deuda) {
            $saldo = round((float) $deuda->saldo_total, 2);
            $cantidad = (int) $deuda->cantidad;
            $dedupeKey = sprintf('pago-pendiente:%s:%s', $fecha->toDateString(), $deuda->persona_id);

            if ($saldo <= 0 || $this->existeDedupeKey($dedupeKey)) {
                continue;
            }

            $this->notificacionService->crear([
                'tipo' => 'PAGO_PENDIENTE',
                'titulo' => 'Pago pendiente',
                'mensaje' => sprintf(
                    'Tienes %s pendiente%s de pago por $%s.',
                    $cantidad,
                    $cantidad === 1 ? '' : 's',
                    number_format($saldo, 2, '.', '')
                ),
                'prioridad' => 'ALTA',
                'canal' => 'APP',
                'personas' => [(int) $deuda->persona_id],
                'data' => [
                    'dedupe_key' => $dedupeKey,
                    'fecha' => $fecha->toDateString(),
                    'persona_id' => (int) $deuda->persona_id,
                    'categoria' => 'pago_pendiente',
                    'cantidad' => $cantidad,
                    'saldo_total' => $saldo,
                ],
            ]);

            $creadas++;
        }

        return $creadas;
    }

    private function generarRecordatoriosReserva(Carbon $fecha): int
    {
        if (!$this->hasNotificationTables()) {
            return 0;
        }

        $reservas = DB::table('reservas.reservas as r')
            ->join('core.personas as p', 'p.id', '=', 'r.persona_id')
            ->join('core.sedes as s', 's.id', '=', 'r.sede_id')
            ->leftJoin('train_gimnasio.tipos_servicios as ts', 'ts.id', '=', 'r.servicio_id')
            ->whereDate('r.fecha', $fecha->toDateString())
            ->where('r.estado', 'RESERVADA')
            ->where('r.hora_inicio', '>=', now()->format('H:i:s'))
            ->selectRaw("
                r.id,
                r.persona_id,
                r.fecha,
                r.hora_inicio,
                s.nombre as sede_nombre,
                COALESCE(ts.nombre, 'Entrenamiento') as servicio_nombre,
                CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, '')) as cliente_nombre
            ")
            ->orderBy('r.hora_inicio')
            ->get();

        $creadas = 0;

        foreach ($reservas as $reserva) {
            $dedupeKey = sprintf('recordatorio-reserva:%s', $reserva->id);

            if ($this->notificacionService->existeDedupeKey($dedupeKey)) {
                continue;
            }

            $this->notificacionService->crear([
                'tipo' => 'RECORDATORIO_RESERVA',
                'titulo' => 'Recordatorio de reserva',
                'mensaje' => sprintf(
                    'Hoy tienes %s en %s a las %s.',
                    $reserva->servicio_nombre,
                    $reserva->sede_nombre,
                    substr((string) $reserva->hora_inicio, 0, 5)
                ),
                'prioridad' => 'NORMAL',
                'canal' => 'APP',
                'personas' => [(int) $reserva->persona_id],
                'data' => [
                    'dedupe_key' => $dedupeKey,
                    'categoria' => 'reserva',
                    'reserva_id' => (int) $reserva->id,
                    'fecha' => $reserva->fecha,
                    'hora_inicio' => substr((string) $reserva->hora_inicio, 0, 5),
                ],
            ]);

            $creadas++;
        }

        return $creadas;
    }

    private function generarMembresiasPorVencer(Carbon $fecha): int
    {
        if (!$this->hasNotificationTables()) {
            return 0;
        }

        $fechasAviso = collect([7, 3, 1, 0])
            ->mapWithKeys(fn (int $dias) => [$fecha->copy()->addDays($dias)->toDateString() => $dias])
            ->all();

        $membresias = DB::table('socios.socio_membresias as sm')
            ->join('socios.socios as socio', 'socio.id', '=', 'sm.socio_id')
            ->join('core.personas as p', 'p.id', '=', 'socio.persona_id')
            ->join('socios.membresias as m', 'm.id', '=', 'sm.membresia_id')
            ->whereIn('sm.fecha_fin', array_keys($fechasAviso))
            ->selectRaw("
                sm.id,
                sm.fecha_fin,
                socio.persona_id,
                m.nombre as membresia_nombre,
                CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, '')) as cliente_nombre
            ")
            ->get();

        $creadas = 0;

        foreach ($membresias as $membresia) {
            $dias = (int) ($fechasAviso[$membresia->fecha_fin] ?? 0);
            $dedupeKey = sprintf('membresia-vence:%s:%s', $membresia->id, $dias);

            if ($this->notificacionService->existeDedupeKey($dedupeKey)) {
                continue;
            }

            $mensaje = $dias === 0
                ? "Tu membresia {$membresia->membresia_nombre} vence hoy."
                : "Tu membresia {$membresia->membresia_nombre} vence en {$dias} dia" . ($dias === 1 ? '.' : 's.');

            $this->notificacionService->crear([
                'tipo' => 'MEMBRESIA_POR_VENCER',
                'titulo' => 'Membresia por vencer',
                'mensaje' => $mensaje,
                'prioridad' => $dias <= 1 ? 'ALTA' : 'NORMAL',
                'canal' => 'APP',
                'personas' => [(int) $membresia->persona_id],
                'data' => [
                    'dedupe_key' => $dedupeKey,
                    'categoria' => 'membresia',
                    'socio_membresia_id' => (int) $membresia->id,
                    'fecha_fin' => $membresia->fecha_fin,
                    'dias_restantes' => $dias,
                ],
            ]);

            $creadas++;
        }

        return $creadas;
    }

    private function generarClientesAusentes(Carbon $fecha): int
    {
        if (!$this->hasNotificationTables()) {
            return 0;
        }

        $limite = $fecha->copy()->subDays(5)->endOfDay()->toDateTimeString();

        $clientes = DB::table('staff.cliente_asignaciones as ca')
            ->join('staff.perfiles as sp', 'sp.id', '=', 'ca.coach_id')
            ->join('core.personas as cliente', 'cliente.id', '=', 'ca.persona_id')
            ->join('core.personas as coach_persona', 'coach_persona.id', '=', 'sp.persona_id')
            ->leftJoin('asistencia.registros as ar', function ($join) {
                $join->on('ar.persona_id', '=', 'ca.persona_id')
                    ->where('ar.estado', '=', 'PERMITIDO');
            })
            ->where('ca.estado', 'ACTIVO')
            ->whereNotNull('sp.usuario_id')
            ->groupBy('ca.id', 'ca.persona_id', 'sp.usuario_id', 'cliente.nombres', 'cliente.apellidos', 'coach_persona.nombres', 'coach_persona.apellidos')
            ->havingRaw('MAX(ar.fecha_hora) IS NULL OR MAX(ar.fecha_hora) <= ?', [$limite])
            ->selectRaw("
                ca.id as asignacion_id,
                ca.persona_id,
                sp.usuario_id as coach_usuario_id,
                CONCAT(COALESCE(cliente.nombres, ''), ' ', COALESCE(cliente.apellidos, '')) as cliente_nombre,
                CONCAT(COALESCE(coach_persona.nombres, ''), ' ', COALESCE(coach_persona.apellidos, '')) as coach_nombre,
                MAX(ar.fecha_hora) as ultima_asistencia
            ")
            ->get();

        $creadas = 0;

        foreach ($clientes as $cliente) {
            $dedupeKey = sprintf('cliente-ausente:%s:%s', $fecha->toDateString(), $cliente->asignacion_id);

            if ($this->notificacionService->existeDedupeKey($dedupeKey)) {
                continue;
            }

            $ultima = $cliente->ultima_asistencia
                ? Carbon::parse($cliente->ultima_asistencia)->format('Y-m-d')
                : 'sin asistencias registradas';

            $this->notificacionService->crear([
                'tipo' => 'CLIENTE_AUSENTE',
                'titulo' => 'Cliente ausente',
                'mensaje' => trim((string) $cliente->cliente_nombre) . " esta ausente. Ultima asistencia: {$ultima}.",
                'prioridad' => 'ALTA',
                'canal' => 'APP',
                'usuarios' => [(int) $cliente->coach_usuario_id],
                'data' => [
                    'dedupe_key' => $dedupeKey,
                    'categoria' => 'coach',
                    'asignacion_id' => (int) $cliente->asignacion_id,
                    'persona_id' => (int) $cliente->persona_id,
                    'ultima_asistencia' => $cliente->ultima_asistencia,
                ],
            ]);

            $creadas++;
        }

        return $creadas;
    }

    private function existeDedupeKey(string $dedupeKey): bool
    {
        return $this->notificacionService->existeDedupeKey($dedupeKey);
    }

    private function hasNotificationTables(): bool
    {
        $row = DB::selectOne("SELECT to_regclass('notificaciones.notificaciones') as notificaciones");

        return !empty($row?->notificaciones);
    }

    private function tableExists(string $table): bool
    {
        $row = DB::selectOne('SELECT to_regclass(?) as table_name', [$table]);

        return !empty($row?->table_name);
    }
}
