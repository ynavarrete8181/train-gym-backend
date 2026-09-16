<?php

namespace App\Services\Gimnasio;

use App\Services\Concerns\RegistraAuditoria;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MembresiaServicio
{
    use RegistraAuditoria;

    public function crear(array $datos)
    {
        $plan = DB::table('gimnasio.planes')->where('id', $datos['plan_id'])->first();

        $datos['precio_aplicado'] = $this->resolverPrecio((int) $datos['plan_id'], $datos['sede_id'] ?? null);
        $datos['codigo_contrato'] = 'TMP-' . Str::uuid();
        $datos['fecha_fin'] = $this->calcularFechaFin($datos['fecha_inicio'], $plan->tipo_duracion, (int) $plan->duracion);
        $datos['estado'] = ($plan->requiere_pago ?? true) ? 'PENDIENTE_PAGO' : 'ACTIVA';
        $datos['created_at'] = now();
        $datos['updated_at'] = now();

        $id = DB::table('gimnasio.membresias')->insertGetId($datos);
        $codigo = 'MEMB-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);

        DB::table('gimnasio.membresias')->where('id', $id)->update([
            'codigo_contrato' => $codigo,
            'updated_at' => now(),
        ]);

        $membresia = $this->obtenerMembresiaConRelaciones($id);
        $this->auditar('gimnasio', 'CREAR', 'gimnasio.membresias', $id, null, $membresia);
        return $membresia;
    }

    public function actualizar(int $id, array $datos)
    {
        $antes = DB::table('gimnasio.membresias')->where('id', $id)->first();

        if (array_key_exists('fecha_inicio', $datos)) {
            $plan = DB::table('gimnasio.planes')->where('id', $antes->plan_id)->first();
            $datos['fecha_fin'] = $this->calcularFechaFin($datos['fecha_inicio'], $plan->tipo_duracion, (int) $plan->duracion);
        }

        $datos['updated_at'] = now();
        DB::table('gimnasio.membresias')->where('id', $id)->update($datos);

        $membresia = $this->obtenerMembresiaConRelaciones($id);
        $this->auditar('gimnasio', 'ACTUALIZAR', 'gimnasio.membresias', $id, $antes, $membresia);
        return $membresia;
    }

    public function eliminar(int $id): void
    {
        $antes = $this->obtenerMembresiaConRelaciones($id);
        DB::table('gimnasio.membresias')->where('id', $id)->delete();
        $this->auditar('gimnasio', 'ELIMINAR', 'gimnasio.membresias', $id, $antes, null);
    }

    public function obtenerMembresiaConRelaciones(int $id)
    {
        return DB::table('gimnasio.membresias')
            ->join('gimnasio.deportistas', 'gimnasio.membresias.deportista_id', '=', 'gimnasio.deportistas.id')
            ->join('seguridad.users', 'gimnasio.deportistas.usuario_id', '=', 'seguridad.users.id')
            ->join('gimnasio.planes', 'gimnasio.membresias.plan_id', '=', 'gimnasio.planes.id')
            ->leftJoin('institucional.sedes', 'gimnasio.membresias.sede_id', '=', 'institucional.sedes.id_sede')
            ->select(
                'gimnasio.membresias.*',
                'gimnasio.deportistas.codigo_deportista',
                'seguridad.users.name as deportista_nombre',
                'seguridad.users.email as deportista_email',
                'gimnasio.planes.nombre as plan_nombre',
                'gimnasio.planes.tipo_producto',
                'gimnasio.planes.tipo_cobro',
                'gimnasio.planes.generar_venta',
                'gimnasio.planes.requiere_pago',
                'gimnasio.planes.renovable',
                'institucional.sedes.nombre as sede_nombre'
            )
            ->where('gimnasio.membresias.id', $id)
            ->first();
    }

    private function calcularFechaFin(string $fechaInicio, string $tipoDuracion, int $duracion): string
    {
        $inicio = Carbon::parse($fechaInicio)->startOfDay();

        return match ($tipoDuracion) {
            'DIAS' => $inicio->copy()->addDays(max($duracion - 1, 0))->toDateString(),
            'MESES' => $inicio->copy()->addMonthsNoOverflow($duracion)->subDay()->toDateString(),
            'ANIOS' => $inicio->copy()->addYears($duracion)->subDay()->toDateString(),
            default => $inicio->toDateString(),
        };
    }

    private function resolverPrecio(int $planId, mixed $sedeId): float
    {
        if ($sedeId) {
            $precioSede = DB::table('gimnasio.plan_precios_sede')
                ->where('plan_id', $planId)
                ->where('sede_id', $sedeId)
                ->where('activo', true)
                ->value('precio');

            if ($precioSede !== null) {
                return (float) $precioSede;
            }
        }

        return (float) DB::table('gimnasio.planes')->where('id', $planId)->value('precio_base');
    }
}
