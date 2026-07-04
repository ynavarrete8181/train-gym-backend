<?php

namespace App\Http\Controllers\Personas;

use App\Http\Controllers\Controller;
use App\Queries\Personas\ReporteEvolucionQuery;
use Illuminate\Http\Request;

class ReporteEvolucionController extends Controller
{
    public function __construct(private ReporteEvolucionQuery $query)
    {
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'buscar' => ['nullable', 'string', 'max:120'],
            'persona_id' => ['nullable', 'integer'],
        ]);

        return response()->json($this->query->resumen($data));
    }
}
