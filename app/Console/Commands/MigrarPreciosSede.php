<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrarPreciosSede extends Command
{
    protected $signature = 'gimnasio:migrar-precios';

    public function handle()
    {
        $viejos = DB::connection('pgsql_v2')->table('socios.membresia_precios_sede')->get();
        foreach($viejos as $v) {
            $plan = DB::table('gimnasio.planes')->where('codigo', 'PLAN-' . $v->membresia_id)->first();
            if ($plan) {
                DB::table('gimnasio.plan_precios_sede')->updateOrInsert(
                    ['plan_id' => $plan->id, 'sede_id' => $v->sede_id],
                    ['precio' => $v->precio, 'activo' => $v->activa, 'created_at' => $v->created_at, 'updated_at' => $v->updated_at]
                );
            }
        }
        $this->info("Migrados " . count($viejos) . " precios por sede.");
    }
}
