<?php

namespace App\Http\Controllers\Acceso;

use App\Http\Controllers\Controller;
use App\Services\Acceso\AccesoService;
use Illuminate\Http\Request;

class AccesoController extends Controller
{
    public function __construct(private AccesoService $service)
    {
    }

    public function credencialApp(Request $request)
    {
        return response()->json([
            'data' => $this->service->credencialApp($request),
        ]);
    }

    public function validarQr(Request $request)
    {
        $data = $request->validate([
            'codigo_qr' => ['required', 'string'],
            'sede_id' => ['nullable', 'integer'],
            'dispositivo_id' => ['nullable', 'integer'],
            'registrar_asistencia' => ['nullable', 'boolean'],
            'origen' => ['nullable', 'string', 'max:30'],
        ]);

        $resultado = $this->service->validarQr($request, $data);

        return response()->json([
            'data' => $resultado,
        ], $resultado['permitido'] ? 200 : 422);
    }
}
