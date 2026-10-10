<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Schedule;
use App\Services\Gimnasio\CobroProgramadoMembresiaServicio;
use App\Services\Logs\LogRetencionServicio;
use App\Services\Ventas\CierreAutomaticoCajaServicio;
use App\Services\Ventas\SincronizarVentasMembresiasServicio;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('revive:reset-passwords-prueba', function (): int {
    if (! app()->environment(['local', 'testing'])) {
        $this->error('Este comando solo puede ejecutarse en entornos local o testing.');
        return 1;
    }

    $total = DB::table('seguridad.users')->count();

    if ($total === 0) {
        $this->info('No existen usuarios para actualizar.');
        return 0;
    }

    DB::transaction(function (): void {
        DB::table('seguridad.users')->update([
            'password' => Hash::make('123456'),
            'updated_at' => now(),
        ]);

        // Obliga a iniciar sesión nuevamente con la contraseña de prueba.
        DB::table('seguridad.tokens_acceso')->delete();
    });

    $this->warn("Contraseña de prueba aplicada a {$total} usuario(s).");
    $this->info('Clave temporal de pruebas: 123456');
    $this->info('Todos los tokens de acceso fueron invalidados.');

    return 0;
})->purpose('Restablece temporalmente la contraseña de todos los usuarios a 123456 solo en local/testing.');

Artisan::command('revive:limpiar-usuarios-prueba-carga', function (): int {
    if (DB::getDriverName() !== 'pgsql') {
        $this->error('Este comando de limpieza está preparado para PostgreSQL.');
        return 1;
    }

    $patron = 'carga.masiva.%@example.com';
    $usuarios = DB::table('seguridad.users')->where('email', 'like', $patron)->get(['id', 'email']);

    if ($usuarios->isEmpty()) {
        $this->info('No existen usuarios de prueba de carga masiva para eliminar.');
        return 0;
    }

    $this->warn("Se eliminarán {$usuarios->count()} usuario(s) de prueba y sus relaciones asociadas.");

    DB::transaction(function () use ($usuarios, $patron): void {
        $idsCargas = DB::table('seguridad.carga_masiva_usuario_detalles')
            ->whereRaw("datos->>'email' LIKE ?", [$patron])
            ->pluck('carga_id')
            ->unique()
            ->values();

        if ($idsCargas->isNotEmpty()) {
            if (Schema::hasTable('seguridad.avisos_usuario')) {
                DB::table('seguridad.avisos_usuario')
                    ->where('referencia_tipo', 'CARGA_MASIVA_USUARIOS')
                    ->whereIn('referencia_id', $idsCargas)
                    ->delete();
            }

            DB::table('seguridad.cargas_masivas_usuario')->whereIn('id', $idsCargas)->delete();
        }

        DB::table('seguridad.users')->whereIn('id', $usuarios->pluck('id'))->delete();
    });

    $this->info("Limpieza completada: {$usuarios->count()} usuario(s) de prueba eliminados.");
    return 0;
})->purpose('Elimina únicamente usuarios carga.masiva.*@example.com, sus lotes y avisos de prueba.');


Artisan::command('revive:procesar-cobros-membresias {--fecha=}', function (CobroProgramadoMembresiaServicio $servicio): int {
    $resultado = $servicio->procesar($this->option('fecha') ?: null);

    $this->info(
        "Cobros procesados: {$resultado['procesadas']} | "
        . "ventas creadas: {$resultado['ventas_creadas']} | "
        . "omitidas: {$resultado['omitidas']} | "
        . "fecha: {$resultado['fecha']}"
    );

    return 0;
})->purpose('Genera ventas pendientes de membresías cuando llega su fecha programada de cobro.');

Schedule::command('revive:procesar-cobros-membresias')
    ->dailyAt('00:05')
    ->withoutOverlapping();


Artisan::command('revive:sincronizar-ventas-membresias {--membresia=}', function (SincronizarVentasMembresiasServicio $servicio): int {
    $membresiaId = $this->option('membresia') ? (int) $this->option('membresia') : null;

    if (! $membresiaId) {
        $this->error('Indica una membresía específica con --membresia=ID. Este comando es solo de reparación y no genera ventas masivamente.');
        return 1;
    }

    $resultado = $servicio->procesar($membresiaId);

    $this->info(
        "Membresías detectadas: {$resultado['detectadas']} | "
        . "ventas creadas: {$resultado['ventas_creadas']} | "
        . "omitidas: {$resultado['omitidas']} | "
        . "errores: " . count($resultado['errores'])
    );

    foreach ($resultado['errores'] as $error) {
        $this->error("Membresía {$error['membresia_id']}: {$error['error']}");
    }

    return empty($resultado['errores']) ? 0 : 1;
})->purpose('Repara de forma explícita la venta faltante de una membresía específica.');


Artisan::command('revive:cerrar-turnos-caja-vencidos {--fecha=}', function (CierreAutomaticoCajaServicio $servicio): int {
    $resultado = $servicio->procesar($this->option('fecha') ?: null);

    $this->info(
        "Turnos detectados: {$resultado['turnos_detectados']} | "
        . "cerrados: {$resultado['turnos_cerrados']} | "
        . "destinatarios notificados: {$resultado['destinatarios_notificados']} | "
        . "fecha: {$resultado['fecha']}"
    );

    return 0;
})->purpose('Cierra automáticamente turnos de caja de días anteriores y deja el arqueo pendiente de conciliación.');

Schedule::command('revive:cerrar-turnos-caja-vencidos')
    ->dailyAt('00:01')
    ->withoutOverlapping();


Artisan::command('revive:limpiar-logs', function (LogRetencionServicio $servicio): int {
    $resultado = $servicio->limpiar();

    $this->info(
        "Logs eliminados: {$resultado['total']} | "
        . "INFO: {$resultado['eventos_info']} | "
        . "WARNING: {$resultado['eventos_warning']} | "
        . "ERROR: {$resultado['eventos_error']} | "
        . "Excepciones: {$resultado['excepciones']} | "
        . "Integraciones OK: {$resultado['integraciones_ok']} | "
        . "Integraciones error: {$resultado['integraciones_error']}"
    );

    return 0;
})->purpose('Aplica la política de retención de logs técnicos. Nunca elimina auditoría funcional.');

Schedule::command('revive:limpiar-logs')
    ->dailyAt('02:30')
    ->withoutOverlapping();
