<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('seguridad.cpu_usermenu')->where('orden', '>=', 3)->increment('orden', 2);

            $integraciones = DB::table('seguridad.cpu_usermenu')->where('menu', 'Integraciones')->value('id_usermenu');
            if (! $integraciones) {
                $integraciones = DB::table('seguridad.cpu_usermenu')->insertGetId([
                    'menu' => 'Integraciones',
                    'icono' => 'hub',
                    'activo' => true,
                    'orden' => 3,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], 'id_usermenu');
            }

            $notificaciones = DB::table('seguridad.cpu_usermenu')->where('menu', 'Notificaciones')->value('id_usermenu');
            if (! $notificaciones) {
                $notificaciones = DB::table('seguridad.cpu_usermenu')->insertGetId([
                    'menu' => 'Notificaciones',
                    'icono' => 'notifications_active',
                    'activo' => true,
                    'orden' => 4,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], 'id_usermenu');
            }

            DB::table('seguridad.cpu_userrolefunction')
                ->where('id_menu', 'INTEGRACIONES-CONFIG')
                ->update(['id_usermenu' => $integraciones, 'orden' => 1, 'updated_at' => now()]);
            DB::table('seguridad.cpu_userfunction')
                ->where('id_menu', 'INTEGRACIONES-CONFIG')
                ->update(['id_usermenu' => $integraciones, 'orden' => 1, 'updated_at' => now()]);

            $funcionesNotificacion = [
                'NOTIFICACIONES-USUARIOS' => 1,
                'NOTIFICACIONES-PLANTILLAS' => 2,
            ];

            foreach ($funcionesNotificacion as $codigo => $orden) {
                DB::table('seguridad.cpu_userrolefunction')
                    ->where('id_menu', $codigo)
                    ->update(['id_usermenu' => $notificaciones, 'orden' => $orden, 'updated_at' => now()]);
                DB::table('seguridad.cpu_userfunction')
                    ->where('id_menu', $codigo)
                    ->update(['id_usermenu' => $notificaciones, 'orden' => $orden, 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $administracion = DB::table('seguridad.cpu_usermenu')->where('menu', 'Administración')->value('id_usermenu');

            if ($administracion) {
                $ordenes = [
                    'NOTIFICACIONES-USUARIOS' => 5,
                    'INTEGRACIONES-CONFIG' => 6,
                    'NOTIFICACIONES-PLANTILLAS' => 7,
                ];

                foreach ($ordenes as $codigo => $orden) {
                    DB::table('seguridad.cpu_userrolefunction')->where('id_menu', $codigo)->update(['id_usermenu' => $administracion, 'orden' => $orden, 'updated_at' => now()]);
                    DB::table('seguridad.cpu_userfunction')->where('id_menu', $codigo)->update(['id_usermenu' => $administracion, 'orden' => $orden, 'updated_at' => now()]);
                }
            }

            DB::table('seguridad.cpu_usermenu')->whereIn('menu', ['Integraciones', 'Notificaciones'])->delete();
            DB::table('seguridad.cpu_usermenu')->where('orden', '>=', 5)->decrement('orden', 2);
        });
    }
};
