<?php

namespace Tests\Unit\Gimnasio;

use App\Services\CuentasCobrar\CarteraServicio;
use App\Services\Gimnasio\CobroProgramadoMembresiaServicio;
use App\Services\Gimnasio\DescuentoDeportistaServicio;
use App\Services\Gimnasio\EmisionCuotasServicio;
use App\Services\Gimnasio\MembresiaServicio;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class EmisionCongelacionConcurrenciaTest extends TestCase
{
    public function test_no_cobra_fecha_que_otro_proceso_ya_avanzo(): void
    {
        $seleccion = Mockery::mock();
        $seleccion->shouldReceive('whereNull')->once()->andReturnSelf();
        $seleccion->shouldReceive('where')->once()->andReturnSelf();
        $seleccion->shouldReceive('whereNotNull')->twice()->andReturnSelf();
        $seleccion->shouldReceive('whereDate')->once()->andReturnSelf();
        $seleccion->shouldReceive('whereNotIn')->once()->andReturnSelf();
        $seleccion->shouldReceive('orderBy')->once()->andReturnSelf();
        $seleccion->shouldReceive('pluck')->once()->with('id')->andReturn(collect([42]));

        $bloqueada = Mockery::mock();
        $bloqueada->shouldReceive('where')->once()->with('id', 42)->andReturnSelf();
        $bloqueada->shouldReceive('lockForUpdate')->once()->andReturnSelf();
        $bloqueada->shouldReceive('first')->once()->andReturn((object) [
            'id' => 42,
            'cantidad_periodos_contratados' => null,
            'generar_venta_automatica' => true,
            'dia_pago' => 2,
            // Otra ejecución adelantó el calendario después de seleccionar el ID.
            'proxima_fecha_cobro' => '2026-12-02',
            'estado' => 'ACTIVA',
        ]);

        DB::shouldReceive('table')->twice()->with('membresias.membresias')
            ->andReturn($seleccion, $bloqueada);
        DB::shouldReceive('transaction')->once()
            ->andReturnUsing(fn ($callback) => $callback());

        $emision = Mockery::mock(EmisionCuotasServicio::class);
        $emision->shouldReceive('procesar')->once()->with('2026-11-02')
            ->andReturn(['cuotas_procesadas' => 0, 'cuotas_emitidas' => 0, 'cuotas_omitidas' => 0]);

        $servicio = new CobroProgramadoMembresiaServicio(
            Mockery::mock(MembresiaServicio::class),
            Mockery::mock(CarteraServicio::class),
            Mockery::mock(DescuentoDeportistaServicio::class),
            $emision
        );

        $resultado = $servicio->procesar('2026-11-02');
        $this->assertSame(0, $resultado['ventas_creadas']);
        $this->assertSame(1, $resultado['omitidas']);
    }

    public function test_suspension_financiera_bloquea_cuota_aun_con_membresia_activa(): void
    {
        $seleccion = Mockery::mock();
        $seleccion->shouldReceive('where')->once()->with('estado', 'PROGRAMADA')->andReturnSelf();
        $seleccion->shouldReceive('where')->once()->with('fecha_programada_emision', '<=', '2026-11-02')->andReturnSelf();
        $seleccion->shouldReceive('orderBy')->once()->with('id')->andReturnSelf();
        $seleccion->shouldReceive('pluck')->once()->with('id')->andReturn(collect([10]));

        $referencia = Mockery::mock();
        $referencia->shouldReceive('where')->once()->with('id', 10)->andReturnSelf();
        $referencia->shouldReceive('first')->once()->with(['membresia_id'])
            ->andReturn((object) ['membresia_id' => 42]);

        $membresia = Mockery::mock();
        $membresia->shouldReceive('where')->once()->with('id', 42)->andReturnSelf();
        $membresia->shouldReceive('lockForUpdate')->once()->andReturnSelf();
        $membresia->shouldReceive('first')->once()->andReturn((object) [
            'id' => 42, 'estado' => 'ACTIVA',
        ]);

        $cuota = Mockery::mock();
        $cuota->shouldReceive('where')->once()->with('id', 10)->andReturnSelf();
        $cuota->shouldReceive('lockForUpdate')->once()->andReturnSelf();
        $cuota->shouldReceive('first')->once()->andReturn((object) [
            'id' => 10,
            'membresia_id' => 42,
            'estado' => 'PROGRAMADA',
            'venta_id' => null,
            'fecha_programada_emision' => '2026-11-02',
        ]);

        $suspension = Mockery::mock();
        $suspension->shouldReceive('where')->times(3)->andReturnSelf();
        $suspension->shouldReceive('exists')->once()->andReturn(true);

        DB::shouldReceive('table')->with('membresias.membresia_cuotas')
            ->times(3)->andReturn($seleccion, $referencia, $cuota);
        DB::shouldReceive('table')->once()->with('membresias.membresias')->andReturn($membresia);
        DB::shouldReceive('table')->once()->with('membresias.membresia_suspensiones')->andReturn($suspension);
        DB::shouldReceive('transaction')->once()
            ->andReturnUsing(fn ($callback) => $callback());

        $resultado = (new EmisionCuotasServicio(Mockery::mock(CarteraServicio::class)))
            ->procesar('2026-11-02');

        $this->assertSame(0, $resultado['cuotas_emitidas']);
        $this->assertSame(1, $resultado['cuotas_omitidas']);
    }
}
