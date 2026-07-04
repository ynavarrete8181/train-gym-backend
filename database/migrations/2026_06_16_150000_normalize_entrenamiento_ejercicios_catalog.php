<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            DB::table('entrenamiento.ejercicios')
                ->where('equipamiento', 'Balón Medicional')
                ->update([
                    'equipamiento' => 'Balón Medicinal',
                    'updated_at' => now(),
                ]);

            DB::table('entrenamiento.ejercicios')
                ->where('id', 8)
                ->where('nombre', 'Vuelos frontales cabo')
                ->update([
                    'nombre' => 'Vuelos frontales cable',
                    'updated_at' => now(),
                ]);

            $referencedExerciseIds = collect(DB::select(<<<'SQL'
                SELECT DISTINCT id
                FROM (
                    SELECT ejercicio_id AS id FROM entrenamiento.rutinas WHERE ejercicio_id IS NOT NULL
                    UNION
                    SELECT ejercicio_transferencia_id AS id FROM entrenamiento.rutinas WHERE ejercicio_transferencia_id IS NOT NULL
                    UNION
                    SELECT ejercicio_id AS id FROM entrenamiento.rutina_plantilla_detalles WHERE ejercicio_id IS NOT NULL
                    UNION
                    SELECT ejercicio_transferencia_id AS id FROM entrenamiento.rutina_plantilla_detalles WHERE ejercicio_transferencia_id IS NOT NULL
                    UNION
                    SELECT ejercicio_id AS id FROM entrenamiento.plan_ejercicios WHERE ejercicio_id IS NOT NULL
                    UNION
                    SELECT ejercicio_id AS id FROM entrenamiento.plan_ejercicio_transferencias WHERE ejercicio_id IS NOT NULL
                ) refs
                WHERE id IS NOT NULL
            SQL))->pluck('id')->map(fn ($id) => (int) $id)->all();

            $safeDeleteIds = collect([36, 39])
                ->reject(fn ($id) => in_array($id, $referencedExerciseIds, true))
                ->values()
                ->all();

            if (!empty($safeDeleteIds)) {
                DB::table('entrenamiento.ejercicios')
                    ->whereIn('id', $safeDeleteIds)
                    ->delete();
            }

            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS entrenamiento_ejercicios_nombre_unique_idx ON entrenamiento.ejercicios ((lower(btrim(nombre))))');
        });
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS entrenamiento.entrenamiento_ejercicios_nombre_unique_idx');
    }
};
