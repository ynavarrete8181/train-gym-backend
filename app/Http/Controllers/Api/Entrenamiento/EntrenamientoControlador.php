<?php

namespace App\Http\Controllers\Api\Entrenamiento;

use App\Http\Controllers\Controller;
use App\Services\Entrenamiento\EntrenamientoServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EntrenamientoControlador extends Controller
{
    public function __construct(private readonly EntrenamientoServicio $entrenamiento)
    {
    }

    public function ejercicios(Request $request)
    {
        return $this->respuestaPaginada('Ejercicios consultados.', $this->entrenamiento->listarEjercicios($request->all()));
    }

    public function guardarEjercicio(Request $request, ?int $id = null)
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:150', Rule::unique('entrenamiento.ejercicios', 'nombre')->ignore($id)],
            'grupo_muscular' => 'required|string|max:60',
            'equipamiento' => 'required|string|max:80',
            'tipo_entrenamiento' => 'nullable|string|max:80',
            'instrucciones' => 'nullable|string',
            'url_recurso' => 'nullable|string',
            'activo' => 'boolean',
        ]);

        return ApiResponse::exito('Ejercicio guardado correctamente.', (array) $this->entrenamiento->guardarEjercicio($datos, $id), [], $id ? 200 : 201);
    }

    public function planes(Request $request)
    {
        return $this->respuestaPaginada('Planes consultados.', $this->entrenamiento->listarPlanes($request->all()));
    }

    public function guardarPlan(Request $request, ?int $id = null)
    {
        $datos = $request->validate([
            'cliente_id' => 'required|exists:pgsql.gimnasio.deportistas,id',
            'entrenador_id' => 'nullable|exists:pgsql.seguridad.users,id',
            'nombre' => 'required|string|max:150',
            'objetivo' => 'nullable|string',
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'nullable|date|after_or_equal:fecha_inicio',
            'estado' => 'required|string|in:BORRADOR,ACTIVO,PAUSADO,FINALIZADO',
            'observaciones' => 'nullable|string',
        ]);

        return ApiResponse::exito('Plan guardado correctamente.', (array) $this->entrenamiento->guardarPlan($datos, $id), [], $id ? 200 : 201);
    }

    public function rutinas(Request $request)
    {
        return $this->respuestaPaginada('Rutinas consultadas.', $this->entrenamiento->listarRutinas($request->all()));
    }

    public function guardarRutina(Request $request, ?int $id = null)
    {
        $datos = $request->validate([
            'plan_id' => 'required|exists:pgsql.entrenamiento.planes,id',
            'ejercicio_id' => 'required|exists:pgsql.entrenamiento.ejercicios,id',
            'semana' => 'required|integer|min:1|max:52',
            'dia' => 'required|string|max:30',
            'bloque' => 'nullable|string|max:120',
            'series' => 'required|integer|min:1|max:50',
            'repeticiones' => 'nullable|string|max:50',
            'carga_objetivo' => 'nullable|numeric|min:0',
            'tipo_carga' => 'required|string|max:30',
            'descanso_segundos' => 'nullable|integer|min:0|max:3600',
            'orden' => 'required|integer|min:1|max:500',
            'notas' => 'nullable|string',
        ]);

        return ApiResponse::exito('Rutina guardada correctamente.', (array) $this->entrenamiento->guardarRutina($datos, $id), [], $id ? 200 : 201);
    }

    public function rm(Request $request)
    {
        return $this->respuestaPaginada('Registros RM consultados.', $this->entrenamiento->listarRm($request->all()));
    }

    public function guardarRm(Request $request, ?int $id = null)
    {
        $datos = $request->validate([
            'cliente_id' => 'required|exists:pgsql.gimnasio.deportistas,id',
            'ejercicio_id' => 'required|exists:pgsql.entrenamiento.ejercicios,id',
            'tipo_registro' => 'required|string|in:DIRECTO,ESTIMADO',
            'peso' => 'required|numeric|min:0',
            'repeticiones' => 'nullable|integer|min:1|max:200',
            'rm_estimado' => 'required|numeric|min:0',
            'fecha_registro' => 'required|date',
            'observaciones' => 'nullable|string',
        ]);

        return ApiResponse::exito('Registro RM guardado correctamente.', (array) $this->entrenamiento->guardarRm($datos, $id), [], $id ? 200 : 201);
    }

    public function ultimosRm(Request $request)
    {
        $clienteId = (int) $request->query('cliente_id');
        if (!$clienteId) {
            return response()->json(['mensaje' => 'cliente_id es requerido'], 422);
        }

        return ApiResponse::exito('Últimos RM consultados.', $this->entrenamiento->ultimosRmPorCliente($clienteId));
    }

    public function progreso(Request $request)
    {
        return $this->respuestaPaginada('Progreso consultado.', $this->entrenamiento->listarProgreso($request->all()));
    }

    public function guardarProgreso(Request $request, ?int $id = null)
    {
        $datos = $request->validate([
            'cliente_id' => 'required|exists:pgsql.gimnasio.deportistas,id',
            'fecha_registro' => 'required|date',
            'peso_kg' => 'nullable|numeric|min:0',
            'talla_cm' => 'nullable|numeric|min:0',
            'cintura_cm' => 'nullable|numeric|min:0',
            'grasa_corporal_pct' => 'nullable|numeric|min:0|max:100',
            'objetivo' => 'nullable|string|max:150',
            'observaciones' => 'nullable|string',
        ]);

        return ApiResponse::exito('Progreso guardado correctamente.', (array) $this->entrenamiento->guardarProgreso($datos, $id), [], $id ? 200 : 201);
    }

    public function eliminarProgreso($id)
    {
        $this->entrenamiento->eliminarProgreso((int) $id);

        return ApiResponse::exito('Registro de progreso eliminado correctamente.');
    }

    private function respuestaPaginada(string $mensaje, $paginador)
    {
        return ApiResponse::exito($mensaje, $paginador->items(), [
            'pagina_actual' => $paginador->currentPage(),
            'por_pagina' => $paginador->perPage(),
            'total' => $paginador->total(),
            'ultima_pagina' => $paginador->lastPage(),
            'opciones_filtro' => $this->entrenamiento->opcionesFiltro(),
            'catalogos' => $this->entrenamiento->catalogos(),
        ]);
    }
}
