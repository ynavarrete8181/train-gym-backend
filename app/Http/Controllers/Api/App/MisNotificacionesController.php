<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Services\Seguridad\AvisoUsuarioService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bandeja personal de la app. Reutiliza los avisos internos del usuario
 * (seguridad.avisos_usuario), los mismos que alimentan la campana web y el
 * canal "Sistema" de las campañas.
 */
class MisNotificacionesController extends Controller
{
    public function __construct(private readonly AvisoUsuarioService $avisos) {}

    public function index(Request $request): JsonResponse
    {
        $avisos = collect($this->avisos->listar((int) $request->user()->id, 50))
            ->map(fn (array $aviso): array => [
                'destinatario_id' => $aviso['id'],
                'id' => $aviso['id'],
                'tipo' => $aviso['tipo'],
                'titulo' => $aviso['titulo'],
                'mensaje' => $aviso['mensaje'],
                'vista' => $aviso['vista'],
                'referencia_tipo' => $aviso['referencia_tipo'],
                'referencia_id' => $aviso['referencia_id'],
                'leida' => $aviso['leido'],
                'estado' => $aviso['leido'] ? 'LEIDA' : 'NO_LEIDA',
                'created_at' => $aviso['created_at'],
            ])
            ->values();

        return response()->json([
            'data' => $avisos,
            'no_leidas' => $avisos->where('leida', false)->count(),
        ]);
    }

    public function leer(Request $request, int $id): JsonResponse
    {
        $this->avisos->marcarLeido((int) $request->user()->id, $id);

        return ApiResponse::exito('Notificación marcada como leída.');
    }
}
