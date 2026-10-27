<?php

namespace App\Services\Gimnasio;

use Illuminate\Support\Facades\DB;

class CodigoClienteServicio
{
    // Sin O, I, 0 o 1, para facilitar lectura y dictado.
    public const ALFABETO = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    public const LONGITUD = 5;

    public function generar(): string
    {
        $maximo = strlen(self::ALFABETO) - 1;

        for ($intento = 0; $intento < 100; $intento++) {
            $codigo = '';
            for ($i = 0; $i < self::LONGITUD; $i++) {
                $codigo .= self::ALFABETO[random_int(0, $maximo)];
            }

            if (! DB::table('clientes.deportistas')->where('codigo_deportista', $codigo)->exists()
                && ! DB::table('clientes.deportista_codigos_historicos')
                    ->where('codigo_anterior', $codigo)->exists()) {
                return $codigo;
            }
        }

        throw new \RuntimeException('No fue posible asignar un código único al cliente.');
    }
}
