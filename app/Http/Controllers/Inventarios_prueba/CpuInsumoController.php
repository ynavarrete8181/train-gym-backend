<?php

namespace App\Http\Controllers\Inventarios;

use App\Http\Controllers\Inventarios\Producto\ProductosController;

/**
 * Compatibilidad temporal para rutas clínicas heredadas.
 *
 * Todo lo real ya vive bajo el módulo Producto.
 */
class CpuInsumoController extends \App\Http\Controllers\Inventarios\Producto\ProductosCatalogoController
{
    public function getInsumos()
    {
        return app(ProductosController::class)->getProductosAtencionMedicinaGeneral();
    }

    public function getInsumosMedico()
    {
        $response = app(ProductosController::class)->getProductosAtencionMedicinaGeneral();
        $payload = $response->getData(true);

        return response()->json([
            'insumosMedicos' => $payload['insumosMedicos'] ?? [],
            'medicamentos' => $payload['medicamentos'] ?? [],
        ], $response->getStatusCode());
    }
}
