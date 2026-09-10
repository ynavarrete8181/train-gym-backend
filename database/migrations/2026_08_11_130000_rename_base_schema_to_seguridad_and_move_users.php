<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->validarPostgreSql();

        DB::transaction(function (): void {
            $this->validarEsquema('base', true);
            $this->validarEsquema('seguridad', false);

            DB::statement('ALTER SCHEMA base RENAME TO seguridad');
            DB::statement('ALTER TABLE public.users SET SCHEMA seguridad');
            DB::statement('ALTER TABLE public.password_reset_tokens SET SCHEMA seguridad');
        });
    }

    public function down(): void
    {
        $this->validarPostgreSql();

        DB::transaction(function (): void {
            $this->validarEsquema('seguridad', true);
            $this->validarEsquema('base', false);

            DB::statement('ALTER TABLE seguridad.password_reset_tokens SET SCHEMA public');
            DB::statement('ALTER TABLE seguridad.users SET SCHEMA public');
            DB::statement('ALTER SCHEMA seguridad RENAME TO base');
        });
    }

    private function validarPostgreSql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Esta migración solo puede ejecutarse en PostgreSQL.');
        }
    }

    private function validarEsquema(string $esquema, bool $debeExistir): void
    {
        $existe = DB::table('information_schema.schemata')
            ->where('schema_name', $esquema)
            ->exists();

        if ($existe !== $debeExistir) {
            $estado = $debeExistir ? 'no existe' : 'ya existe';
            throw new RuntimeException("El esquema {$esquema} {$estado}; se canceló la migración.");
        }
    }
};
