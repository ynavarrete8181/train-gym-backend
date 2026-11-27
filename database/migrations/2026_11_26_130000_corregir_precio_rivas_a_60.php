<?php

use App\Services\CuentasCobrar\CarteraServicio;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Leonela y Pamela Rivas (MEMB-000005/06): el contrato, el período y la cuota de noviembre son de
 * $60, pero sus ventas se registraron con la tarifa general de $80. La administración confirmó que
 * el precio es $60. Se corrige el total de cada venta (detalle y comprobante) a $60; cada una pagó
 * $30 en efectivo el 13/11, así que quedan $30 por cobrar. Solo actúa si todo sigue como se revisó.
 */
return new class extends Migration
{
    /** venta_id => [membresia_id, contrato, nombre] */
    private const VENTAS = [8 => [5, 'MEMB-000005', 'Leonela Rivas'], 9 => [6, 'MEMB-000006', 'Pamela Rivas']];
    private const MOTIVO = 'Precio corregido de $80 a $60: el contrato es de $60 (confirmado por la administración).';

    public function up(): void
    {
        $usuario = (int) (DB::table('seguridad.users')->where('name', 'Root')->value('id') ?? 1);

        foreach (self::VENTAS as $ventaId => [$membresiaId, $contrato, $nombre]) {
            $venta = DB::table('ventas.ventas')->where('id', $ventaId)->first();
            $m = DB::table('membresias.membresias')->where('id', $membresiaId)->first();
            $persona = DB::table('clientes.deportistas as d')->join('personas.personas as p', 'p.id', '=', 'd.persona_id')
                ->where('d.id', $venta->cliente_id ?? 0)->value('p.nombre_completo');
            $detalles = DB::table('ventas.venta_detalles')->where('venta_id', $ventaId)->get();
            $pagado = (float) DB::table('ventas.pagos')->where('venta_id', $ventaId)->where('estado', 'CONFIRMADO')->sum('monto');
            $cuota = DB::table('membresias.membresia_cuotas')->where('venta_id', $ventaId)->first();

            $ok = $venta && $m && $m->codigo_contrato === $contrato && (int) $venta->membresia_id === $membresiaId
                && $persona === $nombre && (float) $venta->total === 80.0 && (float) $venta->descuento === 0.0
                && in_array($venta->estado, ['PENDIENTE', 'PARCIAL'], true)
                && (float) $m->precio_aplicado === 60.0 && $cuota && (float) $cuota->monto === 60.0
                && $detalles->count() === 1 && (float) $detalles[0]->total_linea === 80.0
                && $pagado < 60.0;
            if (! $ok) {
                Log::warning("Precio Rivas: venta {$ventaId} ({$nombre}) ya no coincide con lo revisado; no se cambia.");
                continue;
            }

            $antes = [
                'venta' => $venta,
                'detalles' => $detalles,
                'comprobantes' => DB::table('ventas.comprobantes')->where('venta_id', $ventaId)->get(),
                'cuenta' => DB::table('cuentas_cobrar.cuentas')->where('venta_id', $ventaId)->first(),
            ];

            DB::transaction(function () use ($ventaId, $venta, $detalles, $membresiaId, $contrato, $nombre, $usuario, $antes) {
                DB::table('ventas.venta_detalles')->where('id', $detalles[0]->id)
                    ->update(['precio_unitario' => 60.00, 'total_linea' => 60.00, 'updated_at' => now()]);
                DB::table('ventas.ventas')->where('id', $ventaId)->update([
                    'subtotal' => 60.00,
                    'total' => 60.00,
                    'observaciones' => trim(($venta->observaciones ? $venta->observaciones.' ' : '').self::MOTIVO),
                    'updated_at' => now(),
                ]);
                DB::table('ventas.comprobantes')->where('venta_id', $ventaId)
                    ->update(['subtotal' => 60.00, 'total' => 60.00, 'updated_at' => now()]);
                app(CarteraServicio::class)->sincronizarVenta($ventaId);

                DB::table('membresias.membresia_movimientos')->insert([
                    'membresia_id' => $membresiaId,
                    'tipo' => 'CORRECCION_DATOS',
                    'estado' => 'CONFIRMADO',
                    'fecha_efectiva' => '2026-11-01',
                    'motivo' => self::MOTIVO,
                    'usuario_id' => $usuario,
                    'autorizado_por' => $usuario,
                    'detalle' => json_encode(['venta_id' => $ventaId, 'numero' => $venta->numero, 'total_anterior' => 80.00, 'total_nuevo' => 60.00], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('auditoria.eventos')->insert([
                    'usuario_id' => $usuario,
                    'usuario_nombre' => 'Sistema (migración)',
                    'modulo' => 'ventas',
                    'tabla' => 'ventas.ventas',
                    'registro_id' => (string) $ventaId,
                    'accion' => 'CORREGIR_PRECIO',
                    'descripcion' => "{$contrato} ({$nombre}): venta {$venta->numero} de \$80 a \$60. ".self::MOTIVO,
                    'datos_antes' => json_encode($antes, JSON_UNESCAPED_UNICODE),
                    'datos_despues' => json_encode(DB::table('ventas.ventas')->where('id', $ventaId)->first(), JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                ]);
            });
            Log::info("Precio Rivas: venta {$ventaId} ({$nombre}) corregida a \$60.");
        }
    }

    public function down(): void
    {
        // Corrección de datos con historial; la copia previa está en auditoria.eventos.
    }
};
