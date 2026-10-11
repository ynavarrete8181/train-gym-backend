<?php

namespace Database\Seeders\Institucional;

use App\Services\Institucional\NormalizacionSedeService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InstitucionalSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CarrerasVigentesSeeder::class);
        app(NormalizacionSedeService::class)->normalizarManta();
        $this->regenerarContextos();
    }

    private function regenerarContextos(): void
    {
        DB::table('institucional.contextos')->update(['activo' => false, 'updated_at' => now()]);

        $relaciones = DB::table('institucional.sede_unidad as su')
            ->join('institucional.sedes as s', 's.id_sede', '=', 'su.id_sede')
            ->join('institucional.unidades as u', 'u.id_unidad', '=', 'su.id_unidad')
            ->where('su.activo', true)
            ->where('s.activo', true)
            ->where('u.activo', true)
            ->select('su.id', 'su.id_sede', 'su.id_unidad')
            ->get();

        foreach ($relaciones as $relacion) {
            $padre = DB::table('institucional.contextos')
                ->where('id_sede', $relacion->id_sede)
                ->where('id_unidad', $relacion->id_unidad)
                ->whereNull('id_carrera_area')
                ->first();

            if ($padre) {
                DB::table('institucional.contextos')->where('id_contexto', $padre->id_contexto)->update(['activo' => true, 'updated_at' => now()]);
            } else {
                DB::table('institucional.contextos')->insert([
                    'id_sede' => $relacion->id_sede,
                    'id_unidad' => $relacion->id_unidad,
                    'id_carrera_area' => null,
                    'activo' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $carreras = DB::table('institucional.carreras_areas')
                ->where('id_sede_unidad', $relacion->id)
                ->where('activo', true)
                ->pluck('id_carrera_area');

            foreach ($carreras as $idCarrera) {
                DB::table('institucional.contextos')->updateOrInsert(
                    ['id_sede' => $relacion->id_sede, 'id_unidad' => $relacion->id_unidad, 'id_carrera_area' => $idCarrera],
                    ['activo' => true, 'updated_at' => now(), 'created_at' => now()]
                );
            }
        }
    }
}
