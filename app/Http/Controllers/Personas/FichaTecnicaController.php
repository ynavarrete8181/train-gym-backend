<?php

namespace App\Http\Controllers\Personas;

use App\Http\Controllers\Controller;
use App\Services\Personas\BodyMetricsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FichaTecnicaController extends Controller
{
    public function index(Request $request)
    {
        $buscar = $request->query('buscar');
        
        $query = DB::table('salud.fichas_tecnicas as f')
            ->join('core.personas as p', 'f.persona_id', '=', 'p.id')
            ->leftJoin('salud.ficha_mediciones as m', 'f.id', '=', 'm.ficha_tecnica_id')
            ->select(
                'f.id as ficha_id',
                'f.persona_id',
                'f.fecha_ficha',
                'f.actividad_fisica',
                'f.objetivo',
                'f.observaciones',
                'p.foto_url',
                'p.nombres',
                'p.apellidos',
                'p.numero_identificacion as cedula',
                DB::raw("CONCAT(p.nombres, ' ', p.apellidos) as nombre_completo"),
                'm.peso_kg',
                'm.talla_cm',
                'm.imc',
                'm.cintura_cm',
                'm.grasa_corporal_pct',
                'm.masa_magra_kg'
            );

        // Eliminado la condición whereNull('p.deleted_at') ya que la columna deleted_at no existe en core.personas
        $query->orderBy('f.fecha_ficha', 'desc');

        if (!empty($buscar)) {
            $query->where(function ($q) use ($buscar) {
                $q->where('p.nombres', 'ilike', '%' . $buscar . '%')
                  ->orWhere('p.apellidos', 'ilike', '%' . $buscar . '%')
                  ->orWhere('p.numero_identificacion', 'ilike', '%' . $buscar . '%');
            });
        }

        return response()->json($query->get(), 200);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'persona_id' => [
                'required',
                'integer',
                function ($attribute, $value, $fail) {
                    $exists = DB::table('core.personas')->where('id', $value)->exists();
                    if (!$exists) {
                        $fail('La persona seleccionada no existe.');
                    }
                }
            ],
            'actividad_fisica' => ['nullable', 'string'],
            'objetivo' => ['nullable', 'string'],
            'observaciones' => ['nullable', 'string'],
            'peso_kg' => ['nullable', 'numeric', 'gte:0'],
            'talla_cm' => ['nullable', 'numeric', 'gte:0'],
            'cintura_cm' => ['nullable', 'numeric', 'gte:0'],
            'grasa_corporal_pct' => ['nullable', 'numeric', 'gte:0', 'lte:100'],
            'masa_magra_kg' => ['nullable', 'numeric', 'gte:0'],
        ]);

        $fichaId = DB::transaction(function () use ($data) {
            $fichaId = DB::table('salud.fichas_tecnicas')->insertGetId([
                'persona_id' => $data['persona_id'],
                'fecha_ficha' => now(),
                'actividad_fisica' => $data['actividad_fisica'] ?? null,
                'objetivo' => $data['objetivo'] ?? null,
                'observaciones' => $data['observaciones'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $peso = $data['peso_kg'] ?? null;
            $talla = $data['talla_cm'] ?? null;
            $grasaCorporal = $data['grasa_corporal_pct'] ?? null;
            $masaMagra = $data['masa_magra_kg'] ?? null;
            $metricas = BodyMetricsService::calculate(
                $peso ? (float) $peso : null,
                $talla ? (float) $talla : null,
                isset($data['cintura_cm']) && $data['cintura_cm'] !== null ? (float) $data['cintura_cm'] : null,
                $grasaCorporal !== null ? (float) $grasaCorporal : null,
                $masaMagra !== null ? (float) $masaMagra : null
            );

            DB::table('salud.ficha_mediciones')->insert([
                'ficha_tecnica_id' => $fichaId,
                'peso_kg' => $peso,
                'talla_cm' => $talla,
                'imc' => $metricas['imc'],
                'cintura_cm' => $data['cintura_cm'] ?? null,
                'grasa_corporal_pct' => $grasaCorporal,
                'masa_magra_kg' => $metricas['masa_magra_kg'],
                'created_at' => now(),
            ]);

            return $fichaId;
        });

        return response()->json([
            'message' => 'Evaluación y mediciones corporales registradas con éxito.',
            'ficha_id' => $fichaId,
        ], 210);
    }

    public function update(Request $request, $id)
    {
        $data = $request->validate([
            'actividad_fisica' => ['nullable', 'string'],
            'objetivo' => ['nullable', 'string'],
            'observaciones' => ['nullable', 'string'],
            'peso_kg' => ['nullable', 'numeric', 'gte:0'],
            'talla_cm' => ['nullable', 'numeric', 'gte:0'],
            'cintura_cm' => ['nullable', 'numeric', 'gte:0'],
            'grasa_corporal_pct' => ['nullable', 'numeric', 'gte:0', 'lte:100'],
            'masa_magra_kg' => ['nullable', 'numeric', 'gte:0'],
        ]);

        DB::transaction(function () use ($data, $id) {
            DB::table('salud.fichas_tecnicas')->where('id', $id)->update([
                'actividad_fisica' => $data['actividad_fisica'] ?? null,
                'objetivo' => $data['objetivo'] ?? null,
                'observaciones' => $data['observaciones'] ?? null,
                'updated_at' => now(),
            ]);

            $peso = $data['peso_kg'] ?? null;
            $talla = $data['talla_cm'] ?? null;
            $grasaCorporal = $data['grasa_corporal_pct'] ?? null;
            $masaMagra = $data['masa_magra_kg'] ?? null;
            $metricas = BodyMetricsService::calculate(
                $peso ? (float) $peso : null,
                $talla ? (float) $talla : null,
                isset($data['cintura_cm']) && $data['cintura_cm'] !== null ? (float) $data['cintura_cm'] : null,
                $grasaCorporal !== null ? (float) $grasaCorporal : null,
                $masaMagra !== null ? (float) $masaMagra : null
            );

            $exists = DB::table('salud.ficha_mediciones')->where('ficha_tecnica_id', $id)->exists();
            if ($exists) {
                DB::table('salud.ficha_mediciones')->where('ficha_tecnica_id', $id)->update([
                    'peso_kg' => $peso,
                    'talla_cm' => $talla,
                    'imc' => $metricas['imc'],
                    'cintura_cm' => $data['cintura_cm'] ?? null,
                    'grasa_corporal_pct' => $grasaCorporal,
                    'masa_magra_kg' => $metricas['masa_magra_kg'],
                ]);
            } else {
                DB::table('salud.ficha_mediciones')->insert([
                    'ficha_tecnica_id' => $id,
                    'peso_kg' => $peso,
                    'talla_cm' => $talla,
                    'imc' => $metricas['imc'],
                    'cintura_cm' => $data['cintura_cm'] ?? null,
                    'grasa_corporal_pct' => $grasaCorporal,
                    'masa_magra_kg' => $metricas['masa_magra_kg'],
                    'created_at' => now(),
                ]);
            }
        });

        return response()->json([
            'message' => 'Ficha de evaluación física actualizada con éxito.',
        ]);
    }

    public function destroy($id)
    {
        DB::transaction(function () use ($id) {
            DB::table('salud.ficha_mediciones')->where('ficha_tecnica_id', $id)->delete();
            DB::table('salud.fichas_tecnicas')->where('id', $id)->delete();
        });

        return response()->json([
            'message' => 'Ficha de evaluación física eliminada con éxito.',
        ]);
    }

}
