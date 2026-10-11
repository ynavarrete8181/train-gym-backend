<?php

namespace App\Http\Controllers\Api\Ventas;

use App\Http\Controllers\Controller;
use App\Services\CuentasCobrar\CarteraServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CarteraControlador extends Controller
{
    public function __construct(private readonly CarteraServicio $cartera)
    {
    }

    public function index(Request $request)
    {
        $usuarioId = (int) $request->user()->id;
        $paginador = $this->cartera->listar($request->all(), $usuarioId);

        return ApiResponse::exito('Cartera consultada.', $paginador->items(), [
            'pagina_actual' => $paginador->currentPage(),
            'por_pagina' => $paginador->perPage(),
            'total' => $paginador->total(),
            'ultima_pagina' => $paginador->lastPage(),
            'resumen' => $this->cartera->resumen($usuarioId),
            'catalogos' => $this->cartera->catalogos($usuarioId),
        ]);
    }

    public function detalle(Request $request, int $id)
    {
        return ApiResponse::exito(
            'Detalle de cartera consultado.',
            (array) $this->cartera->detalle($id, (int) $request->user()->id),
        );
    }

    public function actualizar(Request $request, int $id)
    {
        $datos = $request->validate([
            'fecha_vencimiento' => 'sometimes|required|date',
            'prioridad' => ['sometimes', 'required', 'string', Rule::in(['BAJA', 'NORMAL', 'ALTA', 'URGENTE'])],
            'responsable_id' => 'sometimes|nullable|exists:pgsql.seguridad.users,id',
            'proxima_gestion_at' => 'sometimes|nullable|date',
            'observaciones' => 'sometimes|nullable|string|max:2000',
        ]);

        return ApiResponse::exito(
            'Cuenta por cobrar actualizada.',
            (array) $this->cartera->actualizar($id, $datos, (int) $request->user()->id),
        );
    }

    public function registrarGestion(Request $request, int $id)
    {
        $datos = $request->validate([
            'tipo' => ['required', 'string', Rule::in(['LLAMADA', 'WHATSAPP', 'CORREO', 'PRESENCIAL', 'NOTA'])],
            'resultado' => ['nullable', 'string', Rule::in(['CONTACTADO', 'NO_CONTACTADO', 'COMPROMISO', 'INFORMATIVO'])],
            'detalle' => 'required|string|max:3000',
            'gestion_at' => 'nullable|date',
            'proxima_gestion_at' => 'nullable|date|after_or_equal:gestion_at',
        ]);

        return ApiResponse::exito(
            'Gestión de cartera registrada.',
            (array) $this->cartera->registrarGestion($id, $datos, (int) $request->user()->id),
            [],
            201,
        );
    }

    public function registrarCompromiso(Request $request, int $id)
    {
        $datos = $request->validate([
            'monto' => 'required|numeric|min:0.01',
            'fecha_compromiso' => 'required|date|after_or_equal:today',
            'observaciones' => 'nullable|string|max:2000',
        ]);

        return ApiResponse::exito(
            'Compromiso de pago registrado.',
            (array) $this->cartera->registrarCompromiso($id, $datos, (int) $request->user()->id),
            [],
            201,
        );
    }

    public function actualizarCompromiso(Request $request, int $id)
    {
        $datos = $request->validate([
            'estado' => ['required', 'string', Rule::in(['PENDIENTE', 'CUMPLIDO', 'INCUMPLIDO', 'CANCELADO'])],
        ]);

        return ApiResponse::exito(
            'Compromiso actualizado.',
            (array) $this->cartera->actualizarCompromiso($id, $datos['estado'], (int) $request->user()->id),
        );
    }
}
