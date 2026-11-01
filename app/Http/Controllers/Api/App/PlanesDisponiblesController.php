<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlanesDisponiblesController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // Catálogo de lectura para usuarios autenticados: no concede permisos
        // de administración ni permite consultar contratos de otros clientes.
        $planes = DB::table('membresias.planes')
            ->where('activo', true)
            ->orderBy('nombre')
            ->get([
                'id', 'nombre', 'descripcion', 'tipo_producto', 'tipo_duracion',
                'duracion', 'precio_base', 'requiere_modalidades', 'renovable',
            ]);

        $ids = $planes->pluck('id')->all();
        $modalidades = empty($ids) ? collect() : DB::table('membresias.plan_modalidades')
            ->whereIn('plan_id', $ids)
            ->where('activo', true)
            ->get(['id', 'plan_id', 'nombre', 'tipo_duracion', 'duracion', 'precio_base'])
            ->groupBy('plan_id');

        $items = $planes->map(function ($plan) use ($modalidades): array {
            $opciones = ($modalidades->get($plan->id) ?? collect())
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'nombre' => $m->nombre,
                    'tipo_duracion' => $m->tipo_duracion,
                    'duracion' => (int) $m->duracion,
                    'precio_base' => (float) $m->precio_base,
                ])->values()->all();

            $precio = $plan->requiere_modalidades
                ? (empty($opciones) ? null : min(array_column($opciones, 'precio_base')))
                : (float) $plan->precio_base;

            return [
                'id' => $plan->id,
                'nombre' => $plan->nombre,
                'descripcion' => $plan->descripcion,
                'tipo_producto' => $plan->tipo_producto,
                'tipo_duracion' => $plan->tipo_duracion,
                'duracion' => (int) $plan->duracion,
                'precio' => $precio,
                'renovable' => (bool) $plan->renovable,
                'requiere_modalidades' => (bool) $plan->requiere_modalidades,
                'modalidades' => $opciones,
            ];
        })->values()->all();

        return ApiResponse::exito('Planes disponibles consultados.', $items);
    }
}
