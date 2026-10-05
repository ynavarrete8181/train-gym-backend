<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->refrescarVista('planes', 'membresias');
        $this->refrescarVista('membresias', 'membresias');
    }

    public function down(): void
    {
        // La capa de compatibilidad debe conservar la estructura actual de las
        // tablas físicas. No se revierte a una definición obsoleta.
        $this->refrescarVista('planes', 'membresias');
        $this->refrescarVista('membresias', 'membresias');
    }

    private function refrescarVista(string $vista, string $schemaOrigen): void
    {
        $existe = DB::table('pg_class as c')
            ->join('pg_namespace as n', 'n.oid', '=', 'c.relnamespace')
            ->where('n.nspname', 'gimnasio')
            ->where('c.relname', $vista)
            ->where('c.relkind', 'v')
            ->exists();

        if ($existe) {
            DB::statement("DROP VIEW gimnasio.{$vista}");
        }

        DB::statement("CREATE VIEW gimnasio.{$vista} AS SELECT * FROM {$schemaOrigen}.{$vista}");
    }
};
