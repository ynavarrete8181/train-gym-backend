<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

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
