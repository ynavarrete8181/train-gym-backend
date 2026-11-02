<?php

namespace Tests\Unit\Gimnasio;

use App\Services\Gimnasio\PoliticaCicloVidaMembresia;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PoliticaCicloVidaMembresiaTest extends TestCase
{
    private PoliticaCicloVidaMembresia $politica;

    protected function setUp(): void
    {
        parent::setUp();
        $this->politica = new PoliticaCicloVidaMembresia();
    }

    public function test_intervalo_inclusivo_de_quince_dias_y_fin_ajustado(): void
    {
        $resultado = $this->politica->validarIntervalo('2026-11-15', '2026-11-29', '2026-11-02');
        $this->assertSame(15, $resultado['dias']);
        $this->assertSame('2026-12-15', $this->politica->calcularFinAjustado('2026-11-30', 15));
    }

    public function test_congelacion_retroactiva_rechazada(): void
    {
        $this->expectException(ValidationException::class);
        $this->politica->validarIntervalo('2026-10-20', '2026-11-10', '2026-11-02');
    }

    public function test_intervalo_invertido_rechazado(): void
    {
        $this->expectException(ValidationException::class);
        $this->politica->validarIntervalo('2026-11-20', '2026-11-10', '2026-11-02');
    }

    public function test_congelacion_requiere_membresia_activa(): void
    {
        $this->expectException(ValidationException::class);
        $this->politica->validarCongelacion('CANCELADA', 'TIEMPO', false, false);
    }

    public function test_no_se_permite_otra_congelacion_pendiente(): void
    {
        $this->expectException(ValidationException::class);
        $this->politica->validarCongelacion('ACTIVA', 'TIEMPO', true, false);
    }

    public function test_congelacion_de_facturacion_requiere_cuotas_programadas(): void
    {
        $this->expectException(ValidationException::class);
        $this->politica->validarCongelacion('ACTIVA', 'FACTURACION', false, false);
    }

    public function test_reactivacion_requiere_congelacion_vigente(): void
    {
        $this->expectException(ValidationException::class);
        $this->politica->validarReactivacion('ACTIVA', false);
    }

    public function test_cierre_requiere_motivo(): void
    {
        $this->expectException(ValidationException::class);
        $this->politica->validarCierre('ACTIVA', '   ');
    }

    public function test_politica_c_sugiere_facturacion_solo_si_hay_cuotas_pendientes(): void
    {
        $this->assertSame('FACTURACION', $this->politica->tipoSugerido(true, false));
        $this->assertSame('TIEMPO', $this->politica->tipoSugerido(true, true));
        $this->assertSame('TIEMPO', $this->politica->tipoSugerido(false, true));
    }

    public function test_validaciones_positivas_no_producen_efectos_colaterales(): void
    {
        $this->politica->validarCongelacion('ACTIVA', 'FACTURACION', false, true);
        $this->politica->validarReactivacion('CONGELADA', true);
        $this->politica->validarCierre('CONGELADA', 'Retiro voluntario');
        $this->assertTrue(true);
    }
}
