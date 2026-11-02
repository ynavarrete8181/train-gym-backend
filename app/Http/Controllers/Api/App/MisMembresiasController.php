<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Services\Gimnasio\MembresiaServicio;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MisMembresiasController extends Controller
{
    public function __construct(private readonly MembresiaServicio $membresias) {}

    public function __invoke(Request $request): JsonResponse
    {
        $deportista = DB::table('clientes.deportistas')
            ->where('usuario_id', $request->user()->id)
            ->first(['id']);

        if (! $deportista) {
            return ApiResponse::exito('No existe una ficha de deportista vinculada a esta cuenta.', []);
        }

        $ids = DB::table('membresias.membresias')
            ->where('deportista_id', $deportista->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->pluck('id');

        $datos = $ids->map(function ($id): ?array {
            $m = $this->membresias->obtenerMembresiaConRelaciones((int) $id);
            if (! $m) return null;

            return [
                'id' => $m->id,
                'codigo_contrato' => $m->codigo_contrato,
                'plan_nombre' => $m->plan_nombre,
                'modalidad_nombre' => $m->modalidad_nombre,
                'tipo_producto' => $m->tipo_producto,
                'tipo_cobro' => $m->tipo_cobro,
                'cantidad_periodos_contratados' => $m->cantidad_periodos_contratados ?? null,
                'estado' => $m->estado,
                'estado_nombre' => $m->estado_nombre,
                'fecha_inicio' => $m->fecha_inicio,
                'fecha_fin' => $m->fecha_fin,
                'precio_aplicado' => $m->precio_aplicado,
                'sede_nombre' => $m->sede_nombre,
                'sedes_habilitadas' => $m->sedes_habilitadas,
                'asignaciones_entrenador' => $m->asignaciones_entrenador,
                'modalidad_dias_por_semana' => $m->modalidad_dias_por_semana,
                'modalidad_usos_por_semana' => $m->modalidad_usos_por_semana,
                'modalidad_uso_ilimitado' => $m->modalidad_uso_ilimitado,
                'modalidad_tipo_duracion' => $m->modalidad_tipo_duracion,
                'modalidad_duracion' => $m->modalidad_duracion,
                'renovable' => $m->renovable,
                'renovacion_automatica' => $m->renovacion_automatica ?? null,
                'requiere_pago' => $m->requiere_pago,
                'requiere_entrenador' => $m->requiere_entrenador,
                'proxima_fecha_cobro' => $m->proxima_fecha_cobro ?? null,
                'venta_numero' => $m->venta_numero,
                'venta_estado' => $m->venta_estado,
                'venta_total' => $m->venta_total,
                'periodos' => $m->periodos,
                'cuotas' => $m->cuotas,
                'suspensiones' => DB::table('membresias.membresia_suspensiones')
                    ->where('membresia_id', $m->id)
                    ->orderByDesc('id')->limit(10)->get([
                        'id', 'tipo', 'estado', 'fecha_inicio', 'fecha_fin_prevista',
                        'fecha_fin_real', 'dias_reconocidos', 'fecha_fin_original', 'fecha_fin_ajustada',
                    ]),
            ];
        })->filter()->values()->all();

        return ApiResponse::exito('Asignaciones de membresía consultadas.', $datos);
    }
}
