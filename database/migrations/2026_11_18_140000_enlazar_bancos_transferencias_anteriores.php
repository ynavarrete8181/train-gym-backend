<?php

use App\Services\Auditoria\AuditoriaServicio;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Transferencias registradas antes del catálogo de bancos cuyo banco quedó escrito en
 * las observaciones del pago. Se guarda el banco desde el que pagó el cliente:
 *   - 602185233 (Luisa María Alcívar Cedeño): "transferencia Banco Bolivariano ... a Banco Pichincha".
 *   - 95263911 (Silvia Palma): "Transferencia Banco Pichincha".
 * Las demás no tienen el banco en ningún dato y se completan aparte.
 */
return new class extends Migration
{
    private const PAGOS = [
        '602185233' => 'Banco Bolivariano',
        '95263911' => 'Banco Pichincha',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (self::PAGOS as $referencia => $banco) {
                $institucion = DB::table('configuracion.instituciones_financieras')->where('nombre', $banco)->first(['id', 'nombre']);
                $pagos = DB::table('ventas.pagos')->where('referencia', $referencia)->where('metodo_pago', 'TRANSFERENCIA')
                    ->whereNull('institucion_financiera_id')->get();
                if (! $institucion) {
                    continue;
                }
                foreach ($pagos as $pago) {
                    DB::table('ventas.pagos')->where('id', $pago->id)->update([
                        'banco' => $institucion->nombre,
                        'institucion_financiera_id' => $institucion->id,
                        'updated_at' => now(),
                    ]);
                    app(AuditoriaServicio::class)->registrar([
                        'modulo' => 'ventas', 'tabla' => 'ventas.pagos', 'accion' => 'ACTUALIZAR', 'registro_id' => $pago->id,
                        'datos_antes' => $pago, 'datos_despues' => DB::table('ventas.pagos')->where('id', $pago->id)->first(),
                        'descripcion' => "Banco de la transferencia {$referencia} tomado de sus observaciones: {$institucion->nombre}.",
                    ]);
                }
            }

            // Cualquier pago que ya tenga el nombre del banco escrito queda enlazado al catálogo.
            DB::statement('UPDATE ventas.pagos p SET institucion_financiera_id = i.id
                FROM configuracion.instituciones_financieras i
                WHERE p.institucion_financiera_id IS NULL AND p.banco IS NOT NULL AND LOWER(TRIM(p.banco)) = LOWER(i.nombre)');
        });
    }

    public function down(): void
    {
        // Sin reversión automática: los valores anteriores quedan en auditoria.eventos.
    }
};
