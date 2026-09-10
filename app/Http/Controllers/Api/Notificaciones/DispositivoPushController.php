<?php

namespace App\Http\Controllers\Api\Notificaciones;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DispositivoPushController extends Controller
{
    public function guardar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'plataforma' => ['nullable', Rule::in(['android', 'ios'])],
        ]);

        DB::table('notificaciones.dispositivos_push')->updateOrInsert(
            ['token' => $datos['token']],
            [
                'usuario_id' => $request->user()->id,
                'plataforma' => $datos['plataforma'] ?? null,
                'activo' => true,
                'ultimo_registro_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        return ApiResponse::exito('Dispositivo registrado.');
    }

    public function eliminar(Request $request): JsonResponse
    {
        $datos = $request->validate(['token' => ['required', 'string', 'max:255']]);

        DB::table('notificaciones.dispositivos_push')
            ->where('usuario_id', $request->user()->id)
            ->where('token', $datos['token'])
            ->update(['activo' => false, 'updated_at' => now()]);

        return ApiResponse::exito('Dispositivo desactivado.');
    }
}
