<?php

namespace App\Services\Personas;

class BodyMetricsService
{
    public static function calculate(?float $pesoKg, ?float $tallaCm, ?float $cinturaCm, ?float $grasaPct, ?float $masaMagraKg = null, ?float $imc = null): array
    {
        $imc = $imc ?: self::calculateImc($pesoKg, $tallaCm);
        $cinturaAltura = self::calculateCinturaAltura($cinturaCm, $tallaCm);
        $masaMagraKg = $masaMagraKg ?: self::calculateMasaMagra($pesoKg, $grasaPct);
        $estado = self::estadoNutricional($imc);

        return [
            'imc' => $imc,
            'cintura_altura' => $cinturaAltura,
            'masa_magra_kg' => $masaMagraKg,
            'estado_nutricional' => $estado,
            'formula_calculo' => [
                'imc' => [
                    'titulo' => 'IMC',
                    'formula' => 'peso kg / (talla m x talla m)',
                    'resultado' => $imc,
                ],
                'cintura_altura' => [
                    'titulo' => 'Cintura / altura',
                    'formula' => 'cintura cm / talla cm',
                    'resultado' => $cinturaAltura,
                ],
                'masa_magra' => [
                    'titulo' => 'Masa magra',
                    'formula' => 'peso kg x (1 - grasa corporal / 100)',
                    'resultado' => $masaMagraKg,
                ],
            ],
        ];
    }

    public static function calculateImc(?float $pesoKg, ?float $tallaCm): ?float
    {
        if (!$pesoKg || !$tallaCm || $tallaCm <= 0) {
            return null;
        }

        $tallaMetros = $tallaCm / 100;
        return round($pesoKg / ($tallaMetros * $tallaMetros), 2);
    }

    public static function calculateCinturaAltura(?float $cinturaCm, ?float $tallaCm): ?float
    {
        if (!$cinturaCm || !$tallaCm || $tallaCm <= 0) {
            return null;
        }

        return round($cinturaCm / $tallaCm, 2);
    }

    public static function calculateMasaMagra(?float $pesoKg, ?float $grasaPct): ?float
    {
        if (!$pesoKg || $grasaPct === null || $grasaPct < 0 || $grasaPct > 100) {
            return null;
        }

        return round($pesoKg * (1 - ($grasaPct / 100)), 2);
    }

    public static function estadoNutricional(?float $imc): array
    {
        if (!$imc) {
            return [
                'codigo' => 'sin_datos',
                'label' => 'Sin datos',
                'tono' => 'dark',
                'icono' => 'calculator-variant-outline',
                'titulo' => 'Completa peso y talla',
                'mensaje' => 'Con esos datos se calcula el IMC y se interpreta el estado nutricional.',
                'accion' => 'Registrar peso y talla para activar el seguimiento.',
            ];
        }

        if ($imc < 18.5) {
            return [
                'codigo' => 'bajo_peso',
                'label' => 'Bajo peso',
                'tono' => 'warning',
                'icono' => 'alert-circle-outline',
                'titulo' => 'Peso por debajo del rango',
                'mensaje' => 'Conviene revisar alimentación, energía diaria y evolución de masa magra.',
                'accion' => 'Dar seguimiento con entrenador o nutricionista si el valor se mantiene.',
            ];
        }

        if ($imc < 25) {
            return [
                'codigo' => 'normal',
                'label' => 'Normal',
                'tono' => 'success',
                'icono' => 'trophy-outline',
                'titulo' => 'Rango saludable',
                'mensaje' => 'Buen punto de partida. La meta es sostener hábitos y revisar composición corporal.',
                'accion' => 'Celebrar el avance y comparar la siguiente ficha.',
            ];
        }

        if ($imc < 30) {
            return [
                'codigo' => 'sobrepeso',
                'label' => 'Sobrepeso',
                'tono' => 'warning',
                'icono' => 'run-fast',
                'titulo' => 'Sobrepeso por IMC',
                'mensaje' => 'El IMC está sobre el rango normal. Se recomienda mirar cintura, grasa corporal y tendencia.',
                'accion' => 'Priorizar constancia, alimentación y control de cintura en próximas fichas.',
            ];
        }

        $label = $imc < 35 ? 'Obesidad I' : ($imc < 40 ? 'Obesidad II' : 'Obesidad III');

        return [
            'codigo' => 'obesidad',
            'label' => $label,
            'tono' => 'danger',
            'icono' => 'heart-pulse',
            'titulo' => 'Requiere seguimiento',
            'mensaje' => 'El IMC está en rango de obesidad. Es importante acompañar el proceso con control profesional.',
            'accion' => 'Registrar avances frecuentes y cuidar intensidad, alimentación y recuperación.',
        ];
    }
}
