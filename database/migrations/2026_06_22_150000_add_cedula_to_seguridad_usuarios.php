<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE seguridad.usuarios ADD COLUMN IF NOT EXISTS cedula VARCHAR(30)");

        DB::statement("
            UPDATE seguridad.usuarios AS u
            SET cedula = p.numero_identificacion
            FROM core.personas AS p
            WHERE p.id = u.persona_id
              AND COALESCE(TRIM(u.cedula), '') = ''
        ");

        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS uq_seguridad_usuarios_cedula
            ON seguridad.usuarios (cedula)
        ");
    }

    public function down(): void
    {
        DB::statement("DROP INDEX IF EXISTS uq_seguridad_usuarios_cedula");
        DB::statement("ALTER TABLE seguridad.usuarios DROP COLUMN IF EXISTS cedula");
    }
};
