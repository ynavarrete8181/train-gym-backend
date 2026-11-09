<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Services\Acceso\AccesoServicio;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Credencial QR temporal del deportista autenticado. Cada consulta emite un
 * código nuevo de un solo uso y reemplaza el anterior; recepción lo valida con
 * el flujo normal de acceso (POST /api/base/acceso/eventos).
 */
class MiAccesoController extends Controller
{
    public function __construct(private readonly AccesoServicio $acceso) {}

    public function __invoke(Request $request): JsonResponse
    {
        $deportistaId = DB::table('clientes.deportistas')
            ->where('usuario_id', $request->user()->id)
            ->value('id');

        if (! $deportistaId) {
            return ApiResponse::error('La cuenta no tiene un deportista vinculado.', [], 404);
        }

        return response()->json([
            'data' => $this->acceso->generarCredencialTemporalApp((int) $deportistaId),
        ]);
    }
}
