<?php

namespace App\Http\Controllers\Notificaciones;

use App\Http\Controllers\Controller;
use App\Services\Notificaciones\NotificacionGlobalService;
use Illuminate\Http\Request;

class CumpleanosConfigController extends Controller
{
    public function __construct(private NotificacionGlobalService $notificacionGlobalService)
    {
    }

    public function show()
    {
        return response()->json([
            'data' => $this->notificacionGlobalService->configuracionCumpleanos(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'activo' => ['required', 'boolean'],
            'hora_envio' => ['required', 'date_format:H:i'],
            'titulo' => ['required', 'string', 'max:160'],
            'mensaje' => ['required', 'string', 'max:600'],
        ]);

        return response()->json([
            'message' => 'Configuracion de cumpleanos actualizada.',
            'data' => $this->notificacionGlobalService->guardarConfiguracionCumpleanos($data, $request->user()?->id),
        ]);
    }

    public function history(Request $request)
    {
        $limit = min(max((int) $request->query('limit', 100), 1), 200);

        return response()->json([
            'data' => $this->notificacionGlobalService->historialCumpleanos($limit),
        ]);
    }

    public function resend(int $destinatarioId)
    {
        $updated = $this->notificacionGlobalService->reenviarCumpleanos($destinatarioId);

        if (!$updated) {
            return response()->json(['message' => 'No se encontro la notificacion de cumpleanos.'], 404);
        }

        return response()->json(['message' => 'Notificacion de cumpleanos reenviada a la cola push.']);
    }
}
