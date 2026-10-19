<?php

namespace Tests\Unit;

use App\Services\Gimnasio\DescuentoDeportistaServicio;
use PHPUnit\Framework\TestCase;

class DescuentoDeportistaServicioTest extends TestCase
{
    public function test_aplica_dos_por_ciento(): void
    {
        $this->assertSame(1.20, DescuentoDeportistaServicio::calcularImporte(60, 'PORCENTAJE', 2));
    }

    public function test_redondea_a_dos_decimales(): void
    {
        $this->assertSame(0.67, DescuentoDeportistaServicio::calcularImporte(33.33, 'PORCENTAJE', 2));
    }

    public function test_aplica_descuento_fijo(): void
    {
        $this->assertSame(9.0, DescuentoDeportistaServicio::calcularImporte(60, 'FIJO', 9));
    }

    public function test_descuento_no_supera_el_precio(): void
    {
        $this->assertSame(60.0, DescuentoDeportistaServicio::calcularImporte(60, 'FIJO', 100));
    }

    public function test_descuentos_negativos_no_restan_importes(): void
    {
        $this->assertSame(0.0, DescuentoDeportistaServicio::calcularImporte(60, 'PORCENTAJE', -10));
    }

    public function test_no_admite_tipo_desconocido(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DescuentoDeportistaServicio::calcularImporte(60, 'OTRO', 10);
    }
}
