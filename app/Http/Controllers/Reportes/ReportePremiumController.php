<?php

namespace App\Http\Controllers\Reportes;

use App\Http\Controllers\Controller;
use App\Queries\Reportes\ReportePremiumQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportePremiumController extends Controller
{
    public function __construct(private readonly ReportePremiumQuery $query)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'fecha_desde' => ['nullable', 'date'],
            'fecha_hasta' => ['nullable', 'date', 'after_or_equal:fecha_desde'],
            'sede_id' => ['nullable', 'integer'],
            'buscar' => ['nullable', 'string', 'max:180'],
            'cedula' => ['nullable', 'string', 'max:30'],
            'modulo' => ['nullable', 'string', 'max:80'],
            'accion' => ['nullable', 'string', 'max:120'],
            'nivel' => ['nullable', 'string', 'max:20'],
            'actor_rol_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:3', 'max:25'],
        ]);

        return response()->json($this->query->dashboard($filters));
    }
}
