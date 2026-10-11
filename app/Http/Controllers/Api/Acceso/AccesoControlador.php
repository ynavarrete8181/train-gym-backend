<?php

namespace App\Http\Controllers\Api\Acceso;

use App\Http\Controllers\Controller;
use App\Services\Acceso\AccesoServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccesoControlador extends Controller
{
    public function __construct(private readonly AccesoServicio $acceso)
    {
    }

    public function dispositivos(Request $request) { return $this->respuesta('Dispositivos consultados.', $this->acceso->listarDispositivos($request->all())); }
    public function credenciales(Request $request) { return $this->respuesta('Credenciales consultadas.', $this->acceso->listarCredenciales($request->all())); }
    public function eventos(Request $request) { return $this->respuesta('Eventos consultados.', $this->acceso->listarEventos($request->all())); }
    public function asistencias(Request $request) { return $this->respuesta('Asistencias consultadas.', $this->acceso->listarAsistencias($request->all())); }

    public function guardarDispositivo(Request $request, ?int $id = null)
    {
        $datos = $request->validate([
            'sede_id' => 'nullable|exists:pgsql.institucional.sedes,id_sede',
            'nombre' => 'required|string|max:120',
            'tipo' => 'required|string|in:MANUAL,QR,BIOMETRICO,TORNIQUETE,APP',
            'proveedor' => 'nullable|string|max:80',
            'identificador_externo' => 'nullable|string|max:120',
            'activo' => 'boolean',
        ]);

        return ApiResponse::exito('Dispositivo guardado correctamente.', (array) $this->acceso->guardarDispositivo($datos, $id), [], $id ? 200 : 201);
    }

    public function guardarCredencial(Request $request, ?int $id = null)
    {
        $datos = $request->validate([
            'cliente_id' => 'required|exists:pgsql.gimnasio.deportistas,id',
            'tipo' => 'required|string|in:QR,TARJETA,CODIGO,APP',
            'codigo' => ['required', 'string', 'max:120', Rule::unique('acceso.credenciales', 'codigo')->ignore($id)],
            'estado' => 'required|string|in:ACTIVA,INACTIVA,BLOQUEADA,VENCIDA',
            'vigencia_inicio' => 'nullable|date',
            'vigencia_fin' => 'nullable|date|after_or_equal:vigencia_inicio',
        ]);

        return ApiResponse::exito('Credencial guardada correctamente.', (array) $this->acceso->guardarCredencial($datos, $id), [], $id ? 200 : 201);
    }

    public function registrarEvento(Request $request)
    {
        $datos = $request->validate([
            'dispositivo_id' => 'nullable|exists:pgsql.acceso.dispositivos,id',
            'cliente_id' => 'nullable|exists:pgsql.gimnasio.deportistas,id',
            'sede_id' => 'nullable|exists:pgsql.institucional.sedes,id_sede',
            'codigo_credencial' => 'nullable|string|max:120',
            'tipo_evento' => 'required|string|in:INGRESO,SALIDA,VALIDACION',
            'payload_raw' => 'nullable|array',
        ]);

        return ApiResponse::exito('Evento registrado correctamente.', (array) $this->acceso->registrarEvento($datos), [], 201);
    }

    public function registrarAsistencia(Request $request)
    {
        $datos = $request->validate([
            'cliente_id' => 'required|exists:pgsql.gimnasio.deportistas,id',
            'membresia_id' => 'nullable|exists:pgsql.gimnasio.membresias,id',
            'sede_id' => 'nullable|exists:pgsql.institucional.sedes,id_sede',
            'tipo' => 'required|string|in:INGRESO,SALIDA,CLASE,ENTRENAMIENTO',
            'metodo' => 'required|string|in:MANUAL,CREDENCIAL,APP',
            'estado' => 'required|string|in:VALIDA,OBSERVADA,ANULADA',
            'observaciones' => 'nullable|string',
        ]);
        $datos['fecha_hora'] = now();

        return ApiResponse::exito('Asistencia registrada correctamente.', (array) $this->acceso->registrarAsistencia($datos), [], 201);
    }

    private function respuesta(string $mensaje, $paginador)
    {
        return ApiResponse::exito($mensaje, $paginador->items(), [
            'pagina_actual' => $paginador->currentPage(),
            'por_pagina' => $paginador->perPage(),
            'total' => $paginador->total(),
            'ultima_pagina' => $paginador->lastPage(),
            'opciones_filtro' => $this->acceso->opcionesFiltro(),
            'catalogos' => $this->acceso->catalogos(),
        ]);
    }
}
