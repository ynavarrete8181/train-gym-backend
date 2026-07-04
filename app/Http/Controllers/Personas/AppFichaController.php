<?php

namespace App\Http\Controllers\Personas;

use App\Http\Controllers\Controller;
use App\Services\Personas\BodyMetricsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppFichaController extends Controller
{
    public function getFichas(Request $request)
    {
        $personaId = null;
        if ($request->user()) {
            $personaId = $request->user()->persona_id;
        } else {
            // Mock para entorno local si no hay token
            $persona = DB::table('core.personas')->where('nombres', 'like', '%Yandry%')->first();
            $personaId = $persona ? $persona->id : null;
        }

        if (!$personaId) {
            return response()->json(['message' => 'Persona no encontrada'], 404);
        }

        // Obtener la ficha técnica (la más reciente) junto con sus mediciones
        $ficha = DB::table('salud.fichas_tecnicas as f')
            ->leftJoin('salud.ficha_mediciones as m', 'f.id', '=', 'm.ficha_tecnica_id')
            ->leftJoin('core.personas as p', 'p.id', '=', 'f.persona_id')
            ->where('f.persona_id', $personaId)
            ->orderBy('f.fecha_ficha', 'desc')
            ->select('f.*', 'p.foto_url', 'm.peso_kg', 'm.talla_cm', 'm.imc', 'm.cintura_cm', 'm.grasa_corporal_pct', 'm.masa_magra_kg')
            ->first();

        $mediciones = [];
        if ($ficha) {
            // Historial de mediciones (para gráficas/tablas en app)
            $mediciones = DB::table('salud.fichas_tecnicas as f')
                ->join('salud.ficha_mediciones as m', 'f.id', '=', 'm.ficha_tecnica_id')
                ->leftJoin('core.personas as p', 'p.id', '=', 'f.persona_id')
                ->where('f.persona_id', $personaId)
                ->orderBy('f.fecha_ficha', 'asc') // Ascendente para ver evolución en el tiempo
                ->select(
                    'f.id',
                    'f.fecha_ficha',
                    'f.actividad_fisica',
                    'f.objetivo',
                    'f.observaciones',
                    'f.updated_at',
                    'f.created_at',
                    'p.foto_url',
                    'm.peso_kg',
                    'm.talla_cm',
                    'm.imc',
                    'm.cintura_cm',
                    'm.grasa_corporal_pct',
                    'm.masa_magra_kg'
                )
                ->get();
        }

        $fichaMetricas = $ficha ? BodyMetricsService::calculate(
            $ficha->peso_kg !== null ? (float) $ficha->peso_kg : null,
            $ficha->talla_cm !== null ? (float) $ficha->talla_cm : null,
            $ficha->cintura_cm !== null ? (float) $ficha->cintura_cm : null,
            $ficha->grasa_corporal_pct !== null ? (float) $ficha->grasa_corporal_pct : null,
            $ficha->masa_magra_kg !== null ? (float) $ficha->masa_magra_kg : null,
            $ficha->imc !== null ? (float) $ficha->imc : null
        ) : null;

        $historial = collect($mediciones)->map(function ($item) {
            $metricas = BodyMetricsService::calculate(
                $item->peso_kg !== null ? (float) $item->peso_kg : null,
                $item->talla_cm !== null ? (float) $item->talla_cm : null,
                $item->cintura_cm !== null ? (float) $item->cintura_cm : null,
                $item->grasa_corporal_pct !== null ? (float) $item->grasa_corporal_pct : null,
                $item->masa_magra_kg !== null ? (float) $item->masa_magra_kg : null,
                $item->imc !== null ? (float) $item->imc : null
            );

            return [
                'id' => (int) $item->id,
                'fecha_ficha' => $item->fecha_ficha,
                'fecha_revision_sugerida' => \Carbon\Carbon::parse($item->fecha_ficha)->addDays(30)->toDateString(),
                'fecha_actualizacion' => $item->updated_at ?? $item->created_at ?? $item->fecha_ficha,
                'actividad_fisica' => $item->actividad_fisica,
                'objetivo' => $item->objetivo,
                'observaciones' => $item->observaciones,
                'foto_url' => $item->foto_url,
                'peso_kg' => $item->peso_kg,
                'talla_cm' => $item->talla_cm,
                'imc' => $metricas['imc'],
                'cintura_cm' => $item->cintura_cm,
                'cintura_altura' => $metricas['cintura_altura'],
                'grasa_corporal_pct' => $item->grasa_corporal_pct,
                'masa_magra_kg' => $metricas['masa_magra_kg'],
                'estado_nutricional' => $metricas['estado_nutricional'],
                'formula_calculo' => $metricas['formula_calculo'],
            ];
        })->values();

        return response()->json([
            'message' => 'Fichas y evaluaciones obtenidas exitosamente',
            'data' => [
                'ficha' => $ficha ? [
                    'actividad_fisica' => $ficha->actividad_fisica,
                    'objetivo' => $ficha->objetivo,
                    'observaciones' => $ficha->observaciones,
                    'foto_url' => $ficha->foto_url,
                    'peso_kg' => $ficha->peso_kg,
                    'talla_cm' => $ficha->talla_cm,
                    'imc' => $fichaMetricas['imc'],
                    'cintura_cm' => $ficha->cintura_cm,
                    'cintura_altura' => $fichaMetricas['cintura_altura'],
                    'grasa_corporal_pct' => $ficha->grasa_corporal_pct,
                    'masa_magra_kg' => $fichaMetricas['masa_magra_kg'],
                    'estado_nutricional' => $fichaMetricas['estado_nutricional'],
                    'formula_calculo' => $fichaMetricas['formula_calculo'],
                    'fecha_ficha' => $ficha->fecha_ficha,
                    'fecha_revision_sugerida' => \Carbon\Carbon::parse($ficha->fecha_ficha)->addDays(30)->toDateString(),
                    'fecha_actualizacion' => $ficha->updated_at ?? $ficha->created_at ?? $ficha->fecha_ficha
                ] : null,
                'evaluaciones' => $historial
            ]
        ]);
    }
}
