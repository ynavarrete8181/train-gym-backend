<?php
namespace Tests\Unit\Gimnasio;

use App\Services\Gimnasio\RenovacionContratoProgramadoServicio;
use PHPUnit\Framework\TestCase;

final class RenovacionContratoProgramadoTest extends TestCase
{
    public function test_renovacion_de_enero(): void
    {
        $r = RenovacionContratoProgramadoServicio::calcularProximoPeriodo('2026-12-31', 'MESES', 1);
        self::assertSame('2027-01-01', $r['fecha_inicio']);
        self::assertSame('2027-01-31', $r['fecha_fin']);
    }

    public function test_renovaciones_consecutivas_no_superponen_periodos(): void
    {
        $original = ['fecha_inicio' => '2026-12-01', 'fecha_fin' => '2026-12-31'];
        $primera = RenovacionContratoProgramadoServicio::calcularProximoPeriodo($original['fecha_fin'], 'MESES', 1);
        $segunda = RenovacionContratoProgramadoServicio::calcularProximoPeriodo($primera['fecha_fin'], 'MESES', 1);
        $tercera = RenovacionContratoProgramadoServicio::calcularProximoPeriodo($segunda['fecha_fin'], 'MESES', 1);

        self::assertSame(['fecha_inicio' => '2027-01-01', 'fecha_fin' => '2027-01-31'], $primera);
        self::assertSame(['fecha_inicio' => '2027-02-01', 'fecha_fin' => '2027-02-28'], $segunda);
        self::assertSame(['fecha_inicio' => '2027-03-01', 'fecha_fin' => '2027-03-31'], $tercera);
        self::assertSame('2026-12-31', $original['fecha_fin']);
    }

    public function test_reactivacion_previa_desplaza_la_renovacion(): void
    {
        $r = RenovacionContratoProgramadoServicio::calcularProximoPeriodo('2027-02-13', 'MESES', 1);
        self::assertSame('2027-02-14', $r['fecha_inicio']);
        self::assertSame('2027-03-13', $r['fecha_fin']);
    }

    public function test_febrero_es_mes_corto(): void
    {
        $r = RenovacionContratoProgramadoServicio::calcularProximoPeriodo('2027-01-30', 'MESES', 1);
        self::assertSame('2027-01-31', $r['fecha_inicio']);
        self::assertSame('2027-02-27', $r['fecha_fin']);
    }
}
