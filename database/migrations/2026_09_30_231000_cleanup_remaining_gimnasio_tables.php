<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS servicios');
        DB::statement('CREATE SCHEMA IF NOT EXISTS agenda');

        $mapa = [
            'servicios' => [
                'disciplinas',
                'servicio_precios_sede',
            ],
            'agenda' => [
                'clases',
            ],
        ];

        foreach ($mapa as $destino => $tablas) {
            foreach ($tablas as $tabla) {
                if ($this->esVista('gimnasio', $tabla)) {
                    DB::statement("DROP VIEW gimnasio.{$tabla}");
                }

                if ($this->esTablaBase('gimnasio', $tabla)) {
                    DB::statement("ALTER TABLE gimnasio.{$tabla} SET SCHEMA {$destino}");
                }

                if ($this->esTablaBase($destino, $tabla)) {
                    DB::statement("CREATE OR REPLACE VIEW gimnasio.{$tabla} AS SELECT * FROM {$destino}.{$tabla}");
                }
            }
        }
    }

    public function down(): void
    {
        $mapa = [
            'agenda' => [
                'clases',
            ],
            'servicios' => [
                'servicio_precios_sede',
                'disciplinas',
            ],
        ];

        foreach ($mapa as $origen => $tablas) {
            foreach ($tablas as $tabla) {
                if ($this->esVista('gimnasio', $tabla)) {
                    DB::statement("DROP VIEW gimnasio.{$tabla}");
                }

                if ($this->esTablaBase($origen, $tabla) && ! $this->relacionExiste('gimnasio', $tabla)) {
                    DB::statement("ALTER TABLE {$origen}.{$tabla} SET SCHEMA gimnasio");
                }
            }
        }
    }

    private function esTablaBase(string $schema, string $tabla): bool
    {
        return DB::table('pg_class as c')
            ->join('pg_namespace as n', 'n.oid', '=', 'c.relnamespace')
            ->where('n.nspname', $schema)
            ->where('c.relname', $tabla)
            ->whereIn('c.relkind', ['r', 'p'])
            ->exists();
    }

    private function esVista(string $schema, string $tabla): bool
    {
        return DB::table('pg_class as c')
            ->join('pg_namespace as n', 'n.oid', '=', 'c.relnamespace')
            ->where('n.nspname', $schema)
            ->where('c.relname', $tabla)
            ->whereIn('c.relkind', ['v', 'm'])
            ->exists();
    }

    private function relacionExiste(string $schema, string $tabla): bool
    {
        return DB::table('pg_class as c')
            ->join('pg_namespace as n', 'n.oid', '=', 'c.relnamespace')
            ->where('n.nspname', $schema)
            ->where('c.relname', $tabla)
            ->exists();
    }
};
