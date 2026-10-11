<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class ProductionRootSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('production')) {
            throw new RuntimeException('ProductionRootSeeder solo puede ejecutarse en APP_ENV=production.');
        }

        $email = mb_strtolower(trim((string) env('REVIVE_ROOT_EMAIL', 'root@revive.local')));
        $password = (string) env('REVIVE_ROOT_PASSWORD', '');
        $name = trim((string) env('REVIVE_ROOT_NAME', 'Root'));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('REVIVE_ROOT_EMAIL no es valido.');
        }

        if (mb_strlen($password) < 12) {
            throw new RuntimeException('REVIVE_ROOT_PASSWORD debe tener al menos 12 caracteres.');
        }

        $rolId = DB::table('seguridad.cpu_userrole')
            ->where('role', 'SUPERADMINISTRADOR')
            ->where('activo', true)
            ->value('id_userrole');

        if (! $rolId) {
            throw new RuntimeException('No existe el rol SUPERADMINISTRADOR activo.');
        }

        DB::transaction(function () use ($email, $password, $name, $rolId): void {
            $personaId = DB::table('personas.personas')
                ->whereRaw('LOWER(email) = ?', [$email])
                ->value('id');

            if (! $personaId) {
                $personaId = DB::table('personas.personas')->insertGetId([
                    'tipo_identificacion' => null,
                    'identificacion' => null,
                    'nombres' => $name,
                    'apellidos' => null,
                    'nombre_completo' => $name,
                    'fecha_nacimiento' => null,
                    'genero' => null,
                    'telefono' => null,
                    'email' => $email,
                    'direccion' => null,
                    'activo' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $usuarioId = DB::table('seguridad.users')
                ->whereRaw('LOWER(email) = ?', [$email])
                ->value('id');

            $datosUsuario = [
                'persona_id' => $personaId,
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'usr_tipo' => $rolId,
                'usr_estado' => 1,
                'nombres' => $name,
                'apellidos' => null,
                'updated_at' => now(),
            ];

            if ($usuarioId) {
                DB::table('seguridad.users')->where('id', $usuarioId)->update($datosUsuario);
            } else {
                $usuarioId = DB::table('seguridad.users')->insertGetId([
                    ...$datosUsuario,
                    'email_verified_at' => now(),
                    'remember_token' => null,
                    'cedula' => null,
                    'created_at' => now(),
                ]);
            }

            DB::table('seguridad.cpu_userfunction')
                ->where('id_users', $usuarioId)
                ->delete();

            $funciones = DB::table('seguridad.cpu_userrolefunction')
                ->where('id_userrole', $rolId)
                ->where('activo', true)
                ->orderBy('id_usermenu')
                ->orderBy('orden')
                ->get();

            foreach ($funciones as $funcion) {
                DB::table('seguridad.cpu_userfunction')->insert([
                    'id_users' => $usuarioId,
                    'id_userrole' => $rolId,
                    'id_usermenu' => $funcion->id_usermenu,
                    'nombre' => $funcion->nombre,
                    'icono' => $funcion->icono,
                    'accion' => $funcion->accion,
                    'id_menu' => $funcion->id_menu,
                    'activo' => true,
                    'orden' => $funcion->orden,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('seguridad.preferencias_usuario')->updateOrInsert(
                ['id_usuario' => $usuarioId],
                [
                    'tema' => 'sistema',
                    'notificaciones_push' => true,
                    'notificaciones_correo' => true,
                    'notificaciones_internas' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        });
    }
}
