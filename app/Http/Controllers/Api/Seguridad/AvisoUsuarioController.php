<?php

namespace App\Http\Controllers\Api\Seguridad;

use App\Http\Controllers\Controller;
use App\Services\Seguridad\AvisoUsuarioService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvisoUsuarioController extends Controller
{
    public function __construct(private readonly AvisoUsuarioService $service) {}

    public function index(Request $request): JsonResponse
    {
        $usuarioId = (int) $request->user()->id;
        $avisos = $this->service->listar($usuarioId, 20);

        return ApiResponse::exito('Avisos consultados.', [
            'no_leidos' => collect($avisos)->where('leido', false)->count(),
            'avisos' => $avisos,
        ]);
    }

    public function marcarLeido(Request $request, int $aviso): JsonResponse
    {
        $this->service->marcarLeido((int) $request->user()->id, $aviso);

        return ApiResponse::exito('Aviso marcado como leído.');
    }
}
