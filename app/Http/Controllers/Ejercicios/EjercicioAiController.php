<?php

namespace App\Http\Controllers\Ejercicios;

use App\Http\Controllers\Controller;
use App\Queries\Ejercicios\EjercicioQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class EjercicioAiController extends Controller
{
    public function __construct(private EjercicioQuery $ejercicioQuery)
    {
    }

    public function analyze(Request $request)
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'grupo_muscular' => ['nullable', 'string', 'max:50'],
            'equipamiento' => ['nullable', 'string', 'max:50'],
            'tipo_entrenamiento' => ['nullable', 'string', 'max:50'],
            'instrucciones' => ['nullable', 'string'],
        ]);

        return response()->json($this->buildAnalysis($data));
    }

    public function analyzeExisting(Request $request, int $id)
    {
        $ejercicio = $this->ejercicioQuery->obtenerPorId($id);

        if (!$ejercicio) {
            return response()->json([
                'message' => 'No se encontró el ejercicio solicitado.',
            ], 404);
        }

        return response()->json($this->buildAnalysis($ejercicio));
    }

    private function buildAnalysis(array $exercise): array
    {
        $fallback = $this->fallbackAnalysis($exercise);
        $apiKey = config('services.openai.key');

        if (!$apiKey) {
            return [
                ...$fallback,
                'source' => 'fallback_local',
                'message' => 'Configura OPENAI_API_KEY para activar IA real.',
            ];
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(35)
                ->post(config('services.openai.url'), [
                    'model' => config('services.openai.model'),
                    'input' => $this->prompt($exercise),
                    'temperature' => 0.2,
                    'max_output_tokens' => 900,
                ]);

            if (!$response->successful()) {
                return [
                    ...$fallback,
                    'source' => 'fallback_after_ai_error',
                    'message' => 'La IA no respondió correctamente, se usó detección local.',
                    'ai_error' => $response->json('error.message') ?? $response->body(),
                ];
            }

            $parsed = $this->parseOpenAiJson($response->json());

            return [
                ...$fallback,
                ...$parsed,
                'source' => 'openai',
                'model' => config('services.openai.model'),
            ];
        } catch (\Throwable $exception) {
            return [
                ...$fallback,
                'source' => 'fallback_after_exception',
                'message' => 'No se pudo contactar la IA, se usó detección local.',
                'ai_error' => $exception->getMessage(),
            ];
        }
    }

    private function prompt(array $exercise): string
    {
        $payload = json_encode([
            'nombre' => $exercise['nombre'] ?? '',
            'grupo_muscular' => $exercise['grupo_muscular'] ?? '',
            'equipamiento' => $exercise['equipamiento'] ?? '',
            'tipo_entrenamiento' => $exercise['tipo_entrenamiento'] ?? '',
            'instrucciones' => $exercise['instrucciones'] ?? '',
        ], JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
Eres un preparador fisico y anatomista deportivo. Analiza este ejercicio para una app de gimnasio.
Devuelve exclusivamente JSON valido, sin markdown, con esta estructura:
{
  "musculos_principales": [{"nombre": "string", "zona": "string", "intensidad": 1-100}],
  "musculos_secundarios": [{"nombre": "string", "zona": "string", "intensidad": 1-100}],
  "tipo_movimiento": "string",
  "patron_biomecanico": "string",
  "indicaciones_tecnicas": ["string", "string", "string"],
  "riesgos": ["string"],
  "animacion": {
    "plantilla": "squat|hinge|lunge|push|pull|carry|sprint|jump|agility|core|mobility|sport_skill",
    "fases": [
      {"nombre": "preparacion", "descripcion": "string"},
      {"nombre": "accion", "descripcion": "string"},
      {"nombre": "retorno", "descripcion": "string"}
    ],
    "resaltar": ["musculo", "musculo"]
  }
}

Ejercicio:
{$payload}
PROMPT;
    }

    private function parseOpenAiJson(array $payload): array
    {
        $text = $payload['output_text'] ?? null;

        if (!$text && isset($payload['output']) && is_array($payload['output'])) {
            foreach ($payload['output'] as $output) {
                foreach (($output['content'] ?? []) as $content) {
                    if (($content['type'] ?? null) === 'output_text' && !empty($content['text'])) {
                        $text = $content['text'];
                        break 2;
                    }
                }
            }
        }

        if (!$text) {
            return [];
        }

        $clean = trim($text);
        $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean);
        $clean = preg_replace('/\s*```$/', '', $clean);
        $decoded = json_decode($clean, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function fallbackAnalysis(array $exercise): array
    {
        $name = mb_strtolower($exercise['nombre'] ?? '');
        $group = mb_strtoupper($exercise['grupo_muscular'] ?? '');

        if (str_contains($name, 'squat') || str_contains($name, 'sentadilla') || str_contains($name, 'jump') || str_contains($name, 'salto')) {
            return [
                'musculos_principales' => [
                    ['nombre' => 'Cuadriceps', 'zona' => 'pierna anterior', 'intensidad' => 92],
                    ['nombre' => 'Gluteos', 'zona' => 'cadera posterior', 'intensidad' => 88],
                ],
                'musculos_secundarios' => [
                    ['nombre' => 'Pantorrillas', 'zona' => 'pierna posterior', 'intensidad' => 72],
                    ['nombre' => 'Core', 'zona' => 'tronco', 'intensidad' => 58],
                ],
                'tipo_movimiento' => 'Potencia de tren inferior',
                'patron_biomecanico' => 'Triple extension de cadera, rodilla y tobillo',
                'indicaciones_tecnicas' => [
                    'Mantener rodillas alineadas con los pies.',
                    'Aterrizar suave absorbiendo con cadera y rodillas.',
                    'Usar brazos para coordinar el impulso.',
                ],
                'riesgos' => ['Evitar colapso de rodillas hacia adentro.', 'No aterrizar con piernas rigidas.'],
                'animacion' => [
                    'plantilla' => 'jump',
                    'fases' => [
                        ['nombre' => 'preparacion', 'descripcion' => 'Descenso corto con cadera atras y pecho firme.'],
                        ['nombre' => 'accion', 'descripcion' => 'Impulso explosivo hacia arriba.'],
                        ['nombre' => 'retorno', 'descripcion' => 'Aterrizaje suave y estable.'],
                    ],
                    'resaltar' => ['Cuadriceps', 'Gluteos', 'Pantorrillas', 'Core'],
                ],
            ];
        }

        if (str_contains($name, 'sprint') || str_contains($name, 'pique') || str_contains($name, 'agility') || str_contains($name, 'shuffle')) {
            return [
                'musculos_principales' => [
                    ['nombre' => 'Gluteos', 'zona' => 'cadera posterior', 'intensidad' => 86],
                    ['nombre' => 'Isquiosurales', 'zona' => 'pierna posterior', 'intensidad' => 82],
                ],
                'musculos_secundarios' => [
                    ['nombre' => 'Cuadriceps', 'zona' => 'pierna anterior', 'intensidad' => 72],
                    ['nombre' => 'Core', 'zona' => 'tronco', 'intensidad' => 64],
                ],
                'tipo_movimiento' => 'Velocidad y cambio de direccion',
                'patron_biomecanico' => 'Aceleracion, frenada y re-aceleracion',
                'indicaciones_tecnicas' => [
                    'Mantener centro de gravedad bajo.',
                    'Frenar con pasos cortos antes de cambiar direccion.',
                    'Acelerar con braceo activo.',
                ],
                'riesgos' => ['Evitar frenar con rodilla bloqueada.'],
                'animacion' => [
                    'plantilla' => 'agility',
                    'fases' => [
                        ['nombre' => 'preparacion', 'descripcion' => 'Postura atletica y mirada al frente.'],
                        ['nombre' => 'accion', 'descripcion' => 'Aceleracion o corte segun senal.'],
                        ['nombre' => 'retorno', 'descripcion' => 'Controlar la frenada y recuperar base.'],
                    ],
                    'resaltar' => ['Gluteos', 'Isquiosurales', 'Cuadriceps', 'Core'],
                ],
            ];
        }

        return [
            'musculos_principales' => [
                ['nombre' => $group === 'PECHO' ? 'Pectoral' : 'Grupo principal', 'zona' => $exercise['grupo_muscular'] ?? 'general', 'intensidad' => 80],
            ],
            'musculos_secundarios' => [
                ['nombre' => 'Core', 'zona' => 'tronco', 'intensidad' => 45],
            ],
            'tipo_movimiento' => $exercise['tipo_entrenamiento'] ?? 'General',
            'patron_biomecanico' => 'Movimiento general guiado por tecnica',
            'indicaciones_tecnicas' => ['Controlar postura.', 'Respirar durante el movimiento.', 'Progresar la intensidad gradualmente.'],
            'riesgos' => ['Evitar dolor articular o compensaciones.'],
            'animacion' => [
                'plantilla' => 'sport_skill',
                'fases' => [
                    ['nombre' => 'preparacion', 'descripcion' => 'Adoptar posicion inicial estable.'],
                    ['nombre' => 'accion', 'descripcion' => 'Ejecutar el gesto principal.'],
                    ['nombre' => 'retorno', 'descripcion' => 'Volver con control.'],
                ],
                'resaltar' => [$exercise['grupo_muscular'] ?? 'General', 'Core'],
            ],
        ];
    }
}
