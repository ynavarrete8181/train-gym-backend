<?php

use App\Services\Auditoria\AuditoriaServicio;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 3 de validaciones (decisión de la administración, 21/11/2026):
 *  - Se enciende la renovación automática de 6 membresías activas que nunca la tuvieron
 *    (Rocío Roldán, MEMB-000009, queda como está: caso especial por definir).
 *  - 14 descuentos que terminaban con el período actual pasan a "hasta que se desactive",
 *    para que sigan en las renovaciones. Solo se cambia la fecha de fin; valor, sede e inicio
 *    se mantienen, y nada de lo ya cobrado cambia.
 * Cada cambio queda en el historial del contrato y en auditoría. Si un registro ya no está
 * como el 21/11, se deja sin tocar; una segunda ejecución no cambia nada.
 */
return new class extends Migration
{
    private const RENOVAR = ['MEMB-000007', 'MEMB-000008', 'MEMB-000010', 'MEMB-000016', 'MEMB-000019', 'MEMB-000031'];

    /** contrato => fecha de fin actual del descuento que se extiende */
    private const DESCUENTOS = [
        'MEMB-000032' => '2026-12-08', // Carlos Montesdeoca 25 %
        'MEMB-000020' => '2026-12-08', // Carlitos Montesdeoca $10
        'MEMB-000021' => '2026-12-08', // Ema Montesdeoca $10
        'MEMB-000028' => '2026-12-19', // Sara Alava López 25 %
        'MEMB-000027' => '2026-12-19', // Gabriel Alava López 25 %
        'MEMB-000024' => '2026-12-07', // Javier Barcia 25 %
        'MEMB-000025' => '2026-12-11', // Sophia Barcia 25 %
        'MEMB-000023' => '2026-12-05', // Raymon Palma Sacoto 50 %
        'MEMB-000026' => '2026-12-14', // Mirian Zipa 50 %
        'MEMB-000037' => '2026-12-19', // Dennis Palacios $30
        'MEMB-000035' => '2026-12-12', // Viviana Barreto $20
        'MEMB-000002' => '2026-11-30', // Silvia Palma 75 %
        'MEMB-000018' => '2026-12-17', // Mauro Briones $20
        'MEMB-000033' => '2026-12-17', // Paula Palma $65 (Home)
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $auditoria = app(AuditoriaServicio::class);

            foreach (self::RENOVAR as $codigo) {
                $m = DB::table('membresias.membresias as m')->join('membresias.planes as p', 'p.id', '=', 'm.plan_id')
                    ->where('m.codigo_contrato', $codigo)->first(['m.*', 'p.renovable', 'p.tipo_producto']);
                if (! $m || $m->renovacion_automatica || ! $m->renovable || $m->tipo_producto === 'PASE_DIARIO'
                    || ! in_array($m->estado, ['ACTIVA', 'PENDIENTE_PAGO'], true)) {
                    continue;
                }
                DB::table('membresias.membresias')->where('id', $m->id)->update(['renovacion_automatica' => true, 'updated_at' => now()]);
                $motivo = 'Configuración del contrato: renovación automática desactivada → activada.';
                $this->movimiento($m->id, 'CONFIGURAR_CONTRATO', $motivo, [
                    'dias_gracia_anterior' => (int) $m->dias_gracia, 'dias_gracia_nuevo' => (int) $m->dias_gracia,
                    'renovacion_automatica_anterior' => false, 'renovacion_automatica_nueva' => true,
                ]);
                $auditoria->registrar([
                    'modulo' => 'gimnasio', 'tabla' => 'membresias.membresias', 'accion' => 'ACTUALIZAR', 'registro_id' => $m->id,
                    'datos_antes' => ['renovacion_automatica' => false], 'datos_despues' => ['renovacion_automatica' => true],
                    'descripcion' => mb_substr("{$codigo}: {$motivo}", 0, 255),
                ]);
            }

            foreach (self::DESCUENTOS as $codigo => $finActual) {
                $m = DB::table('membresias.membresias')->where('codigo_contrato', $codigo)->first();
                if (! $m || ! $m->renovacion_automatica || ! in_array($m->estado, ['ACTIVA', 'PENDIENTE_PAGO'], true)) {
                    continue;
                }
                $d = DB::table('membresias.descuentos_deportistas')
                    ->where('deportista_id', $m->deportista_id)->where('activo', true)
                    ->whereDate('fecha_fin', $finActual)
                    ->where(fn ($q) => $q->whereNull('plan_id')->orWhere('plan_id', $m->plan_id))
                    ->orderByDesc('id')->first();
                if (! $d) {
                    continue;
                }
                DB::table('membresias.descuentos_deportistas')->where('id', $d->id)->update(['fecha_fin' => null, 'updated_at' => now()]);
                $valor = $d->tipo === 'PORCENTAJE' ? ((float) $d->valor) . ' %' : '$' . number_format((float) $d->valor, 2);
                $motivo = "Cambio del descuento: {$valor} sigue en las renovaciones hasta que se desactive (antes hasta el "
                    . implode('/', array_reverse(explode('-', $finActual))) . '), autorizado por administración.';
                $this->movimiento($m->id, 'DESCUENTO_EDITADO', $motivo, [
                    'tipo' => $d->tipo, 'valor' => (float) $d->valor,
                    'fecha_inicio' => substr((string) $d->fecha_inicio, 0, 10), 'fecha_fin' => null,
                    'anterior' => ['tipo' => $d->tipo, 'valor' => (float) $d->valor, 'fecha_inicio' => substr((string) $d->fecha_inicio, 0, 10), 'fecha_fin' => $finActual],
                    'extension' => true,
                ]);
                $auditoria->registrar([
                    'modulo' => 'gimnasio', 'tabla' => 'membresias.descuentos_deportistas', 'accion' => 'ACTUALIZAR_DESCUENTO', 'registro_id' => $d->id,
                    'datos_antes' => $d, 'datos_despues' => DB::table('membresias.descuentos_deportistas')->where('id', $d->id)->first(),
                    'descripcion' => mb_substr("{$codigo}: {$motivo}", 0, 255),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Sin reversión automática: los valores anteriores quedan en auditoria.eventos y en el historial del contrato.
    }

    private function movimiento(int $membresiaId, string $tipo, string $motivo, array $detalle): void
    {
        DB::table('membresias.membresia_movimientos')->insert([
            'membresia_id' => $membresiaId, 'tipo' => $tipo, 'estado' => 'CONFIRMADO',
            'fecha_efectiva' => now('America/Guayaquil')->toDateString(), 'motivo' => $motivo,
            'usuario_id' => null, 'autorizado_por' => null,
            'detalle' => json_encode($detalle, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
};
