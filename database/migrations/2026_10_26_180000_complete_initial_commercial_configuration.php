<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('membresias.planes', 'requiere_ficha_inicial')) {
            Schema::table('membresias.planes', function (Blueprint $table): void {
                $table->boolean('requiere_ficha_inicial')->default(false)->after('requiere_entrenador');
            });
        }

        $this->refrescarVistaPlanes();
        $this->configurarPlanes();
    }

    public function down(): void
    {
        if (Schema::hasColumn('membresias.planes', 'requiere_ficha_inicial')) {
            DB::statement('DROP VIEW IF EXISTS gimnasio.planes');
            Schema::table('membresias.planes', function (Blueprint $table): void {
                $table->dropColumn('requiere_ficha_inicial');
            });
            $this->refrescarVistaPlanes();
        }
    }

    private function configurarPlanes(): void
    {
        $configuracion = [
            'Musculación' => [
                'servicios' => ['Acceso general con pizarra', 'Fuerza e hipertrofia'],
                'requiere_entrenador' => false,
                'requiere_ficha_inicial' => true,
            ],
            'Funcional' => [
                'servicios' => ['Entrenamiento funcional', 'HIIT metabólico', 'Clase grupal funcional'],
                'requiere_entrenador' => true,
                'requiere_ficha_inicial' => true,
            ],
            'Entrenamiento Híbrido' => [
                'servicios' => ['Fuerza e hipertrofia', 'Entrenamiento funcional', 'HIIT metabólico'],
                'requiere_entrenador' => true,
                'requiere_ficha_inicial' => true,
            ],
            'Entrenamiento Deportivo' => [
                'servicios' => ['Evaluación física inicial', 'Control y seguimiento', 'Preparación física Xpadel'],
                'requiere_entrenador' => true,
                'requiere_ficha_inicial' => true,
            ],
            'Fortalecimiento / Rehabilitación' => [
                'servicios' => ['Evaluación física inicial', 'Control y seguimiento', 'Movilidad y recuperación'],
                'requiere_entrenador' => true,
                'requiere_ficha_inicial' => true,
            ],
            'Pase Diario' => [
                'servicios' => ['Acceso general con pizarra', 'Acceso general Xpadel'],
                'requiere_entrenador' => false,
                'requiere_ficha_inicial' => false,
            ],
            'Pase Diario Familia' => [
                'servicios' => ['Acceso general con pizarra', 'Acceso general Xpadel'],
                'requiere_entrenador' => false,
                'requiere_ficha_inicial' => false,
            ],
        ];

        $sedes = DB::table('institucional.sedes')->where('activo', true)->pluck('id_sede');

        foreach ($configuracion as $nombre => $reglas) {
            $plan = DB::table('membresias.planes')->where('nombre', $nombre)->first();
            if (! $plan) {
                continue;
            }

            DB::table('membresias.planes')->where('id', $plan->id)->update([
                'requiere_entrenador' => $reglas['requiere_entrenador'],
                'requiere_ficha_inicial' => $reglas['requiere_ficha_inicial'],
                'generar_venta' => true,
                'updated_at' => now(),
            ]);

            $servicios = DB::table('servicios.servicios')
                ->whereIn('nombre', $reglas['servicios'])
                ->where('activo', true)
                ->pluck('id');

            foreach ($servicios as $servicioId) {
                DB::table('membresias.plan_servicios')->updateOrInsert(
                    ['plan_id' => $plan->id, 'servicio_id' => $servicioId],
                    ['activo' => true, 'created_at' => now(), 'updated_at' => now()]
                );
            }

            if (! $plan->requiere_modalidades) {
                foreach ($sedes as $sedeId) {
                    DB::table('membresias.plan_precios_sede')->insertOrIgnore([
                        'plan_id' => $plan->id,
                        'sede_id' => $sedeId,
                        'precio' => $plan->precio_base,
                        'activo' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    private function refrescarVistaPlanes(): void
    {
        DB::statement('DROP VIEW IF EXISTS gimnasio.planes');
        DB::statement('CREATE VIEW gimnasio.planes AS SELECT * FROM membresias.planes');
    }
};
