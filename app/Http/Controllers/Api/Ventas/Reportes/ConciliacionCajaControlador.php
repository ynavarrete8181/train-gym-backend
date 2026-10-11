<?php

namespace App\Http\Controllers\Api\Ventas\Reportes;

use App\Http\Controllers\Controller;
use App\Services\Ventas\Reportes\ConciliacionCajaServicio;
use App\Services\Ventas\Reportes\Exportaciones\ConciliacionCajaExportacionServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ConciliacionCajaControlador extends Controller
{
    public function __construct(
        private readonly ConciliacionCajaServicio $servicio,
        private readonly ConciliacionCajaExportacionServicio $exportacion,
    ) {
    }

    public function index(Request $request)
    {
        $filtros = $request->validate([
            'busqueda' => 'nullable|string|max:120',
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date|after_or_equal:desde',
            'fecha' => 'nullable|string|max:30',
            'sede_id' => 'nullable',
            'caja' => 'nullable|string|max:120',
            'apertura' => 'nullable|string|max:10',
            'cierre' => 'nullable|string|max:10',
            'cajero' => 'nullable|string|max:120',
            'saldo_inicial' => 'nullable|string|max:30',
            'efectivo_cobrado' => 'nullable|string|max:30',
            'efectivo_esperado' => 'nullable|string|max:30',
            'efectivo_contado' => 'nullable|string|max:30',
            'diferencia' => 'nullable|string|max:30',
            'transferencia' => 'nullable|string|max:30',
            'tarjeta' => 'nullable|string|max:30',
            'deposito' => 'nullable|string|max:30',
            'otros' => 'nullable|string|max:30',
            'tipo_cierre' => 'nullable',
            'estado_conciliacion' => 'nullable',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|in:5,10,25,50',
        ]);

        $resultado = $this->servicio->consultar($filtros, (int) $request->user()->id);

        return ApiResponse::exito(
            'Reporte de conciliación de caja consultado.',
            $resultado['datos'],
            $resultado['meta'],
        );
    }

    public function excel(Request $request)
    {
        $filtros = $request->validate([
            'busqueda' => 'nullable|string|max:120',
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date|after_or_equal:desde',
            'fecha' => 'nullable|string|max:30',
            'sede_id' => 'nullable',
            'caja' => 'nullable|string|max:120',
            'apertura' => 'nullable|string|max:10',
            'cierre' => 'nullable|string|max:10',
            'cajero' => 'nullable|string|max:120',
            'saldo_inicial' => 'nullable|string|max:30',
            'efectivo_cobrado' => 'nullable|string|max:30',
            'efectivo_esperado' => 'nullable|string|max:30',
            'efectivo_contado' => 'nullable|string|max:30',
            'diferencia' => 'nullable|string|max:30',
            'transferencia' => 'nullable|string|max:30',
            'tarjeta' => 'nullable|string|max:30',
            'deposito' => 'nullable|string|max:30',
            'otros' => 'nullable|string|max:30',
            'tipo_cierre' => 'nullable',
            'estado_conciliacion' => 'nullable'
        ]);

        return $this->exportacion->excel($filtros, (int) $request->user()->id);
    }

}
