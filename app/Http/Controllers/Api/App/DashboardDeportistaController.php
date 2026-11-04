<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardDeportistaController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $usuario = $request->user();

        // Nunca aceptar deportista_id de la app: el contexto es el usuario autenticado.
        $deportista = DB::table('clientes.deportistas')
            ->where('usuario_id', $usuario->id)
            ->first(['id']);

        $membresia = null;
        if ($deportista) {
            $registro = DB::table('membresias.membresias as m')
                ->join('membresias.planes as p', 'p.id', '=', 'm.plan_id')
                ->where('m.deportista_id', $deportista->id)
                ->orderByRaw("CASE WHEN m.estado = 'ACTIVA' THEN 0 WHEN m.estado = 'PENDIENTE_PAGO' THEN 1 ELSE 2 END")
                ->orderByRaw("CASE WHEN p.tipo_producto = 'MEMBRESIA' THEN 0 ELSE 1 END")
                ->orderByDesc('m.fecha_inicio')
                ->first(['m.id', 'm.estado', 'm.fecha_inicio', 'm.fecha_fin', 'm.sede_id', 'p.nombre as plan_nombre']);

            if ($registro) {
                $renovacionImpaga = DB::table('membresias.membresia_periodos as pr')
                    ->leftJoin('ventas.ventas as v', 'v.id', '=', 'pr.venta_id')
                    ->where('pr.membresia_id', $registro->id)
                    ->where('pr.renovacion_manual', true)
                    ->where('pr.fecha_inicio', '<=', now('America/Guayaquil')->toDateString())
                    ->where(function ($q): void {
                        $q->whereNull('pr.venta_id')->orWhere('v.estado', '!=', 'PAGADA');
                    })->exists();
                $membresia = [
                    'id' => $registro->id,
                    'nombre' => $registro->plan_nombre,
                    'estado' => $registro->estado,
                    'estado_consulta' => $renovacionImpaga && $registro->estado === 'ACTIVA'
                        ? 'PENDIENTE_PAGO' : $registro->estado,
                    'fecha_inicio' => $registro->fecha_inicio,
                    'fecha_fin' => $registro->fecha_fin,
                    'sede_id' => $registro->sede_id,
                ];
            }
        }

        // Las reservas y rutinas se incorporarán al consolidado cuando sus
        // consultas personales estén disponibles; nunca devolver datos ajenos.
        return ApiResponse::exito('Resumen personal consultado.', [
            'usuario' => ['id' => $usuario->id, 'name' => $usuario->name],
            'sede_id' => $membresia['sede_id'] ?? null,
            'membresia' => $membresia,
            'plan' => null,
            'proxima_reserva' => null,
        ]);
    }
}
