<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $evento = DB::table('notificaciones.eventos')->insertGetId(['codigo' => 'USUARIO_RESTABLECER_CLAVE', 'nombre' => 'Restablecimiento de contraseña', 'descripcion' => 'Enlace seguro para que el usuario defina una contraseña nueva.', 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        $plantilla = DB::table('notificaciones.plantillas')->insertGetId([
            'codigo' => 'RESTABLECIMIENTO_CLAVE', 'nombre' => 'Restablecimiento de contraseña', 'tipo' => 'RESTABLECIMIENTO',
            'asunto' => 'Restablece tu contraseña de {{nombre_sistema}}',
            'cuerpo_html' => '<p>Hola <strong>{{nombre_usuario}}</strong>,</p><p>Un administrador solicitó el restablecimiento de tu contraseña.</p><p><a href="{{url_activacion}}">Crear una contraseña nueva</a></p><p>Este enlace es personal y vence el {{fecha_expiracion}}. Si no esperabas esta solicitud, comunícate con el administrador.</p>',
            'cuerpo_texto' => "Hola {{nombre_usuario}},\n\nUn administrador solicitó el restablecimiento de tu contraseña.\n\nCrea una contraseña nueva: {{url_activacion}}\n\nEste enlace vence el {{fecha_expiracion}}.",
            'variables' => json_encode(['nombre_sistema', 'nombre_usuario', 'url_activacion', 'fecha_expiracion']), 'activo' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('notificaciones.evento_plantilla')->insert(['evento_id' => $evento, 'plantilla_id' => $plantilla, 'predeterminada' => true, 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::statement("CREATE UNIQUE INDEX plantillas_unica_rest_clave_idx ON notificaciones.plantillas(tipo) WHERE tipo = 'RESTABLECIMIENTO'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS notificaciones.plantillas_unica_rest_clave_idx');
        DB::table('notificaciones.eventos')->where('codigo', 'USUARIO_RESTABLECER_CLAVE')->delete();
        DB::table('notificaciones.plantillas')->where('codigo', 'RESTABLECIMIENTO_CLAVE')->delete();
    }
};
