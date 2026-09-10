<?php

namespace App\Http\Controllers\Api\Comunicaciones;

use App\Http\Controllers\Controller;
use App\Services\Comunicaciones\ComunicacionServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ComunicacionControlador extends Controller
{
    public function __construct(private readonly ComunicacionServicio $comunicaciones)
    {
    }

    public function tipos(Request $request) { return $this->respuesta('Tipos de comunicacion consultados.', $this->comunicaciones->listarTipos($request->all())); }
    public function segmentos(Request $request) { return $this->respuesta('Segmentos consultados.', $this->comunicaciones->listarSegmentos($request->all())); }
    public function mensajes(Request $request) { return $this->respuesta('Mensajes consultados.', $this->comunicaciones->listarMensajes($request->all())); }
    public function programaciones(Request $request) { return $this->respuesta('Programaciones consultadas.', $this->comunicaciones->listarProgramaciones($request->all())); }

    public function guardarTipo(Request $request, ?int $id = null)
    {
        $datos = $request->validate([
            'codigo' => ['required', 'string', 'max:40', Rule::unique('comunicaciones.tipos_comunicacion', 'codigo')->ignore($id)],
            'nombre' => 'required|string|max:120',
            'descripcion' => 'nullable|string',
            'canal_preferido' => 'required|string|in:SISTEMA,CORREO,PUSH,APP',
            'activo' => 'boolean',
        ]);

        return ApiResponse::exito('Tipo de comunicacion guardado.', (array) $this->comunicaciones->guardarTipo($datos, $id), [], $id ? 200 : 201);
    }

    public function guardarSegmento(Request $request, ?int $id = null)
    {
        $datos = $request->validate([
            'codigo' => ['required', 'string', 'max:40', Rule::unique('comunicaciones.segmentos', 'codigo')->ignore($id)],
            'nombre' => 'required|string|max:120',
            'descripcion' => 'nullable|string',
            'criterios' => 'nullable|array',
            'activo' => 'boolean',
        ]);

        return ApiResponse::exito('Segmento guardado.', (array) $this->comunicaciones->guardarSegmento($datos, $id), [], $id ? 200 : 201);
    }

    public function guardarMensaje(Request $request, ?int $id = null)
    {
        $datos = $request->validate([
            'tipo_id' => 'nullable|exists:pgsql.comunicaciones.tipos_comunicacion,id',
            'segmento_id' => 'nullable|exists:pgsql.comunicaciones.segmentos,id',
            'titulo' => 'required|string|max:180',
            'contenido' => 'required|string',
            'canal' => 'required|string|in:SISTEMA,CORREO,PUSH,APP',
            'estado' => 'required|string|in:BORRADOR,PROGRAMADO,ENVIADO,CANCELADO',
            'programado_at' => 'nullable|date',
        ]);

        return ApiResponse::exito('Mensaje guardado.', (array) $this->comunicaciones->guardarMensaje($datos, $id, $request->user()?->id), [], $id ? 200 : 201);
    }

    public function guardarProgramacion(Request $request, ?int $id = null)
    {
        $datos = $request->validate([
            'mensaje_id' => 'required|exists:pgsql.comunicaciones.mensajes,id',
            'nombre' => 'required|string|max:120',
            'frecuencia' => 'required|string|in:UNICA,DIARIA,SEMANAL,MENSUAL,EVENTO',
            'proxima_ejecucion' => 'nullable|date',
            'estado' => 'required|string|in:ACTIVA,PAUSADA,FINALIZADA',
            'observaciones' => 'nullable|string',
        ]);

        return ApiResponse::exito('Programacion guardada.', (array) $this->comunicaciones->guardarProgramacion($datos, $id), [], $id ? 200 : 201);
    }

    private function respuesta(string $mensaje, $paginador)
    {
        return ApiResponse::exito($mensaje, $paginador->items(), [
            'pagina_actual' => $paginador->currentPage(),
            'por_pagina' => $paginador->perPage(),
            'total' => $paginador->total(),
            'ultima_pagina' => $paginador->lastPage(),
            'opciones_filtro' => $this->comunicaciones->opcionesFiltro(),
            'catalogos' => $this->comunicaciones->catalogos(),
        ]);
    }
}
