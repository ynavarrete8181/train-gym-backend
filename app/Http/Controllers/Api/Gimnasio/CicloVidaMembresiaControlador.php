<?php

namespace App\Http\Controllers\Api\Gimnasio;

use App\Http\Controllers\Controller;
use App\Services\Gimnasio\CicloVidaMembresiaServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

final class CicloVidaMembresiaControlador extends Controller
{
    public function __construct(private readonly CicloVidaMembresiaServicio $servicio) {}

    public function mostrar(int $id)
    {
        return ApiResponse::exito('Ciclo de vida consultado.', $this->servicio->resumen($id));
    }

    public function previsualizar(Request $request, int $id)
    {
        $datos = $request->validate([
            'tipo' => 'required|in:TIEMPO,FACTURACION',
            'fecha_inicio' => 'required|date_format:Y-m-d',
            'fecha_fin' => 'required|date_format:Y-m-d|after_or_equal:fecha_inicio',
        ]);
        return ApiResponse::exito('Vista previa sin efectos sobre el contrato.', $this->servicio->previsualizar($id, $datos));
    }

    public function congelar(Request $request, int $id)
    {
        $datos = $request->validate([
            'tipo' => 'required|in:TIEMPO,FACTURACION',
            'fecha_inicio' => 'required|date_format:Y-m-d',
            'fecha_fin' => 'required|date_format:Y-m-d|after_or_equal:fecha_inicio',
            'motivo' => 'required|string|min:8|max:1000',
        ]);
        return ApiResponse::exito('Congelación de tiempo registrada.', $this->servicio->congelar($id, $datos, (int) $request->user()->id));
    }

    public function reactivar(Request $request, int $id)
    {
        $datos = $request->validate(['motivo' => 'required|string|min:8|max:1000']);
        return ApiResponse::exito('Membresía reactivada.', $this->servicio->reactivar($id, $datos['motivo'], (int) $request->user()->id));
    }
}
