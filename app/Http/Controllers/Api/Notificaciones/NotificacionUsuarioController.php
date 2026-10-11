<?php

namespace App\Http\Controllers\Api\Notificaciones;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Notificaciones\LoteInvitacionAccesoService;
use App\Services\Notificaciones\NotificacionUsuarioService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificacionUsuarioController extends Controller
{
    public function __construct(
        private readonly NotificacionUsuarioService $service,
        private readonly LoteInvitacionAccesoService $loteService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $p = $this->service->listar($request->only(['busqueda', 'estado', 'per_page']));

        return ApiResponse::exito('Notificaciones consultadas.', $p->items(), ['pagina_actual' => $p->currentPage(), 'por_pagina' => $p->perPage(), 'total' => $p->total(), 'ultima_pagina' => $p->lastPage()]);
    }

    public function seleccion(Request $request): JsonResponse
    {
        return ApiResponse::exito(
            'Usuarios filtrados consultados.',
            $this->service->idsFiltrados($request->only(['busqueda', 'estado'])),
        );
    }

    public function enviar(Request $request, User $usuario): JsonResponse
    {
        $datos = $request->validate(['forzar' => ['nullable', 'boolean']]);
        $id = $this->service->solicitar($usuario, $request->user()?->id, (bool) ($datos['forzar'] ?? false));
        $estado = DB::table('notificaciones.notificaciones')->where('id', $id)->value('estado');
        $inmediato = $estado === 'ENVIADA';

        return ApiResponse::exito($inmediato ? 'Correo enviado correctamente.' : 'Envío programado para procesamiento.', ['notificacion_id' => $id, 'estado' => $estado], codigo: $inmediato ? 200 : 202);
    }

    public function enviarVarios(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'usuarios' => ['required', 'array', 'min:1', 'max:500'],
            'usuarios.*' => ['integer', 'distinct'],
            'forzar' => ['nullable', 'boolean'],
        ]);

        $lote = $this->loteService->crear(
            $datos['usuarios'],
            (bool) ($datos['forzar'] ?? false),
            $request->user()?->id,
        );

        return ApiResponse::exito('Las invitaciones fueron enviadas a procesamiento.', $lote, codigo: 202);
    }

    public function estadoLote(Request $request, int $lote): JsonResponse
    {
        $datos = $this->loteService->estado($lote);
        abort_unless(! $request->user()?->id || DB::table('notificaciones.lotes_acceso')->where('id', $lote)->where('solicitado_por', $request->user()->id)->exists(), 403);

        return ApiResponse::exito('Lote consultado.', $datos);
    }

    public function lotesRecientes(Request $request): JsonResponse
    {
        return ApiResponse::exito('Lotes consultados.', $this->loteService->recientes($request->user()?->id));
    }

    public function historial(User $usuario): JsonResponse
    {
        $datos = DB::table('notificaciones.notificaciones as n')->leftJoin('notificaciones.intentos as i', 'i.notificacion_id', '=', 'n.id')->where('n.usuario_id', $usuario->id)->select('n.id', 'n.tipo', 'n.estado', 'n.correo_destino', 'n.encolado_at', 'n.enviado_at', 'i.numero_intento', 'i.estado as estado_intento', 'i.http_status', 'i.mensaje_error', 'i.finalizado_at')->orderByDesc('n.id')->orderByDesc('i.numero_intento')->get();

        return ApiResponse::exito('Historial consultado.', $datos);
    }
}
