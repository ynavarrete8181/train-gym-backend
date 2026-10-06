<?php

namespace App\Services\Ventas;

use App\Services\Notificaciones\ExpoPushService;
use App\Services\Seguridad\AvisoUsuarioService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CierreAutomaticoCajaServicio
{
    public function __construct(
        private readonly AvisoUsuarioService $avisos,
        private readonly ExpoPushService $push,
    ) {}

    public function procesar(?string $fecha = null): array
    {
        $fechaProceso = $fecha ? Carbon::parse($fecha)->startOfDay() : now()->startOfDay();

        $turnos = DB::table('ventas.turnos_caja as t')
            ->join('ventas.cajas as c', 'c.id', '=', 't.caja_id')
            ->join('institucional.sedes as s', 's.id_sede', '=', 't.sede_id')
            ->join('seguridad.users as u', 'u.id', '=', 't.usuario_id')
            ->where('t.estado', 'ABIERTA')
            ->whereDate('t.fecha_apertura', '<', $fechaProceso->toDateString())
            ->select(
                't.*',
                'c.codigo as caja_codigo',
                'c.nombre as caja_nombre',
                's.nombre as sede_nombre',
                'u.name as cajero_nombre'
            )
            ->orderBy('t.fecha_apertura')
            ->get();

        $cerrados = 0;
        $notificados = 0;

        foreach ($turnos as $turno) {
            $resultado = DB::transaction(function () use ($turno): ?object {
                $actual = DB::table('ventas.turnos_caja')
                    ->where('id', $turno->id)
                    ->lockForUpdate()
                    ->first();

                if (! $actual || $actual->estado !== 'ABIERTA') {
                    return null;
                }

                $efectivoCobrado = (float) DB::table('ventas.pagos')
                    ->where('turno_caja_id', $actual->id)
                    ->where('estado', 'CONFIRMADO')
                    ->where('metodo_pago', 'EFECTIVO')
                    ->sum('monto');

                $esperado = round((float) $actual->saldo_inicial + $efectivoCobrado, 2);
                $fechaCierre = Carbon::parse($actual->fecha_apertura)->endOfDay();

                DB::table('ventas.turnos_caja')
                    ->where('id', $actual->id)
                    ->update([
                        'fecha_cierre' => $fechaCierre,
                        'efectivo_esperado' => $esperado,
                        'efectivo_contado' => null,
                        'diferencia' => null,
                        'estado' => 'CERRADA',
                        'tipo_cierre' => 'AUTOMATICO',
                        'requiere_arqueo' => true,
                        'cierre_automatico_at' => now(),
                        'observaciones_cierre' => 'Cierre automático por fin de jornada. Pendiente de arqueo y conciliación.',
                        'cerrado_por' => null,
                        'updated_at' => now(),
                    ]);

                return (object) [
                    'id' => (int) $actual->id,
                    'usuario_id' => (int) $actual->usuario_id,
                    'sede_id' => (int) $actual->sede_id,
                    'efectivo_esperado' => $esperado,
                    'fecha_cierre' => $fechaCierre,
                ];
            });

            if (! $resultado) {
                continue;
            }

            $cerrados++;
            $notificados += $this->notificarPendienteArqueo($turno, $resultado);
        }

        return [
            'fecha' => $fechaProceso->toDateString(),
            'turnos_detectados' => $turnos->count(),
            'turnos_cerrados' => $cerrados,
            'destinatarios_notificados' => $notificados,
        ];
    }

    private function notificarPendienteArqueo(object $turno, object $cierre): int
    {
        $titulo = 'Cierre automático pendiente de arqueo';
        $fecha = Carbon::parse($turno->fecha_apertura)->format('d/m/Y');
        $mensaje = sprintf(
            '%s · %s · %s · %s · efectivo esperado $%s. Requiere revisión y conciliación.',
            $turno->caja_codigo,
            $turno->cajero_nombre,
            $turno->sede_nombre,
            $fecha,
            number_format((float) $cierre->efectivo_esperado, 2, '.', '')
        );

        $destinatarios = $this->destinatarios((int) $turno->sede_id);

        foreach ($destinatarios as $usuario) {
            try {
                $this->avisos->registrar(
                    (int) $usuario->id,
                    'ARQUEO_CAJA_PENDIENTE',
                    $titulo,
                    $mensaje,
                    '/ventas/turnos-caja',
                    'TURNO_CAJA',
                    (int) $turno->id,
                );
            } catch (\Throwable $e) {
                Log::warning('No se pudo registrar aviso de arqueo pendiente.', [
                    'turno_id' => $turno->id,
                    'usuario_id' => $usuario->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $tokens = DB::table('notificaciones.dispositivos_push')
                ->where('usuario_id', $usuario->id)
                ->where('activo', true)
                ->pluck('token');

            foreach ($tokens as $token) {
                try {
                    $this->push->enviar((string) $token, $titulo, $mensaje, [
                        'tipo' => 'ARQUEO_CAJA_PENDIENTE',
                        'turno_id' => (int) $turno->id,
                        'sede_id' => (int) $turno->sede_id,
                        'vista' => '/ventas/turnos-caja',
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('No se pudo enviar push de arqueo pendiente.', [
                        'turno_id' => $turno->id,
                        'usuario_id' => $usuario->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $destinatarios->count();
    }

    private function destinatarios(int $sedeId)
    {
        return DB::table('seguridad.users as u')
            ->join('seguridad.cpu_userrole as r', 'r.id_userrole', '=', 'u.usr_tipo')
            ->where('u.usr_estado', 1)
            ->whereIn('r.role', ['SUPERADMINISTRADOR', 'ADMINISTRADOR', 'SUPERVISOR DE VENTAS'])
            ->where(function ($query) use ($sedeId): void {
                $query->whereIn('r.role', ['SUPERADMINISTRADOR', 'ADMINISTRADOR'])
                    ->orWhereExists(function ($sub) use ($sedeId): void {
                        $sub->selectRaw('1')
                            ->from('institucional.usuario_contexto as uc')
                            ->join('institucional.contextos as c', 'c.id_contexto', '=', 'uc.id_contexto')
                            ->whereColumn('uc.id_usuario', 'u.id')
                            ->where('uc.activo', true)
                            ->where('c.activo', true)
                            ->where('c.id_sede', $sedeId);
                    });
            })
            ->select('u.id', 'u.name', 'r.role')
            ->distinct()
            ->get();
    }
}
