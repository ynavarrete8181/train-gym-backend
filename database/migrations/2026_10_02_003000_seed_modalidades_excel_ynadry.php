<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $catalogo = [
            'MUSCULACION' => [
                ['codigo' => '3D-SEM', 'nombre' => '3 días por semana', 'dias' => 3, 'precio' => 40.00],
                ['codigo' => '4D-SEM', 'nombre' => '4 días por semana', 'dias' => 4, 'precio' => null],
                ['codigo' => '5D-SEM', 'nombre' => '5 días por semana', 'dias' => 5, 'precio' => 60.00],
            ],
            'ENTRENAMIENTO DEPORTIVO' => [
                ['codigo' => '1D-SEM', 'nombre' => '1 día por semana', 'dias' => 1, 'precio' => null],
                ['codigo' => '2D-SEM', 'nombre' => '2 días por semana', 'dias' => 2, 'precio' => null],
                ['codigo' => '3D-SEM', 'nombre' => '3 días por semana', 'dias' => 3, 'precio' => null],
                ['codigo' => '5D-SEM', 'nombre' => '5 días por semana', 'dias' => 5, 'precio' => null],
            ],
            'ENTRENAMIENTO HIBRIDO' => [
                ['codigo' => '5D-SEM', 'nombre' => '5 días por semana', 'dias' => 5, 'precio' => null],
            ],
        ];

        foreach ($catalogo as $nombreBase => $modalidades) {
            $plan = DB::table('membresias.planes')
                ->whereRaw(
                    "UPPER(TRANSLATE(nombre, 'ÁÉÍÓÚÜÑáéíóúüñ', 'AEIOUUNaeiouun')) = ?",
                    [$nombreBase]
                )
                ->first();

            if (! $plan) {
                continue;
            }

            DB::table('membresias.planes')
                ->where('id', $plan->id)
                ->update([
                    'requiere_modalidades' => true,
                    'updated_at' => now(),
                ]);

            foreach ($modalidades as $item) {
                $precio = $item['precio'] ?? (float) ($plan->precio_base ?? 0);

                DB::table('membresias.plan_modalidades')->updateOrInsert(
                    [
                        'plan_id' => $plan->id,
                        'codigo' => $item['codigo'],
                    ],
                    [
                        'nombre' => $item['nombre'],
                        'descripcion' => 'Modalidad inicial identificada desde YNADRY LISTA 1.xlsx.',
                        'dias_por_semana' => $item['dias'],
                        'usos_por_semana' => $item['dias'],
                        'uso_ilimitado' => false,
                        'tipo_duracion' => 'SEMANAS',
                        'duracion' => 4,
                        'precio_base' => $precio,
                        'tarifa_inscripcion' => 0,
                        'modelo_cobro' => 'FIJO_POR_PERIODO',
                        'momento_cobro' => 'ANTICIPADO',
                        'permite_prorrateo' => false,
                        'permite_extension' => true,
                        'extension_automatica' => false,
                        'permite_rollover' => false,
                        'activo' => true,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        $codigosPorPlan = [
            'MUSCULACION' => ['3D-SEM', '4D-SEM', '5D-SEM'],
            'ENTRENAMIENTO DEPORTIVO' => ['1D-SEM', '2D-SEM', '3D-SEM', '5D-SEM'],
            'ENTRENAMIENTO HIBRIDO' => ['5D-SEM'],
        ];

        foreach ($codigosPorPlan as $nombreBase => $codigos) {
            $planId = DB::table('membresias.planes')
                ->whereRaw(
                    "UPPER(TRANSLATE(nombre, 'ÁÉÍÓÚÜÑáéíóúüñ', 'AEIOUUNaeiouun')) = ?",
                    [$nombreBase]
                )
                ->value('id');

            if (! $planId) {
                continue;
            }

            $modalidadIds = DB::table('membresias.plan_modalidades')
                ->where('plan_id', $planId)
                ->whereIn('codigo', $codigos)
                ->pluck('id');

            if ($modalidadIds->isNotEmpty()) {
                $enUso = DB::table('membresias.membresias')
                    ->whereIn('modalidad_id', $modalidadIds)
                    ->exists();

                if (! $enUso) {
                    DB::table('membresias.plan_modalidades')
                        ->whereIn('id', $modalidadIds)
                        ->delete();
                }
            }
        }
    }
};
