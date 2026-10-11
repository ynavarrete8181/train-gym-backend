<?php

namespace App\Http\Controllers\Api\Notificaciones;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ComunicacionInicioAppController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $usuarioId = (int) $request->user()->id;

        $comunicaciones = DB::table('notificaciones.campanias as c')
            ->join('notificaciones.campania_destinatarios as d', 'd.campania_id', '=', 'c.id')
            ->where('d.usuario_id', $usuarioId)
            ->where('c.publicar_inicio_app', true)
            ->where('c.estado', '<>', 'BORRADOR')
            ->select(
                'c.id',
                DB::raw('COALESCE(c.titulo_app, c.asunto) as titulo'),
                DB::raw('COALESCE(c.descripcion_app, c.cuerpo_texto) as descripcion'),
                'c.imagen_inicio_app as imagen_url',
                'c.accion_url_app as accion_url',
                'c.accion_etiqueta_app as accion_etiqueta',
                'c.iniciada_at as publicada_at',
            )
            ->orderByDesc('c.iniciada_at')
            ->orderByDesc('c.id')
            ->limit(6)
            ->get()
            ->map(fn ($item) => [
                'id' => (int) $item->id,
                'titulo' => $item->titulo,
                'descripcion' => trim(strip_tags((string) $item->descripcion)),
                'imagen_url' => $item->imagen_url,
                'accion_url' => $item->accion_url,
                'accion_etiqueta' => $item->accion_etiqueta,
                'publicada_at' => $item->publicada_at,
            ])
            ->values();

        return ApiResponse::exito('Comunicaciones de inicio consultadas.', $comunicaciones);
    }
}
