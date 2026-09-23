<?php

namespace App\Services\Gimnasio;

use App\Services\Concerns\RegistraAuditoria;
use Illuminate\Support\Facades\DB;

class PlanServicio
{
    use RegistraAuditoria;

    public function crear(array $datos)
    {
        $preciosSede = $datos['precios_sede'] ?? [];
        $servicioIds = $datos['servicio_ids'] ?? [];
        unset($datos['precios_sede'], $datos['servicio_ids']);

        $datos['created_at'] = now();
        $datos['updated_at'] = now();

        return DB::transaction(function () use ($datos, $preciosSede, $servicioIds) {
            if (empty($datos['codigo'])) {
                $datos['codigo'] = $this->generarCodigo();
            }

            $id = DB::table('gimnasio.planes')->insertGetId($datos);

            $this->sincronizarPreciosSede($id, $preciosSede);
            $this->sincronizarServicios($id, $servicioIds);

            $plan = DB::table('gimnasio.planes')->where('id', $id)->first();
            $plan->precios_sede = DB::table('gimnasio.plan_precios_sede')->where('plan_id', $id)->get();
            $plan->servicios = $this->serviciosDelPlan($id);
            $this->auditar('gimnasio', 'CREAR', 'gimnasio.planes', $id, null, $plan);
            return $plan;
        });
    }

    public function actualizar(int $id, array $datos)
    {
        $preciosSede = $datos['precios_sede'] ?? [];
        $servicioIds = $datos['servicio_ids'] ?? [];
        unset($datos['precios_sede'], $datos['servicio_ids']);

        $datos['updated_at'] = now();

        return DB::transaction(function () use ($id, $datos, $preciosSede, $servicioIds) {
            $antes = DB::table('gimnasio.planes')->where('id', $id)->first();
            DB::table('gimnasio.planes')->where('id', $id)->update($datos);

            $this->sincronizarPreciosSede($id, $preciosSede);
            $this->sincronizarServicios($id, $servicioIds);

            $plan = DB::table('gimnasio.planes')->where('id', $id)->first();
            $plan->precios_sede = DB::table('gimnasio.plan_precios_sede')->where('plan_id', $id)->get();
            $plan->servicios = $this->serviciosDelPlan($id);
            $this->auditar('gimnasio', 'ACTUALIZAR', 'gimnasio.planes', $id, $antes, $plan);
            return $plan;
        });
    }

    private function sincronizarPreciosSede(int $planId, array $preciosSede)
    {
        DB::table('gimnasio.plan_precios_sede')->where('plan_id', $planId)->delete();

        $insertData = [];
        foreach ($preciosSede as $p) {
            $insertData[] = [
                'plan_id' => $planId,
                'sede_id' => $p['sede_id'],
                'precio' => $p['precio'],
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (!empty($insertData)) {
            DB::table('gimnasio.plan_precios_sede')->insert($insertData);
        }
    }

    private function sincronizarServicios(int $planId, array $servicioIds): void
    {
        DB::table('gimnasio.plan_servicios')->where('plan_id', $planId)->delete();

        $ids = collect($servicioIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $ahora = now();
        DB::table('gimnasio.plan_servicios')->insert(
            $ids->map(fn ($servicioId) => [
                'plan_id' => $planId,
                'servicio_id' => $servicioId,
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ])->all()
        );
    }

    private function serviciosDelPlan(int $planId)
    {
        return DB::table('gimnasio.plan_servicios as ps')
            ->join('gimnasio.servicios as s', 's.id', '=', 'ps.servicio_id')
            ->leftJoin('gimnasio.categorias_servicio as c', 'c.id', '=', 's.categoria_id')
            ->where('ps.plan_id', $planId)
            ->where('ps.activo', true)
            ->orderBy('s.nombre')
            ->get([
                's.id',
                's.nombre',
                's.duracion_minutos',
                'c.nombre as categoria',
            ]);
    }

    private function generarCodigo(): string
    {
        DB::statement('LOCK TABLE gimnasio.planes IN SHARE ROW EXCLUSIVE MODE');

        $siguiente = ((int) DB::table('gimnasio.planes')->max('id')) + 1;
        $codigo = 'PLAN-' . $siguiente;

        while (DB::table('gimnasio.planes')->where('codigo', $codigo)->exists()) {
            $siguiente++;
            $codigo = 'PLAN-' . $siguiente;
        }

        return $codigo;
    }

}
