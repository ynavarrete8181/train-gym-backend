<?php

namespace App\Http\Controllers\Personas;

use App\Http\Controllers\Controller;
use App\Queries\Personas\MembresiaQuery;

class AppPlanesController extends Controller
{
    public function __construct(private MembresiaQuery $membresiaQuery)
    {
    }

    public function getPlanes()
    {
        $sedeId = request()->query('sede_id');
        $planes = collect($this->membresiaQuery->listarCatalogo($sedeId ? (int) $sedeId : null))
            ->filter(fn ($plan) => (bool) ($plan['activa'] ?? false))
            ->values()
            ->map(fn ($plan) => [
                'id' => $plan['id'],
                'nombre' => $plan['nombre'],
                'descripcion' => $plan['descripcion'],
                'duracion_dias' => $plan['duracion_dias'],
                'precio' => $plan['precio'],
                'precio_base' => $plan['precio_base'],
                'precio_sede' => $plan['precio_sede'],
                'facturacion_automatica' => $plan['facturacion_automatica'],
            ]);

        return response()->json([
            'data' => $planes,
        ]);
    }
}
