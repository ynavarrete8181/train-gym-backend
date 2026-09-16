<?php

namespace App\Http\Controllers\Api\Gimnasio;

use App\Http\Controllers\Controller;
use App\Services\Gimnasio\PlanServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlanControlador extends Controller
{
    protected PlanServicio $planServicio;

    public function __construct(PlanServicio $planServicio)
    {
        $this->planServicio = $planServicio;
    }

    public function index(Request $request)
    {
        $planes = DB::table('gimnasio.planes')
            ->orderBy('nombre')
            ->paginate($request->input('per_page', 10));

        $ids = collect($planes->items())->pluck('id')->all();
        $precios = DB::table('gimnasio.plan_precios_sede as pps')
            ->join('institucional.sedes as s', 's.id_sede', '=', 'pps.sede_id')
            ->whereIn('pps.plan_id', $ids)
            ->where('pps.activo', true)
            ->select('pps.*', 's.nombre as sede_nombre')
            ->get()
            ->groupBy('plan_id');

        $items = collect($planes->items())->map(function ($plan) use ($precios) {
            $plan->precios_sede = $precios->get($plan->id, collect())->values();
            return $plan;
        });

        return ApiResponse::exito('Planes consultados', $items, [
            'pagina_actual' => $planes->currentPage(),
            'por_pagina' => $planes->perPage(),
            'total' => $planes->total(),
            'ultima_pagina' => $planes->lastPage(),
        ]);
    }

    public function store(Request $request)
    {
        $validados = $this->validar($request);
        $plan = $this->planServicio->crear($validados);

        return ApiResponse::exito('Plan creado correctamente.', (array) $plan, [], 201);
    }

    public function show($id)
    {
        $plan = DB::table('gimnasio.planes')->where('id', $id)->first();

        if (! $plan) {
            return response()->json(['mensaje' => 'Plan no encontrado'], 404);
        }

        $plan->precios_sede = DB::table('gimnasio.plan_precios_sede as pps')
            ->join('institucional.sedes as s', 's.id_sede', '=', 'pps.sede_id')
            ->where('pps.plan_id', $id)
            ->where('pps.activo', true)
            ->select('pps.*', 's.nombre as sede_nombre')
            ->get();

        return ApiResponse::exito('Plan consultado.', (array) $plan);
    }

    public function update(Request $request, $id)
    {
        $plan = DB::table('gimnasio.planes')->where('id', $id)->first();

        if (! $plan) {
            return response()->json(['mensaje' => 'Plan no encontrado'], 404);
        }

        $validados = $this->validar($request, (int) $id);
        $planActualizado = $this->planServicio->actualizar($id, $validados);

        return ApiResponse::exito('Plan actualizado correctamente.', (array) $planActualizado);
    }

    public function destroy($id)
    {
        $plan = DB::table('gimnasio.planes')->where('id', $id)->first();
        if (!$plan) {
            return response()->json(['mensaje' => 'Plan no encontrado'], 404);
        }

        $enUso = DB::table('gimnasio.membresias')->where('plan_id', $id)->exists();
        if ($enUso) {
            return response()->json(['mensaje' => 'El plan no se puede eliminar porque tiene membresías asociadas.'], 409);
        }

        DB::table('gimnasio.planes')->where('id', $id)->delete();
        return ApiResponse::exito('Plan eliminado exitosamente.');
    }

    private function validar(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'codigo' => 'required|string|max:50|unique:pgsql.gimnasio.planes,codigo' . ($id ? ',' . $id : ''),
            'nombre' => 'required|string|max:150',
            'descripcion' => 'nullable|string',
            'tipo_duracion' => 'required|string|max:20|in:DIAS,MESES,ANIOS',
            'duracion' => 'required|integer|min:1',
            'precio_base' => 'required|numeric|min:0',
            'tarifa_inscripcion' => 'nullable|numeric|min:0',
            'tipo_producto' => 'required|string|in:MEMBRESIA,PASE_DIARIO,PAQUETE_VISITAS,PAQUETE_SESIONES',
            'tipo_cobro' => 'required|string|in:PAGO_UNICO,RECURRENTE',
            'generar_venta' => 'boolean',
            'requiere_pago' => 'boolean',
            'renovable' => 'boolean',
            'activo' => 'boolean',
            'precios_sede' => 'nullable|array',
            'precios_sede.*.sede_id' => 'required|exists:pgsql.institucional.sedes,id_sede',
            'precios_sede.*.precio' => 'required|numeric|min:0',
        ]);
    }
}
