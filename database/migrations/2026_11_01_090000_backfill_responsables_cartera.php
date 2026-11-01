<?php

use App\Services\Seguridad\AlcanceOperativoService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $alcance = app(AlcanceOperativoService::class);

        // Solo cuentas abiertas con responsable vacío; las asignaciones manuales
        // y el historial de cobros permanecen intactos.
        DB::table('cuentas_cobrar.cuentas as cc')
            ->join('ventas.ventas as v', 'v.id', '=', 'cc.venta_id')
            ->leftJoin('ventas.cajas as caja', 'caja.id', '=', 'v.caja_id')
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->whereNull('cc.responsable_id')
            ->where('cc.estado', 'ABIERTA')
            ->whereIn('v.estado', ['PENDIENTE', 'PARCIAL'])
            ->select([
                'cc.id as id',
                'v.responsable_comercial_id',
                'v.usuario_id',
            ])
            ->selectRaw('COALESCE(caja.sede_id, m.sede_id) as sede_id')
            ->orderBy('cc.id')
            ->chunkById(200, function ($cuentas) use ($alcance): void {
                foreach ($cuentas as $cuenta) {
                    $sedeId = (int) ($cuenta->sede_id ?? 0);
                    if (! $sedeId) continue;

                    foreach ([$cuenta->responsable_comercial_id, $cuenta->usuario_id] as $candidato) {
                        $candidato = (int) $candidato;
                        if (! $candidato || ! $alcance->puedeGestionarCartera($candidato)) continue;
                        if (! in_array($sedeId, $alcance->sedesPermitidas($candidato, 'maneja_caja'), true)) continue;

                        DB::table('cuentas_cobrar.cuentas')
                            ->where('id', $cuenta->id)
                            ->whereNull('responsable_id')
                            ->where('estado', 'ABIERTA')
                            ->update([
                                'responsable_id' => $candidato,
                                'updated_at' => now(),
                            ]);
                        break;
                    }
                }
            }, 'cc.id', 'id');
    }

    public function down(): void
    {
        // No revertir atribuciones históricas: el responsable pudo ser modificado
        // manualmente después de esta migración.
    }
};
