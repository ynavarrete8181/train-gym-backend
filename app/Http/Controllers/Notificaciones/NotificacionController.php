<?php

namespace App\Http\Controllers\Notificaciones;

use App\Http\Controllers\Controller;
use App\Services\Notificaciones\NotificacionService;
use Illuminate\Http\Request;

class NotificacionController extends Controller
{
    public function __construct(private NotificacionService $notificacionService)
    {
    }

    public function index(Request $request)
    {
        $limit = min(max((int) $request->query('limit', 50), 1), 100);
        $user = $request->user();

        return response()->json([
            'data' => $this->notificacionService->listarParaUsuario($user?->id, $user?->persona_id, $limit),
            'no_leidas' => $this->notificacionService->contarNoLeidas($user?->id, $user?->persona_id),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'titulo' => ['required', 'string', 'max:160'],
            'mensaje' => ['required', 'string'],
            'tipo' => ['nullable', 'string', 'max:50'],
            'canal' => ['nullable', 'string', 'max:30'],
            'prioridad' => ['nullable', 'string', 'max:20'],
            'programada_para' => ['nullable', 'date'],
            'data' => ['nullable', 'array'],
            'usuarios' => ['nullable', 'array'],
            'usuarios.*' => ['integer'],
            'personas' => ['nullable', 'array'],
            'personas.*' => ['integer'],
        ]);

        if (empty($data['usuarios']) && empty($data['personas'])) {
            return response()->json(['message' => 'Seleccione al menos un destinatario.'], 422);
        }

        return response()->json([
            'message' => 'Notificacion creada correctamente.',
            'data' => $this->notificacionService->crear($data, $request->user()?->id),
        ], 201);
    }

    public function markAsRead(Request $request, int $id)
    {
        $user = $request->user();
        $updated = $this->notificacionService->marcarLeida($id, $user?->id, $user?->persona_id);

        if (!$updated) {
            return response()->json(['message' => 'No se encontro la notificacion.'], 404);
        }

        return response()->json(['message' => 'Notificacion marcada como leida.']);
    }

    public function markAllAsRead(Request $request)
    {
        $user = $request->user();
        $total = $this->notificacionService->marcarTodasLeidas($user?->id, $user?->persona_id);

        return response()->json([
            'message' => 'Notificaciones marcadas como leidas.',
            'total' => $total,
        ]);
    }

    public function registerDevice(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'plataforma' => ['required', 'string', 'max:30'],
        ]);
        $user = $request->user();

        $this->notificacionService->registrarDispositivo($user?->id, $user?->persona_id, $data['plataforma'], $data['token']);

        return response()->json(['message' => 'Dispositivo registrado.']);
    }
}
