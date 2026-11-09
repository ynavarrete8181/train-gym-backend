<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ConfigurarOperacionPresentacion extends Command
{
    protected $signature = 'revive:configurar-operacion-presentacion {--confirm}';

    protected $description = 'Completa contextos y caja para la operación local de Andrea y Karol.';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) || DB::connection()->getDatabaseName() !== 'db_gimnasio' || ! $this->option('confirm')) {
            $this->error('Operación permitida únicamente con --confirm en db_gimnasio local/testing.');

            return self::FAILURE;
        }

        $andrea = DB::table('seguridad.users')->where('email', 'andrea_amen@hotmail.com')->first();
        $karol = DB::table('seguridad.users')->where('email', 'karol@revive.com')->first();
        if (! $andrea || ! $karol) {
            $this->error('No se encontraron las cuentas operativas esperadas.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($andrea, $karol): void {
            $contextoHome = DB::table('institucional.contextos')->where('id_sede', 1)->where('activo', true)->orderBy('id_contexto')->value('id_contexto');
            $contextoXpadel = DB::table('institucional.contextos')->where('id_sede', 2)->where('activo', true)->orderByDesc('id_unidad')->orderBy('id_contexto')->value('id_contexto');
            foreach ([[$andrea->id, $contextoXpadel, true], [$andrea->id, $contextoHome, false], [$karol->id, $contextoXpadel, true], [$karol->id, $contextoHome, false]] as [$usuario, $contexto, $principal]) {
                DB::table('institucional.usuario_contexto')->updateOrInsert(['id_usuario' => $usuario, 'id_contexto' => $contexto], [
                    'principal' => $principal, 'activo' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('ventas.cajas')->updateOrInsert(['codigo' => 'CAJA-HOME-001'], [
                'sede_id' => 1, 'nombre' => 'Caja principal Home',
                'descripcion' => 'Caja operativa para cobros y ventas de Revive Home.',
                'saldo_inicial' => 0, 'activa' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach ([$andrea, $karol] as $usuario) {
                foreach (DB::table('seguridad.cpu_userrolefunction')->where('id_userrole', $usuario->usr_tipo)->where('activo', true)->get() as $funcion) {
                    DB::table('seguridad.cpu_userfunction')->updateOrInsert(['id_users' => $usuario->id, 'id_menu' => $funcion->id_menu], [
                        'id_userrole' => $usuario->usr_tipo, 'id_usermenu' => $funcion->id_usermenu,
                        'nombre' => $funcion->nombre, 'icono' => $funcion->icono, 'accion' => $funcion->accion,
                        'activo' => true, 'orden' => $funcion->orden, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        });

        $this->info('Andrea quedó habilitada para configurar en Home/Xpadel y Karol para operar cobros en ambas sedes.');

        return self::SUCCESS;
    }
}
