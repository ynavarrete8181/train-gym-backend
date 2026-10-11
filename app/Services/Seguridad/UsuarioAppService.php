<?php

namespace App\Services\Seguridad;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class UsuarioAppService
{
    public function registrar(User $usuario, ?string $plataforma, ?string $versionApp): void
    {
        DB::transaction(function () use ($usuario, $plataforma, $versionApp): void {
            $ahora = now();
            DB::table('seguridad.usuarios_app')->updateOrInsert(
                ['usuario_id' => $usuario->id],
                [
                    'plataforma' => $plataforma,
                    'version_app' => $versionApp,
                    'activo' => true,
                    'ultimo_acceso_at' => $ahora,
                    'ultima_sincronizacion_at' => $ahora,
                    'estado_sincronizacion' => 'SINCRONIZADO',
                    'origen_datos' => 'ROL_SISTEMA',
                    'updated_at' => $ahora,
                    'created_at' => $ahora,
                ],
            );

            $usuarioAppId = (int) DB::table('seguridad.usuarios_app')->where('usuario_id', $usuario->id)->value('id');
            $rol = (string) DB::table('seguridad.cpu_userrole')->where('id_userrole', $usuario->usr_tipo)->value('role');
            $tipo = $this->tipoDesdeRol($rol);

            DB::table('seguridad.usuario_app_vinculaciones')->where('usuario_app_id', $usuarioAppId)->update([
                'activo' => false,
                'updated_at' => $ahora,
            ]);
            DB::table('seguridad.usuario_app_vinculaciones')->updateOrInsert(
                ['usuario_app_id' => $usuarioAppId, 'tipo' => $tipo],
                [
                    'identificador_institucional' => $usuario->cedula,
                    'origen' => 'ROL_SISTEMA',
                    'activo' => true,
                    'sincronizado_at' => $ahora,
                    'updated_at' => $ahora,
                    'created_at' => $ahora,
                ],
            );

            DB::table('seguridad.users')->where('id', $usuario->id)->update(['ultimo_acceso_app_at' => $ahora]);
        });
    }

    private function tipoDesdeRol(string $rol): string
    {
        $rol = mb_strtoupper($rol);
        if (str_contains($rol, 'ESTUDIANTE') || str_contains($rol, 'ALUMNO')) {
            return 'ESTUDIANTE';
        }
        if (str_contains($rol, 'DOCENTE') || str_contains($rol, 'PROFESOR')) {
            return 'DOCENTE';
        }
        if (str_contains($rol, 'ADMINISTR')) {
            return 'ADMINISTRATIVO';
        }

        return 'SIN_CLASIFICAR';
    }
}
