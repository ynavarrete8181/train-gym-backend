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
        unset($datos['precios_sede']);

        $datos['created_at'] = now();
        $datos['updated_at'] = now();

        return DB::transaction(function () use ($datos, $preciosSede) {
            $id = DB::table('gimnasio.planes')->insertGetId($datos);

            $this->sincronizarPreciosSede($id, $preciosSede);

            $plan = DB::table('gimnasio.planes')->where('id', $id)->first();
            $plan->precios_sede = DB::table('gimnasio.plan_precios_sede')->where('plan_id', $id)->get();
            $this->auditar('gimnasio', 'CREAR', 'gimnasio.planes', $id, null, $plan);
            return $plan;
        });
    }

    public function actualizar(int $id, array $datos)
    {
        $preciosSede = $datos['precios_sede'] ?? [];
        unset($datos['precios_sede']);

        $datos['updated_at'] = now();

        return DB::transaction(function () use ($id, $datos, $preciosSede) {
            $antes = DB::table('gimnasio.planes')->where('id', $id)->first();
            DB::table('gimnasio.planes')->where('id', $id)->update($datos);

            $this->sincronizarPreciosSede($id, $preciosSede);

            $plan = DB::table('gimnasio.planes')->where('id', $id)->first();
            $plan->precios_sede = DB::table('gimnasio.plan_precios_sede')->where('plan_id', $id)->get();
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
}
