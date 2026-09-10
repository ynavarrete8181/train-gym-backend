<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

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
