<?php

namespace App\Services\Notificaciones;

use App\Jobs\Notificaciones\ProcesarLoteInvitacionesAccesoJob;
use App\Models\User;
use App\Services\Concerns\RegistraAuditoria;
use App\Services\Seguridad\AvisoUsuarioService;
use Illuminate\Support\Facades\DB;
use Throwable;

class LoteInvitacionAccesoService
{
    use RegistraAuditoria;

    public function __construct(
        private readonly NotificacionUsuarioService $notificacionService,
        private readonly AvisoUsuarioService $avisoService,
    ) {}

    public function crear(array $usuarios, bool $forzar, ?int $solicitadoPor): array
    {
        $ids = collect($usuarios)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $existentes = User::query()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id);

        if ($existentes->isEmpty()) {
            throw new \RuntimeException('No existen usuarios válidos para procesar.');
        }

        $idLote = DB::transaction(function () use ($existentes, $forzar, $solicitadoPor): int {
            $id = (int) DB::table('notificaciones.lotes_acceso')->insertGetId([
                'estado' => 'EN_COLA',
                'total' => $existentes->count(),
                'procesados' => 0,
                'enviadas' => 0,
                'en_cola' => 0,
                'errores' => 0,
                'forzar' => $forzar,
                'solicitado_por' => $solicitadoPor,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($existentes as $usuarioId) {
                DB::table('notificaciones.lote_acceso_detalles')->insert([
                    'lote_id' => $id,
                    'usuario_id' => $usuarioId,
                    'estado' => 'PENDIENTE',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $id;
        });

        ProcesarLoteInvitacionesAccesoJob::dispatch($idLote)->afterCommit();

        $this->auditar('notificaciones', 'CREAR', 'notificaciones.lotes_acceso', $idLote, null, null, "Lote de invitaciones de acceso creado para {$existentes->count()} usuario(s).");

        return $this->estado($idLote);
    }

    public function procesarBloque(int $idLote, int $limite = 10): void
    {
        $lote = DB::table('notificaciones.lotes_acceso')->where('id', $idLote)->first();
        if (! $lote || in_array($lote->estado, ['COMPLETADO', 'COMPLETADO_CON_ERRORES', 'ERROR'], true)) {
            return;
        }

        DB::table('notificaciones.lotes_acceso')->where('id', $idLote)->update([
            'estado' => 'PROCESANDO',
            'iniciado_at' => $lote->iniciado_at ?: now(),
            'updated_at' => now(),
        ]);

        $this->sincronizarEstadosEnvio($idLote);

        $detalles = DB::table('notificaciones.lote_acceso_detalles')
            ->where('lote_id', $idLote)
            ->where('estado', 'PENDIENTE')
            ->orderBy('id')
            ->limit($limite)
            ->get();

        foreach ($detalles as $detalle) {
            DB::table('notificaciones.lote_acceso_detalles')->where('id', $detalle->id)->update([
                'estado' => 'PROCESANDO',
                'updated_at' => now(),
            ]);

            $usuario = User::find($detalle->usuario_id);
            if (! $usuario) {
                DB::table('notificaciones.lote_acceso_detalles')->where('id', $detalle->id)->update([
                    'estado' => 'ERROR',
                    'error' => 'El usuario ya no existe.',
                    'updated_at' => now(),
                ]);
                continue;
            }

            try {
                $idNotificacion = $this->notificacionService->solicitar(
                    $usuario,
                    $lote->solicitado_por ? (int) $lote->solicitado_por : null,
                    (bool) $lote->forzar,
                );

                DB::table('notificaciones.lote_acceso_detalles')->where('id', $detalle->id)->update([
                    'estado' => 'EN_COLA',
                    'notificacion_id' => $idNotificacion,
                    'error' => null,
                    'updated_at' => now(),
                ]);
            } catch (Throwable $e) {
                DB::table('notificaciones.lote_acceso_detalles')->where('id', $detalle->id)->update([
                    'estado' => 'ERROR',
                    'error' => $e->getMessage(),
                    'updated_at' => now(),
                ]);
            }
        }

        $this->sincronizarEstadosEnvio($idLote);
        $this->recalcular($idLote);

        if (DB::table('notificaciones.lote_acceso_detalles')->where('lote_id', $idLote)->where('estado', 'PENDIENTE')->exists()) {
            ProcesarLoteInvitacionesAccesoJob::dispatch($idLote);
            return;
        }

        if (DB::table('notificaciones.lote_acceso_detalles')->where('lote_id', $idLote)->whereIn('estado', ['EN_COLA', 'PROCESANDO'])->exists()) {
            ProcesarLoteInvitacionesAccesoJob::dispatch($idLote)->delay(now()->addSeconds(2));
            return;
        }

        $this->finalizar($idLote);
    }

    public function estado(int $idLote): array
    {
        $lote = DB::table('notificaciones.lotes_acceso')->where('id', $idLote)->first();
        abort_unless($lote, 404);

        $detalles = DB::table('notificaciones.lote_acceso_detalles as d')
            ->leftJoin('seguridad.users as u', 'u.id', '=', 'd.usuario_id')
            ->where('d.lote_id', $idLote)
            ->orderBy('d.id')
            ->get(['d.usuario_id', 'u.name', 'u.email', 'd.estado', 'd.notificacion_id', 'd.error'])
            ->map(fn ($d) => (array) $d)->all();

        $total = max(1, (int) $lote->total);

        return [
            'id' => (int) $lote->id,
            'estado' => $lote->estado,
            'total' => (int) $lote->total,
            'procesados' => (int) $lote->procesados,
            'enviadas' => (int) $lote->enviadas,
            'en_cola' => (int) $lote->en_cola,
            'errores' => (int) $lote->errores,
            'pendientes' => max(0, (int) $lote->total - (int) $lote->procesados - (int) $lote->en_cola),
            'porcentaje' => min(100, (int) round(((int) $lote->procesados / $total) * 100)),
            'forzar' => (bool) $lote->forzar,
            'error_general' => $lote->error_general,
            'iniciado_at' => $lote->iniciado_at,
            'finalizado_at' => $lote->finalizado_at,
            'detalles' => $detalles,
        ];
    }

    public function recientes(?int $solicitadoPor, int $limite = 10): array
    {
        return DB::table('notificaciones.lotes_acceso')
            ->when($solicitadoPor, fn ($q) => $q->where('solicitado_por', $solicitadoPor))
            ->orderByDesc('id')
            ->limit($limite)
            ->get()
            ->map(fn ($l) => [
                'id' => (int) $l->id,
                'estado' => $l->estado,
                'total' => (int) $l->total,
                'procesados' => (int) $l->procesados,
                'enviadas' => (int) $l->enviadas,
                'en_cola' => (int) $l->en_cola,
                'errores' => (int) $l->errores,
                'created_at' => $l->created_at,
                'finalizado_at' => $l->finalizado_at,
            ])->all();
    }

    public function marcarErrorGeneral(int $idLote, string $mensaje): void
    {
        DB::table('notificaciones.lote_acceso_detalles')
            ->where('lote_id', $idLote)
            ->where('estado', 'PROCESANDO')
            ->update(['estado' => 'PENDIENTE', 'updated_at' => now()]);

        DB::table('notificaciones.lotes_acceso')->where('id', $idLote)->update([
            'estado' => 'ERROR',
            'error_general' => $mensaje,
            'finalizado_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function sincronizarEstadosEnvio(int $idLote): void
    {
        $detalles = DB::table('notificaciones.lote_acceso_detalles as d')
            ->join('notificaciones.notificaciones as n', 'n.id', '=', 'd.notificacion_id')
            ->where('d.lote_id', $idLote)
            ->whereIn('d.estado', ['EN_COLA', 'PROCESANDO'])
            ->get(['d.id', 'n.id as notificacion_id', 'n.estado']);

        foreach ($detalles as $detalle) {
            if ($detalle->estado === 'ENVIADA') {
                DB::table('notificaciones.lote_acceso_detalles')->where('id', $detalle->id)->update([
                    'estado' => 'ENVIADA',
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

                DB::table('notificaciones.lote_acceso_detalles')->where('id', $detalle->id)->update([
                    'estado' => 'ERROR',
                    'error' => $mensaje ?: 'No se pudo enviar la invitación.',
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function recalcular(int $idLote): void
    {
        $detalles = DB::table('notificaciones.lote_acceso_detalles')->where('lote_id', $idLote)->get();

        DB::table('notificaciones.lotes_acceso')->where('id', $idLote)->update([
            'procesados' => $detalles->whereIn('estado', ['ENVIADA', 'ERROR'])->count(),
            'enviadas' => $detalles->where('estado', 'ENVIADA')->count(),
            'en_cola' => $detalles->whereIn('estado', ['EN_COLA', 'PROCESANDO'])->count(),
            'errores' => $detalles->where('estado', 'ERROR')->count(),
            'updated_at' => now(),
        ]);
    }

    private function finalizar(int $idLote): void
    {
        $this->sincronizarEstadosEnvio($idLote);
        $this->recalcular($idLote);

        $lote = DB::table('notificaciones.lotes_acceso')->where('id', $idLote)->first();
        $estado = ((int) $lote->errores > 0) ? 'COMPLETADO_CON_ERRORES' : 'COMPLETADO';

        DB::table('notificaciones.lotes_acceso')->where('id', $idLote)->update([
            'estado' => $estado,
            'finalizado_at' => now(),
            'updated_at' => now(),
        ]);

        if ($lote->solicitado_por) {
            $this->avisoService->registrar(
                (int) $lote->solicitado_por,
                'LOTE_INVITACIONES_ACCESO',
                'Invitaciones de acceso finalizadas',
                "{$lote->enviadas} enviada(s) y {$lote->errores} con error.",
                'NOTIFICACIONES-ACCESO',
                'LOTE_INVITACIONES_ACCESO',
                $idLote,
            );
        }
    }
}
