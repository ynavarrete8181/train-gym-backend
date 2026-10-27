<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class EliminarVistasGimnasio extends Command
{
    protected $signature = 'revive:eliminar-vistas-gimnasio {--confirmar : Confirmar la eliminación de la capa de compatibilidad}';
    protected $description = 'Eliminar de forma controlada las 27 vistas heredadas, sin CASCADE';

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


    public function handle(): int
    {
        if (! $this->option('confirmar')) {
            $this->error('Ejecute únicamente después de validar respaldos y dependencias, usando --confirmar.');
            return self::FAILURE;
        }
        if (getenv('REVIVE_ALLOW_DROP_GIMNASIO_VIEWS') !== 'true') {
            $this->error('Falta la autorización REVIVE_ALLOW_DROP_GIMNASIO_VIEWS=true.');
            return self::FAILURE;
        }
        if (DB::getDriverName() !== 'pgsql') {
            $this->error('Este comando requiere PostgreSQL.');
            return self::FAILURE;
        }

        $base = DB::connection()->getDatabaseName();
        $host = config('database.connections.pgsql.host');
        if ($base !== 'db_gimnasio_limpieza_prueba' || ! in_array($host, ['127.0.0.1', 'localhost'], true)) {
            $this->error('Por seguridad este comando solo funciona en db_gimnasio_limpieza_prueba alojada localmente.');
            return self::FAILURE;
        }

        DB::transaction(function (): void {
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
        });

        $this->info('Vistas heredadas y esquema gimnasio eliminados.');
        return self::SUCCESS;
    }
}
