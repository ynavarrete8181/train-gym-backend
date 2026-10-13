<?php

namespace App\Http\Controllers\Api\Integraciones;

use App\Http\Controllers\Controller;
use App\Services\Integraciones\GmailOAuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GmailOAuthController extends Controller
{
    public function __construct(private readonly GmailOAuthService $gmail) {}

    public function estado(): JsonResponse
    {
        return ApiResponse::exito('Estado de Gmail consultado.', $this->gmail->estado());
    }

    public function configurar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'client_id' => ['required', 'string', 'max:500'],
            'client_secret' => ['required', 'string', 'max:500'],
            'sender' => ['required', 'email', 'max:254'],
        ]);
        $this->gmail->configurar($datos['client_id'], $datos['client_secret'], $datos['sender'], $request->user()?->id);
        return ApiResponse::exito('Cliente OAuth configurado. Conecta la cuenta de Gmail.');
    }

    public function conectar(Request $request): JsonResponse
    {
        return ApiResponse::exito('Autorización preparada.', [
            'url' => $this->gmail->iniciar((int) $request->user()->id),
        ]);
    }

    public function desconectar(Request $request): JsonResponse
    {
        $this->gmail->desconectar($request->user()?->id);
        return ApiResponse::exito('Cuenta Gmail desconectada.');
    }

    public function callback(Request $request)
    {
        if ($request->filled('error')) {
            return response('Autorización de Google cancelada. Puedes cerrar esta ventana.', 400);
        }
        $datos = $request->validate([
            'state' => ['required', 'string', 'size:64'],
            'code' => ['required', 'string'],
        ]);
        try {
            $this->gmail->completar($datos['state'], $datos['code']);
            return response('Gmail conectado correctamente a Revive. Puedes cerrar esta ventana.')
                ->header('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            Log::warning('No fue posible conectar Gmail OAuth.', ['tipo' => get_class($e)]);
            return response('No se pudo conectar Gmail. Regresa a Integraciones y reinténtalo.', 400)
                ->header('Cache-Control', 'no-store');
        }
    }
}
