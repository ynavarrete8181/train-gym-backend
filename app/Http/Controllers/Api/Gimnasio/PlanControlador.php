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
        $planes = DB::table('membresias.planes')
            ->orderBy('nombre')
            ->paginate($request->input('per_page', 10));

        $ids = collect($planes->items())->pluck('id')->all();
        $precios = DB::table('membresias.plan_precios_sede as pps')
            ->join('institucional.sedes as s', 's.id_sede', '=', 'pps.sede_id')
            ->whereIn('pps.plan_id', $ids)
            ->where('pps.activo', true)
            ->select('pps.*', 's.nombre as sede_nombre')
            ->get()
            ->groupBy('plan_id');

        $servicios = DB::table('membresias.plan_servicios as ps')
            ->join('servicios.servicios as s', 's.id', '=', 'ps.servicio_id')
            ->leftJoin('servicios.categorias_servicio as c', 'c.id', '=', 's.categoria_id')
            ->whereIn('ps.plan_id', $ids)
            ->where('ps.activo', true)
            ->select('ps.plan_id', 's.id', 's.nombre', 's.duracion_minutos', 'c.nombre as categoria')
            ->orderBy('s.nombre')
            ->get()
            ->groupBy('plan_id');

        $items = collect($planes->items())->map(function ($plan) use ($precios, $servicios) {
            $plan->precios_sede = $precios->get($plan->id, collect())->values();
            $plan->servicios = $servicios->get($plan->id, collect())->values();
            $plan->servicio_ids = $plan->servicios->pluck('id')->map(fn ($id) => (int) $id)->values();
            $plan->modalidades = $this->planServicio->modalidadesDelPlan((int) $plan->id);
            return $plan;
        });

        return ApiResponse::exito('Planes consultados', $items, [
            'pagina_actual' => $planes->currentPage(),
            'por_pagina' => $planes->perPage(),
            'total' => $planes->total(),
            'ultima_pagina' => $planes->lastPage(),
        ]);
    }

    public function serviciosCatalogo()
    {
        $servicios = DB::table('servicios.servicios as s')
            ->leftJoin('servicios.categorias_servicio as c', 'c.id', '=', 's.categoria_id')
            ->where('s.activo', true)
            ->orderBy('c.nombre')
            ->orderBy('s.nombre')
            ->get([
                's.id',
                's.nombre',
                's.duracion_minutos',
                'c.nombre as categoria',
            ]);

        return ApiResponse::exito('Servicios disponibles para planes consultados.', $servicios);
    }

    public function store(Request $request)
    {
        $validados = $this->validar($request);
        $plan = $this->planServicio->crear($validados);

        return ApiResponse::exito('Plan creado correctamente.', (array) $plan, [], 201);
    }

    public function show($id)
    {
        $plan = DB::table('membresias.planes')->where('id', $id)->first();

        if (! $plan) {
            return response()->json(['mensaje' => 'Plan no encontrado'], 404);
        }

        $plan->precios_sede = DB::table('membresias.plan_precios_sede as pps')
            ->join('institucional.sedes as s', 's.id_sede', '=', 'pps.sede_id')
            ->where('pps.plan_id', $id)
            ->where('pps.activo', true)
            ->select('pps.*', 's.nombre as sede_nombre')
            ->get();

        $plan->servicios = DB::table('membresias.plan_servicios as ps')
            ->join('servicios.servicios as s', 's.id', '=', 'ps.servicio_id')
            ->leftJoin('servicios.categorias_servicio as c', 'c.id', '=', 's.categoria_id')
            ->where('ps.plan_id', $id)
            ->where('ps.activo', true)
            ->orderBy('s.nombre')
            ->get(['s.id', 's.nombre', 's.duracion_minutos', 'c.nombre as categoria']);
        $plan->servicio_ids = $plan->servicios->pluck('id')->map(fn ($servicioId) => (int) $servicioId)->values();
        $plan->modalidades = $this->planServicio->modalidadesDelPlan((int) $id);

        return ApiResponse::exito('Plan consultado.', (array) $plan);
    }

    public function update(Request $request, $id)
    {
        $plan = DB::table('membresias.planes')->where('id', $id)->first();

        if (! $plan) {
            return response()->json(['mensaje' => 'Plan no encontrado'], 404);
        }

        $validados = $this->validar($request, (int) $id);
        $planActualizado = $this->planServicio->actualizar($id, $validados);

        return ApiResponse::exito('Plan actualizado correctamente.', (array) $planActualizado);
    }

    public function modalidades(int $id)
    {
        $plan = DB::table('membresias.planes')->where('id', $id)->first();
        if (! $plan) {
            return response()->json(['mensaje' => 'Plan no encontrado'], 404);
        }

        return ApiResponse::exito(
            'Modalidades consultadas.',
            $this->planServicio->modalidadesDelPlan((int) $id)->values()
        );
    }

    public function storeModalidad(Request $request, int $id)
    {
        $datos = $this->validarModalidad($request);
        $modalidad = $this->planServicio->guardarModalidad($id, $datos);

        return ApiResponse::exito('Modalidad creada correctamente.', (array) $modalidad, [], 201);
    }

    public function updateModalidad(Request $request, int $id, int $modalidadId)
    {
        $datos = $this->validarModalidad($request, $modalidadId, $id);
        $modalidad = $this->planServicio->guardarModalidad($id, $datos, $modalidadId);

        return ApiResponse::exito('Modalidad actualizada correctamente.', (array) $modalidad);
    }

    public function destroyModalidad(int $id, int $modalidadId)
    {
        $this->planServicio->eliminarModalidad($id, $modalidadId);
        return ApiResponse::exito('Modalidad eliminada correctamente.');
    }

    public function destroy($id)
    {
        $plan = DB::table('membresias.planes')->where('id', $id)->first();
        if (!$plan) {
            return response()->json(['mensaje' => 'Plan no encontrado'], 404);
        }

        $enUso = DB::table('membresias.membresias')->where('plan_id', $id)->exists();
        if ($enUso) {
            return response()->json(['mensaje' => 'El plan no se puede eliminar porque tiene membresías asociadas.'], 409);
        }

        DB::table('membresias.planes')->where('id', $id)->delete();
        return ApiResponse::exito('Plan eliminado exitosamente.');
    }

    private function validar(Request $request, ?int $id = null): array
    {
        $datos = $request->validate([
            'codigo' => 'nullable|string|max:50|unique:pgsql.membresias.planes,codigo' . ($id ? ',' . $id : ''),
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
            'requiere_entrenador' => 'boolean',
            'renovable' => 'boolean',
            'requiere_modalidades' => 'boolean',
            'activo' => 'boolean',
            'precios_sede' => 'nullable|array',
            'precios_sede.*.sede_id' => 'required|distinct|exists:pgsql.institucional.sedes,id_sede',
            'precios_sede.*.precio' => 'required|numeric|min:0',
            'servicio_ids' => 'nullable|array',
            'servicio_ids.*' => 'required|integer|distinct|exists:pgsql.servicios.servicios,id',
        ]);

        if (($datos['tipo_producto'] ?? null) === 'PASE_DIARIO') {
            $datos['tipo_cobro'] = 'PAGO_UNICO';
            $datos['tipo_duracion'] = 'DIAS';
            $datos['duracion'] = 1;
            $datos['tarifa_inscripcion'] = 0;
            $datos['renovable'] = false;
        }

        $requiereModalidades = (bool) ($datos['requiere_modalidades'] ?? false);
        if ($requiereModalidades) {
            $datos['tipo_duracion'] = 'DIAS';
            $datos['duracion'] = 1;
            $datos['precio_base'] = 0;
            $datos['tarifa_inscripcion'] = 0;
            $datos['precios_sede'] = [];
        }

        $requiereEntrenador = (bool) ($datos['requiere_entrenador'] ?? false);
        $servicioIds = collect($datos['servicio_ids'] ?? [])
            ->map(fn ($servicioId) => (int) $servicioId)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($requiereEntrenador && empty($servicioIds)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'servicio_ids' => 'Selecciona al menos un servicio incluido para un plan que requiere entrenador.',
            ]);
        }

        if (! empty($servicioIds)) {
            $serviciosActivos = DB::table('servicios.servicios')
                ->whereIn('id', $servicioIds)
                ->where('activo', true)
                ->count();

            if ($serviciosActivos !== count($servicioIds)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'servicio_ids' => 'Todos los servicios incluidos deben estar activos.',
                ]);
            }
        }

        $datos['servicio_ids'] = $requiereEntrenador ? $servicioIds : [];

        if ($id) {
            unset($datos['codigo']);
        } elseif (empty($datos['codigo'])) {
            unset($datos['codigo']);
        }

        return $datos;


    private function validarModalidad(Request $request, ?int $modalidadId = null, ?int $planId = null): array
    {
        $unique = 'unique:pgsql.membresias.plan_modalidades,codigo';
        if ($modalidadId) {
            $unique .= ',' . $modalidadId;
        }

        $datos = $request->validate([
            'codigo' => ['nullable', 'string', 'max:60', $unique],
            'nombre' => 'required|string|max:120',
            'descripcion' => 'nullable|string|max:1000',
            'dias_por_semana' => 'nullable|integer|min:1|max:7|required_if:uso_ilimitado,false',
            'usos_por_semana' => 'nullable|integer|min:1|max:30|required_if:uso_ilimitado,false',
            'uso_ilimitado' => 'boolean',
            'tipo_duracion' => 'required|string|in:DIAS,SEMANAS,MESES,ANIOS',
            'duracion' => 'required|integer|min:1|max:365',
            'precio_base' => 'required|numeric|min:0',
            'tarifa_inscripcion' => 'nullable|numeric|min:0',
            'modelo_cobro' => 'required|string|in:FIJO_POR_PERIODO,PRORRATEO_POR_SEMANAS_UTILIZADAS',
            'momento_cobro' => 'required|string|in:ANTICIPADO,VENCIDO',
            'permite_prorrateo' => 'boolean',
            'permite_extension' => 'boolean',
            'extension_automatica' => 'boolean',
            'permite_rollover' => 'boolean',
            'activo' => 'boolean',
            'precios_sede' => 'nullable|array',
            'precios_sede.*.sede_id' => 'required|integer|distinct|exists:pgsql.institucional.sedes,id_sede',
            'precios_sede.*.precio' => 'required|numeric|min:0',
        ]);

        if (($datos['modelo_cobro'] ?? null) === 'PRORRATEO_POR_SEMANAS_UTILIZADAS') {
            $datos['permite_prorrateo'] = true;
            $datos['momento_cobro'] = 'VENCIDO';
        }

        if (($datos['uso_ilimitado'] ?? false) === true) {
            $datos['dias_por_semana'] = null;
            $datos['usos_por_semana'] = null;
        }

        if (($datos['extension_automatica'] ?? false) && ! ($datos['permite_extension'] ?? false)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'extension_automatica' => 'Para extender automáticamente, primero debe permitirse la extensión.',
            ]);
        }

        return $datos;
    }
    }
}
