<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE notificaciones.plantillas ADD COLUMN tipo VARCHAR(30) NOT NULL DEFAULT 'COMUNICADO'");
        DB::statement('CREATE TABLE notificaciones.eventos (
            id BIGSERIAL PRIMARY KEY, codigo VARCHAR(100) UNIQUE NOT NULL, nombre VARCHAR(160) NOT NULL,
            descripcion TEXT NULL, activo BOOLEAN NOT NULL DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT NOW(), updated_at TIMESTAMP DEFAULT NOW()
        )');
        DB::statement('CREATE TABLE notificaciones.evento_plantilla (
            id BIGSERIAL PRIMARY KEY, evento_id BIGINT NOT NULL REFERENCES notificaciones.eventos(id) ON DELETE CASCADE,
            plantilla_id BIGINT NOT NULL REFERENCES notificaciones.plantillas(id) ON DELETE CASCADE,
            predeterminada BOOLEAN NOT NULL DEFAULT FALSE, activo BOOLEAN NOT NULL DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT NOW(), updated_at TIMESTAMP DEFAULT NOW(), UNIQUE(evento_id, plantilla_id)
        )');
        DB::statement('CREATE UNIQUE INDEX evento_plantilla_predeterminada_idx ON notificaciones.evento_plantilla(evento_id) WHERE predeterminada = TRUE AND activo = TRUE');
        $evento = DB::table('notificaciones.eventos')->insertGetId([
            'codigo' => 'USUARIO_CREADO', 'nombre' => 'Usuario creado',
            'descripcion' => 'Invitación segura para establecer la contraseña inicial.', 'activo' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $plantilla = DB::table('notificaciones.plantillas')->where('codigo', 'INVITACION_USUARIO')->value('id');
        if ($plantilla) {
            DB::table('notificaciones.plantillas')->where('id', $plantilla)->update(['tipo' => 'ACCESO', 'updated_at' => now()]);
            DB::table('notificaciones.evento_plantilla')->insert(['evento_id' => $evento, 'plantilla_id' => $plantilla, 'predeterminada' => true, 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::statement("CREATE UNIQUE INDEX plantillas_unica_acceso_idx ON notificaciones.plantillas(tipo) WHERE tipo = 'ACCESO'");

        DB::table('seguridad.cpu_userrolefunction')->where('id_menu', 'NOTIFICACIONES-CAMPANIAS')->update([
            'nombre' => 'Comunicados', 'icono' => 'campaign', 'accion' => 'notificaciones/comunicados',
            'id_menu' => 'NOTIFICACIONES-COMUNICADOS', 'orden' => 1, 'updated_at' => now(),
        ]);
        DB::table('seguridad.cpu_userfunction')->where('id_menu', 'NOTIFICACIONES-CAMPANIAS')->update([
            'nombre' => 'Comunicados', 'icono' => 'campaign', 'accion' => 'notificaciones/comunicados',
            'id_menu' => 'NOTIFICACIONES-COMUNICADOS', 'orden' => 1, 'updated_at' => now(),
        ]);
        DB::table('seguridad.cpu_userrolefunction')->where('id_menu', 'NOTIFICACIONES-PLANTILLAS')->update(['orden' => 3, 'updated_at' => now()]);
        DB::table('seguridad.cpu_userfunction')->where('id_menu', 'NOTIFICACIONES-PLANTILLAS')->update(['orden' => 3, 'updated_at' => now()]);
        DB::table('seguridad.cpu_userrolefunction')->where('id_menu', 'NOTIFICACIONES-HISTORIAL')->update(['orden' => 4, 'updated_at' => now()]);
        DB::table('seguridad.cpu_userfunction')->where('id_menu', 'NOTIFICACIONES-HISTORIAL')->update(['orden' => 4, 'updated_at' => now()]);

        foreach (DB::table('seguridad.cpu_userrolefunction')->where('id_menu', 'NOTIFICACIONES-COMUNICADOS')->get() as $f) {
            DB::table('seguridad.cpu_userrolefunction')->insertOrIgnore([
                'id_userrole' => $f->id_userrole, 'id_usermenu' => $f->id_usermenu, 'nombre' => 'Invitaciones de acceso',
                'icono' => 'mark_email_unread', 'accion' => 'notificaciones/acceso', 'id_menu' => 'NOTIFICACIONES-ACCESO',
                'activo' => true, 'orden' => 2, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (DB::table('seguridad.cpu_userfunction')->where('id_menu', 'NOTIFICACIONES-COMUNICADOS')->get() as $f) {
            DB::table('seguridad.cpu_userfunction')->insertOrIgnore([
                'id_users' => $f->id_users, 'id_userrole' => $f->id_userrole, 'id_usermenu' => $f->id_usermenu,
                'nombre' => 'Invitaciones de acceso', 'icono' => 'mark_email_unread', 'accion' => 'notificaciones/acceso',
                'id_menu' => 'NOTIFICACIONES-ACCESO', 'activo' => true, 'orden' => 2,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('seguridad.cpu_userfunction')->where('id_menu', 'NOTIFICACIONES-ACCESO')->delete();
        DB::table('seguridad.cpu_userrolefunction')->where('id_menu', 'NOTIFICACIONES-ACCESO')->delete();
        DB::table('seguridad.cpu_userrolefunction')->where('id_menu', 'NOTIFICACIONES-COMUNICADOS')->update(['nombre' => 'Campañas y envíos', 'accion' => 'notificaciones/campanias', 'id_menu' => 'NOTIFICACIONES-CAMPANIAS', 'updated_at' => now()]);
        DB::table('seguridad.cpu_userfunction')->where('id_menu', 'NOTIFICACIONES-COMUNICADOS')->update(['nombre' => 'Campañas y envíos', 'accion' => 'notificaciones/campanias', 'id_menu' => 'NOTIFICACIONES-CAMPANIAS', 'updated_at' => now()]);
        DB::statement('DROP TABLE IF EXISTS notificaciones.evento_plantilla');
        DB::statement('DROP TABLE IF EXISTS notificaciones.eventos');
        DB::statement('ALTER TABLE notificaciones.plantillas DROP COLUMN IF EXISTS tipo');
    }
};
