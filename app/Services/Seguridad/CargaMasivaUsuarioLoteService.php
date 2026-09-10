<?php

namespace App\Services\Seguridad;

use App\Jobs\Seguridad\ProcesarCargaMasivaUsuariosJob;
use App\Services\Concerns\RegistraAuditoria;
use Illuminate\Support\Facades\DB;

class CargaMasivaUsuarioLoteService
{
    use RegistraAuditoria;

    public function __construct(
        private readonly CargaMasivaUsuarioService $cargaService,
        private readonly AvisoUsuarioService $avisoService,
        private readonly TiempoRealUsuarioService $tiempoRealService,
    ) {}

    public function crear(array $filas, bool $notificar, ?int $solicitadoPor): array
    {
        $validadas = $this->cargaService->validar($filas);
        $idCarga = DB::transaction(function () use ($filas, $validadas, $notificar, $solicitadoPor): int {
            $erroresIniciales = collect($validadas)->where('estado', 'error')->count();
            $id = (int) DB::table('seguridad.cargas_masivas_usuario')->insertGetId([
                'estado' => 'EN_COLA',
                'total' => count($filas),
                'procesados' => $erroresIniciales,
                'creados' => 0,
                'errores' => $erroresIniciales,
                'notificaciones_encoladas' => 0,
                'notificaciones_enviadas' => 0,
                'notificaciones_error' => 0,
                'notificar' => $notificar,
                'solicitado_por' => $solicitadoPor,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($validadas as $indice => $resultado) {
                DB::table('seguridad.carga_masiva_usuario_detalles')->insert([
                    'carga_id' => $id,
                    'fila' => (int) $resultado['fila'],
                    'datos' => json_encode($filas[$indice], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'estado' => $resultado['estado'] === 'valido' ? 'PENDIENTE' : 'ERROR',
                    'error' => $resultado['estado'] === 'error' ? implode(' | ', $resultado['errores']) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $id;
        });

        ProcesarCargaMasivaUsuariosJob::dispatch($idCarga)->onConnection('database')->afterCommit();

        $this->auditar('seguridad', 'CREAR', 'seguridad.cargas_masivas_usuario', $idCarga, null, null, 'Carga masiva de usuarios iniciada para '.count($filas).' fila(s).');

        return $this->estado($idCarga);
    }

    public function procesarBloque(int $idCarga, int $limite = 10): void
    {
        $carga = DB::table('seguridad.cargas_masivas_usuario')->where('id', $idCarga)->first();
        if (! $carga || in_array($carga->estado, ['COMPLETADO', 'COMPLETADO_CON_ERRORES', 'ERROR'], true)) {
            return;
        }

        DB::table('seguridad.cargas_masivas_usuario')->where('id', $idCarga)->update([
            'estado' => $carga->estado === 'NOTIFICANDO' ? 'NOTIFICANDO' : 'PROCESANDO',
            'iniciado_at' => $carga->iniciado_at ?: now(),
            'updated_at' => now(),
        ]);

        $this->sincronizarNotificaciones($idCarga);

        $detalles = DB::table('seguridad.carga_masiva_usuario_detalles')
            ->where('carga_id', $idCarga)
            ->where('estado', 'PENDIENTE')
            ->orderBy('fila')
            ->limit($limite)
            ->get();

        foreach ($detalles as $detalle) {
            DB::table('seguridad.carga_masiva_usuario_detalles')->where('id', $detalle->id)->update([
                'estado' => 'PROCESANDO',
                'updated_at' => now(),
            ]);

            $fila = json_decode($detalle->datos, true) ?: [];
            $resultado = $this->cargaService->procesar(
                [$fila],
                (bool) $carga->notificar,
                $carga->solicitado_por ? (int) $carga->solicitado_por : null,
            );

            $creado = $resultado['creados'][0] ?? null;
            $error = $resultado['errores'][0] ?? null;

            if ($creado) {
                $notificacionId = null;
                if (($creado['estado_notificacion'] ?? null) === 'EN_COLA') {
                    $notificacionId = DB::table('notificaciones.notificaciones')
                        ->where('usuario_id', $creado['id'])
                        ->where('tipo', 'INVITACION_USUARIO')
                        ->whereIn('estado', ['EN_COLA', 'PROCESANDO', 'ENVIADA'])
                        ->latest('id')
                        ->value('id');
                }

                DB::table('seguridad.carga_masiva_usuario_detalles')->where('id', $detalle->id)->update([
                    'estado' => 'CREADO',
                    'usuario_id' => $creado['id'],
                    'notificacion_id' => $notificacionId,
                    'estado_notificacion' => $creado['estado_notificacion'] ?? null,
                    'error' => $creado['error_notificacion'] ?? null,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('seguridad.carga_masiva_usuario_detalles')->where('id', $detalle->id)->update([
                    'estado' => 'ERROR',
                    'error' => implode(' | ', $error['errores'] ?? ['No se pudo crear el usuario.']),
                    'updated_at' => now(),
                ]);
            }
        }

        $this->sincronizarNotificaciones($idCarga);
        $this->recalcular($idCarga);
        $this->emitirEstado($idCarga);

        if (DB::table('seguridad.carga_masiva_usuario_detalles')->where('carga_id', $idCarga)->where('estado', 'PENDIENTE')->exists()) {
            ProcesarCargaMasivaUsuariosJob::dispatch($idCarga)->onConnection('database');
            return;
        }

        $carga = DB::table('seguridad.cargas_masivas_usuario')->where('id', $idCarga)->first();
        if ($carga->notificar && DB::table('seguridad.carga_masiva_usuario_detalles')
            ->where('carga_id', $idCarga)
            ->whereIn('estado_notificacion', ['PENDIENTE', 'EN_COLA', 'PROCESANDO'])
            ->exists()) {
            DB::table('seguridad.cargas_masivas_usuario')->where('id', $idCarga)->update([
                'estado' => 'NOTIFICANDO',
                'updated_at' => now(),
            ]);
            $this->emitirEstado($idCarga);
            ProcesarCargaMasivaUsuariosJob::dispatch($idCarga)->onConnection('database')->delay(now()->addSeconds(2));
            return;
        }

        $this->finalizar($idCarga);
    }

    public function marcarErrorGeneral(int $idCarga, string $mensaje): void
    {
        DB::table('seguridad.carga_masiva_usuario_detalles')
            ->where('carga_id', $idCarga)
            ->where('estado', 'PROCESANDO')
            ->update(['estado' => 'PENDIENTE', 'updated_at' => now()]);

        DB::table('seguridad.cargas_masivas_usuario')->where('id', $idCarga)->update([
            'estado' => 'ERROR',
            'error_general' => $mensaje,
            'finalizado_at' => now(),
            'updated_at' => now(),
        ]);

        $this->emitirEstado($idCarga);
    }

    public function estado(int $idCarga): array
    {
        $carga = DB::table('seguridad.cargas_masivas_usuario')->where('id', $idCarga)->first();
        abort_unless($carga, 404);

        $detalles = DB::table('seguridad.carga_masiva_usuario_detalles')
            ->where('carga_id', $idCarga)
            ->orderBy('fila')
            ->get(['fila', 'estado', 'usuario_id', 'notificacion_id', 'estado_notificacion', 'error', 'datos'])
            ->map(function ($detalle): array {
                $datos = json_decode($detalle->datos, true) ?: [];
                return [
                    'fila' => (int) $detalle->fila,
                    'estado' => $detalle->estado,
                    'usuario_id' => $detalle->usuario_id,
                    'notificacion_id' => $detalle->notificacion_id,
                    'estado_notificacion' => $detalle->estado_notificacion,
                    'error' => $detalle->error,
                    'nombre' => trim(($datos['nombres'] ?? $datos['nombre'] ?? '').' '.($datos['apellidos'] ?? '')),
                    'email' => $datos['email'] ?? $datos['correo'] ?? null,
                ];
            })->all();

        $totalUsuarios = max(1, (int) $carga->total);
        $trabajoTotal = $totalUsuarios;
        $trabajoTerminado = (int) $carga->procesados;

        if ($carga->notificar) {
            $notificacionesEsperadas = max(0, (int) $carga->total - (int) $carga->errores);
            $trabajoTotal += $notificacionesEsperadas;
            $trabajoTerminado += (int) $carga->notificaciones_enviadas + (int) $carga->notificaciones_error;
        }

        return [
            'id' => (int) $carga->id,
            'estado' => $carga->estado,
            'total' => (int) $carga->total,
            'procesados' => (int) $carga->procesados,
            'creados' => (int) $carga->creados,
            'errores' => (int) $carga->errores,
            'notificar' => (bool) $carga->notificar,
            'notificaciones_encoladas' => (int) $carga->notificaciones_encoladas,
            'notificaciones_enviadas' => (int) $carga->notificaciones_enviadas,
            'notificaciones_error' => (int) $carga->notificaciones_error,
            'porcentaje' => min(100, (int) round(($trabajoTerminado / max(1, $trabajoTotal)) * 100)),
            'error_general' => $carga->error_general,
            'iniciado_at' => $carga->iniciado_at,
            'finalizado_at' => $carga->finalizado_at,
            'detalles' => $detalles,
        ];
    }

    public function recientes(?int $solicitadoPor, int $limite = 10): array
    {
        return DB::table('seguridad.cargas_masivas_usuario')
            ->when($solicitadoPor, fn ($q) => $q->where('solicitado_por', $solicitadoPor))
            ->orderByDesc('id')
            ->limit($limite)
            ->get()
            ->map(fn ($carga) => [
                'id' => (int) $carga->id,
                'estado' => $carga->estado,
                'total' => (int) $carga->total,
                'procesados' => (int) $carga->procesados,
                'creados' => (int) $carga->creados,
                'errores' => (int) $carga->errores,
                'notificar' => (bool) $carga->notificar,
                'created_at' => $carga->created_at,
                'finalizado_at' => $carga->finalizado_at,
            ])->all();
    }

    private function sincronizarNotificaciones(int $idCarga): void
    {
        $detalles = DB::table('seguridad.carga_masiva_usuario_detalles as d')
            ->join('notificaciones.notificaciones as n', 'n.id', '=', 'd.notificacion_id')
            ->where('d.carga_id', $idCarga)
            ->whereNotNull('d.notificacion_id')
            ->whereIn('d.estado_notificacion', ['PENDIENTE', 'EN_COLA', 'PROCESANDO'])
            ->get(['d.id', 'n.id as notificacion_id', 'n.estado']);

        foreach ($detalles as $detalle) {
            if ($detalle->estado === 'ENVIADA') {
                DB::table('seguridad.carga_masiva_usuario_detalles')->where('id', $detalle->id)->update([
                    'estado_notificacion' => 'ENVIADA',
                    'error' => null,
                    'updated_at' => now(),
                ]);
                continue;
            }

            if ($detalle->estado === 'ERROR') {
                $mensaje = DB::table('notificaciones.intentos')
                    ->where('notificacion_id', $detalle->notificacion_id)
                    ->orderByDesc('numero_intento')
                    ->value('mensaje_error');

                DB::table('seguridad.carga_masiva_usuario_detalles')->where('id', $detalle->id)->update([
                    'estado_notificacion' => 'ERROR',
                    'error' => $mensaje ?: 'No se pudo enviar la invitación.',
                    'updated_at' => now(),
                ]);
                continue;
            }

            if ($detalle->estado === 'PROCESANDO') {
                DB::table('seguridad.carga_masiva_usuario_detalles')->where('id', $detalle->id)->update([
                    'estado_notificacion' => 'PROCESANDO',
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function recalcular(int $idCarga): void
    {
        $detalles = DB::table('seguridad.carga_masiva_usuario_detalles')->where('carga_id', $idCarga)->get();

        DB::table('seguridad.cargas_masivas_usuario')->where('id', $idCarga)->update([
            'procesados' => $detalles->whereIn('estado', ['CREADO', 'ERROR'])->count(),
            'creados' => $detalles->where('estado', 'CREADO')->count(),
            'errores' => $detalles->where('estado', 'ERROR')->count(),
            'notificaciones_encoladas' => $detalles->whereIn('estado_notificacion', ['PENDIENTE', 'EN_COLA', 'PROCESANDO'])->count(),
            'notificaciones_enviadas' => $detalles->where('estado_notificacion', 'ENVIADA')->count(),
            'notificaciones_error' => $detalles->where('estado_notificacion', 'ERROR')->count(),
            'updated_at' => now(),
        ]);
    }

    private function emitirEstado(int $idCarga): void
    {
        $solicitadoPor = DB::table('seguridad.cargas_masivas_usuario')
            ->where('id', $idCarga)
            ->value('solicitado_por');

        if (! $solicitadoPor) {
            return;
        }

        $datos = $this->estado($idCarga);
        if (! in_array($datos['estado'], ['COMPLETADO', 'COMPLETADO_CON_ERRORES', 'ERROR'], true)) {
            unset($datos['detalles']);
        }

        $this->tiempoRealService->emitir(
            (int) $solicitadoPor,
            'CARGA_MASIVA_USUARIOS',
            $datos,
        );
    }

    private function finalizar(int $idCarga): void
    {
        $this->sincronizarNotificaciones($idCarga);
        $this->recalcular($idCarga);
        $carga = DB::table('seguridad.cargas_masivas_usuario')->where('id', $idCarga)->first();

        $estado = ((int) $carga->errores > 0 || (int) $carga->notificaciones_error > 0)
            ? 'COMPLETADO_CON_ERRORES'
            : 'COMPLETADO';

        DB::table('seguridad.cargas_masivas_usuario')->where('id', $idCarga)->update([
            'estado' => $estado,
            'finalizado_at' => now(),
            'updated_at' => now(),
        ]);

        $this->emitirEstado($idCarga);

        if ($carga->solicitado_por) {
            $mensaje = $carga->notificar
                ? "{$carga->creados} usuario(s) creado(s), {$carga->notificaciones_enviadas} invitación(es) enviada(s) y {$carga->notificaciones_error} con error."
                : "{$carga->creados} usuario(s) creado(s) y {$carga->errores} con error.";

            $this->avisoService->registrar(
                (int) $carga->solicitado_por,
                'CARGA_MASIVA_USUARIOS',
                'Carga masiva de usuarios finalizada',
                $mensaje,
                'SEGURIDAD-USUARIOS',
                'CARGA_MASIVA_USUARIOS',
                $idCarga,
            );
        }
    }
}
