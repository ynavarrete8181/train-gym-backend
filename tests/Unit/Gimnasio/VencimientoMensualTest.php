<?php

namespace Tests\Unit\Gimnasio;

use App\Services\Gimnasio\ContratoCuotasServicio;
use PHPUnit\Framework\TestCase;

class VencimientoMensualTest extends TestCase
{
    public function test_conserva_el_dia_fijo_aunque_los_periodos_duren_28_dias(): void
    {
        $fechas = [];
        for ($i = 0; $i < 5; $i++) {
            $fechas[] = ContratoCuotasServicio::vencimientoMensual('2026-11-05', $i);
        }

        $this->assertSame([
            '2026-11-05', '2026-12-05', '2027-01-05',
            '2027-02-05', '2027-03-05',
        ], $fechas);
    }

    public function test_dia_31_se_ajusta_en_febrero_sin_deriva(): void
    {
        $this->assertSame('2027-02-28', ContratoCuotasServicio::vencimientoMensual('2027-01-31', 1));
        $this->assertSame('2027-03-31', ContratoCuotasServicio::vencimientoMensual('2027-01-31', 2));
    }

    public function test_dia_31_elegido_se_recupera_despues_de_febrero_corto(): void
    {
        $primeraFecha = ContratoCuotasServicio::primeraFechaPagoPorDia('2027-02-01', 31);
        $this->assertSame('2027-02-28', $primeraFecha);
        $this->assertSame('2027-03-31', ContratoCuotasServicio::vencimientoMensual($primeraFecha, 1, 31));
        $this->assertSame('2027-04-30', ContratoCuotasServicio::vencimientoMensual($primeraFecha, 2, 31));
    }

    public function test_febrero_bisiesto_respeta_29(): void
    {
        $this->assertSame('2028-02-29', ContratoCuotasServicio::vencimientoMensual('2028-01-31', 1));
    }
}
