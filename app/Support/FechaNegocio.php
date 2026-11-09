<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/** El sistema guarda y muestra todas las fechas en hora de Ecuador (app.timezone). */
class FechaNegocio
{
    public const ZONA = 'America/Guayaquil';

    public static function ahora(): Carbon
    {
        return now(self::ZONA);
    }

    public static function hoy(): string
    {
        return self::ahora()->toDateString();
    }

    public static function diaSql(string $columna): string
    {
        return "DATE({$columna})";
    }
}
