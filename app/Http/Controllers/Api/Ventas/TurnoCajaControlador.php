<?php

namespace App\Http\Controllers\Api\Ventas;

use App\Http\Controllers\Controller;
use App\Services\Ventas\TurnoCajaServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class TurnoCajaControlador extends Controller
{
    public function __construct(private readonly TurnoCajaServicio $turnos)
    {
    }

    public function index(Request $request)
    {
        $usuarioId = (int) $request->user()->id;
        $paginador = $this->turnos->listar($request->all(), $usuarioId);

        return ApiResponse::exito('Turnos de caja consultados.', $paginador->items(), [
            'pagina_actual' => $paginador->currentPage(),
            'por_pagina' => $paginador->perPage(),
            'total' => $paginador->total(),
            'ultima_pagina' => $paginador->lastPage(),
            'opciones_filtro' => $this->turnos->opcionesFiltro($usuarioId),
            'catalogos' => $this->turnos->catalogos($usuarioId),
        ]);
    }

    public function abrir(Request $request)
    {
        $datos = $request->validate([
            'caja_id' => 'required|exists:pgsql.ventas.cajas,id',
            'saldo_inicial' => 'required|numeric|min:0',
            'observaciones' => 'nullable|string|max:1000',
        ]);

        return ApiResponse::exito(
            'Turno de caja abierto correctamente.',
            (array) $this->turnos->abrir($datos, (int) $request->user()->id),
            [],
            201,
        );
    }

    public function cerrar(Request $request, int $id)
    {
        $datos = $request->validate([
            'efectivo_contado' => 'required|numeric|min:0',
            'observaciones' => 'nullable|string|max:1000',
        ]);

        return ApiResponse::exito(
            'Turno de caja cerrado correctamente.',
            (array) $this->turnos->cerrar($id, $datos, (int) $request->user()->id),
        );
    }

    public function actual(Request $request)
    {
        return ApiResponse::exito(
            'Turno actual consultado.',
            (array) ($this->turnos->turnoAbiertoUsuario((int) $request->user()->id) ?? []),
            ['catalogos' => $this->turnos->catalogos((int) $request->user()->id)],
        );
    }
}
