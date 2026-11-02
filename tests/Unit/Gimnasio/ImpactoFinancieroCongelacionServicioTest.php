<?php

namespace Tests\Unit\Gimnasio;

use App\Services\Gimnasio\ImpactoFinancieroCongelacionServicio;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * Se prueba con objetos de lectura; ninguna escritura sobre ventas,
 * pagos, cuotas o Cartera es permitida en la previsualización.
 */
class ImpactoFinancieroCongelacionServicioTest extends TestCase
{
    public function test_noviembre_pagado_se_conserva_y_diciembre_se_proyecta_sin_emision(): void
    {
        $cuotas = collect([
            (object) [
                'id' => 1, 'numero_cuota' => 1, 'estado' => 'EMITIDA',
                'monto' => '60.00', 'venta_id' => 101, 'venta_estado' => 'PAGADA',
                'venta_total' => '60.00', 'fecha_programada_emision' => '2026-11-01',
                'fecha_vencimiento' => '2026-11-04',
            ],
            (object) [
                'id' => 2, 'numero_cuota' => 2, 'estado' => 'PROGRAMADA',
                'monto' => '60.00', 'venta_id' => null, 'venta_estado' => null,
                'venta_total' => null, 'fecha_programada_emision' => '2026-12-01',
                'fecha_vencimiento' => '2026-12-04',
            ],
        ]);

        $consulta = Mockery::mock();
        $consulta->shouldReceive('leftJoin')->once()->andReturnSelf();
        $consulta->shouldReceive('where')->once()->andReturnSelf();
        $consulta->shouldReceive('orderBy')->once()->andReturnSelf();
        $consulta->shouldReceive('get')->once()->andReturn($cuotas);
        $pagos = Mockery::mock();
        $pagos->shouldReceive('where')->twice()->andReturnSelf();
        $pagos->shouldReceive('sum')->once()->with('monto')->andReturn(60);

        DB::shouldReceive('table')->once()->with('membresias.membresia_cuotas as c')->andReturn($consulta);
        DB::shouldReceive('table')->once()->with('ventas.pagos')->andReturn($pagos);
        $otrasVentas = Mockery::mock();
        $otrasVentas->shouldReceive('where')->twice()->andReturnSelf();
        $otrasVentas->shouldReceive('whereNotIn')->once()->andReturnSelf();
        $otrasVentas->shouldReceive('get')->once()->andReturn(collect());
        DB::shouldReceive('table')->once()->with('ventas.ventas')->andReturn($otrasVentas);

        $resultado = (new ImpactoFinancieroCongelacionServicio())->previsualizar(
            8, '2026-11-15', '2026-12-31', 'FACTURACION'
        );

        $this->assertSame(47, $resultado['dias']);
        $this->assertSame(60.0, $resultado['pagos_confirmados']);
        $this->assertSame(0.0, $resultado['deuda_emitida']);
        $this->assertSame(1, $resultado['cuotas_reprogramables']);
        $this->assertFalse($resultado['requiere_revision_cartera']);
        $this->assertSame('PAGO_CONFIRMADO_CONSERVAR', $resultado['cuotas'][0]['accion']);
        $this->assertSame('REPROGRAMACION_PROPUESTA', $resultado['cuotas'][1]['accion']);
        $this->assertSame('2027-01-17', $resultado['cuotas'][1]['emision_propuesta']);
        $this->assertSame('2027-01-20', $resultado['cuotas'][1]['vencimiento_propuesto']);
        $this->assertTrue($resultado['es_simulacion']);
        $this->assertFalse($resultado['modifica_ventas']);
        $this->assertFalse($resultado['modifica_pagos']);
    }

    public function test_una_venta_emitida_con_pago_parcial_conserva_su_deuda(): void
    {
        $cuotas = collect([(object) [
            'id' => 3, 'numero_cuota' => 1, 'estado' => 'EMITIDA',
            'monto' => '60.00', 'venta_id' => 201, 'venta_estado' => 'PENDIENTE',
            'venta_total' => '60.00', 'fecha_programada_emision' => '2026-11-01',
            'fecha_vencimiento' => '2026-11-04',
        ]]);

        $consulta = Mockery::mock();
        $consulta->shouldReceive('leftJoin')->once()->andReturnSelf();
        $consulta->shouldReceive('where')->once()->andReturnSelf();
        $consulta->shouldReceive('orderBy')->once()->andReturnSelf();
        $consulta->shouldReceive('get')->once()->andReturn($cuotas);
        $pagos = Mockery::mock();
        $pagos->shouldReceive('where')->twice()->andReturnSelf();
        $pagos->shouldReceive('sum')->once()->with('monto')->andReturn(20);
        DB::shouldReceive('table')->once()->with('membresias.membresia_cuotas as c')->andReturn($consulta);
        DB::shouldReceive('table')->once()->with('ventas.pagos')->andReturn($pagos);
        $otrasVentas = Mockery::mock();
        $otrasVentas->shouldReceive('where')->twice()->andReturnSelf();
        $otrasVentas->shouldReceive('whereNotIn')->once()->andReturnSelf();
        $otrasVentas->shouldReceive('get')->once()->andReturn(collect());
        DB::shouldReceive('table')->once()->with('ventas.ventas')->andReturn($otrasVentas);

        $resultado = (new ImpactoFinancieroCongelacionServicio())->previsualizar(
            8, '2026-11-15', '2026-11-29', 'FACTURACION'
        );

        $this->assertSame(20.0, $resultado['pagos_confirmados']);
        $this->assertSame(40.0, $resultado['deuda_emitida']);
        $this->assertTrue($resultado['requiere_revision_cartera']);
        $this->assertSame('VENTA_EMITIDA_REVISAR_CARTERA', $resultado['cuotas'][0]['accion']);
        $this->assertSame('2026-11-04', $resultado['cuotas'][0]['vencimiento_propuesto']);
    }
}
