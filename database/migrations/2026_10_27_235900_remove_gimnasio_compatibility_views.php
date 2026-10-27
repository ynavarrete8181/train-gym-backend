<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use RuntimeException;

return new class extends Migration
{
    /**
     * Lista cerrada de las vistas heredadas; nunca borrar objetos desconocidos.
     * Esta migración solo debe ejecutarse tras validar los consumidores externos.
     */
    private const VISTAS = [
        'asignaciones_entrenador_cliente' => 'entrenamiento',
        'asignaciones_horario_entrenador' => 'agenda',
        'categorias_servicio' => 'servicios',
        'clases' => 'agenda',
        'deportistas' => 'clientes',
        'disciplinas' => 'servicios',
        'entrenador_horario_franjas' => 'agenda',
        'entrenador_horario_recesos' => 'agenda',
        'entrenador_horarios' => 'agenda',
        'entrenador_servicios' => 'entrenamiento',
        'entrenadores' => 'entrenamiento',
        'excepciones_horario' => 'agenda',
        'horario_bloques' => 'agenda',
        'horario_entrenadores' => 'agenda',
        'horarios_servicio' => 'agenda',
        'jornada_detalles' => 'agenda',
        'jornadas' => 'agenda',
        'membresia_periodos' => 'membresias',
        'membresia_sedes' => 'membresias',
        'membresias' => 'membresias',
        'plan_precios_sede' => 'membresias',
        'plan_servicios' => 'membresias',
        'planes' => 'membresias',
        'recesos' => 'agenda',
        'reservas_dia' => 'agenda',
        'servicio_precios_sede' => 'servicios',
        'servicios' => 'servicios',
    ];

    public function up(): void
    {
        $actuales = DB::select("
            SELECT c.relname, c.relkind
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'gimnasio'
              AND c.relkind IN ('r', 'p', 'v', 'm', 'f', 'S')
            ORDER BY c.relname
        ");

        if (! $actuales) {
            // Sin esquema o sin relaciones: DROP SCHEMA RESTRICT valida que esté vacío.
            DB::statement('DROP SCHEMA IF EXISTS gimnasio RESTRICT');
            return;
        }

        foreach ($actuales as $objeto) {
            if ($objeto->relkind !== 'v' || ! array_key_exists($objeto->relname, self::VISTAS)) {
                throw new RuntimeException(
                    "Limpieza abortada: gimnasio.{$objeto->relname} no es una vista heredada autorizada."
                );
            }
        }

        $encontradas = array_map(static fn ($objeto) => $objeto->relname, $actuales);
        $faltantes = array_diff(array_keys(self::VISTAS), $encontradas);
        if ($faltantes !== []) {
            throw new RuntimeException(
                'Limpieza abortada: inventario de vistas inesperado; faltan: '.implode(', ', $faltantes)
            );
        }

        // PostgreSQL bloquea DROP VIEW RESTRICT ante dependencias SQL y
        // revierte toda la migración si alguna comprobación falla.
        foreach (array_keys(self::VISTAS) as $vista) {
            DB::statement('DROP VIEW gimnasio."'.$vista.'" RESTRICT');
        }

        // No elimina funciones, tablas ni otros objetos: falla si queda algo.
        DB::statement('DROP SCHEMA gimnasio RESTRICT');
    }

    public function down(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS gimnasio');

        foreach (self::VISTAS as $vista => $origen) {
            DB::statement('CREATE VIEW gimnasio."'.$vista.'" AS SELECT * FROM '.$origen.'."'.$vista.'"');
        }
    }
};
