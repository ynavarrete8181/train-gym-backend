<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('seguridad.users') || ! Schema::hasTable('seguridad.cpu_userrole')) {
            return;
        }

        $rolesSinContexto = DB::table('seguridad.cpu_userrole')
            ->whereIn('role', ['SUPERADMINISTRADOR', 'DEPORTISTA', 'RESPONSABLE'])
            ->pluck('id_userrole');

        $usuariosSinContexto = DB::table('seguridad.users')
            ->whereIn('usr_tipo', $rolesSinContexto)
            ->pluck('id');

        if (Schema::hasTable('institucional.usuario_contexto') && $usuariosSinContexto->isNotEmpty()) {
            DB::table('institucional.usuario_contexto')
                ->whereIn('id_usuario', $usuariosSinContexto)
                ->delete();
        }

        $rolesApp = DB::table('seguridad.cpu_userrole')
            ->whereIn('role', ['DEPORTISTA', 'RESPONSABLE'])
            ->pluck('id_userrole');

        $usuariosApp = DB::table('seguridad.users')
            ->whereIn('usr_tipo', $rolesApp)
            ->pluck('id');

        if (Schema::hasTable('seguridad.cpu_userfunction') && $usuariosApp->isNotEmpty()) {
            DB::table('seguridad.cpu_userfunction')
                ->whereIn('id_users', $usuariosApp)
                ->delete();
        }
    }

    public function down(): void
    {
        // Normalización de datos: no se reconstruyen contextos o permisos heredados obsoletos.
    }
};
