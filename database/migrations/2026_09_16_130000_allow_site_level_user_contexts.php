<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE institucional.contextos ALTER COLUMN id_unidad DROP NOT NULL');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS contextos_sede_nivel_unique
            ON institucional.contextos (id_sede)
            WHERE id_unidad IS NULL AND id_carrera_area IS NULL');

        $sedes = DB::table('institucional.sedes')
            ->where('activo', true)
            ->pluck('id_sede');

        foreach ($sedes as $idSede) {
            DB::table('institucional.contextos')->updateOrInsert(
                [
                    'id_sede' => $idSede,
                    'id_unidad' => null,
                    'id_carrera_area' => null,
                ],
                [
                    'activo' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        $contextosSede = DB::table('institucional.contextos')
            ->whereNull('id_unidad')
            ->whereNull('id_carrera_area')
            ->pluck('id_contexto');

        if ($contextosSede->isNotEmpty()) {
            DB::table('institucional.usuario_contexto')
                ->whereIn('id_contexto', $contextosSede)
                ->delete();

            DB::table('institucional.contextos')
                ->whereIn('id_contexto', $contextosSede)
                ->delete();
        }

        DB::statement('DROP INDEX IF EXISTS institucional.contextos_sede_nivel_unique');
        DB::statement('ALTER TABLE institucional.contextos ALTER COLUMN id_unidad SET NOT NULL');
    }
};
