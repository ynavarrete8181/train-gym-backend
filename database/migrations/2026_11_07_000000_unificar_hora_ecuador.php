<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La aplicación pasa de UTC a hora de Ecuador. Convierte a esa hora los campos que PHP
 * guardó en UTC. No toca:
 * - filas de datos iniciales, cuyo created_at es una fecha sin hora (00:00:00);
 * - valores a las 00:00:00, que son fechas sin hora;
 * - fecha_venta y fecha_pago ya en hora de Ecuador por el valor por defecto de la base;
 * - cierres automáticos de turno, que ya guardan el fin de la jornada de Ecuador.
 *
 * No tiene reversión automática: se restaura desde el respaldo. Debe ejecutarse a la vez
 * que se despliega el código con app.timezone = America/Guayaquil y sin operación en caja.
 */
return new class extends Migration
{
    private const COLUMNAS_SIN_SUFIJO = [
        'created_at', 'updated_at', 'fecha_hora', 'fecha_cambio', 'fecha_movimiento',
        'expira_en', 'ultimo_uso_en', 'fecha_apertura', 'fecha_cierre',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $columnas = collect(DB::select(<<<'SQL'
            SELECT c.table_schema, c.table_name, c.column_name
            FROM information_schema.columns c
            JOIN information_schema.tables t USING (table_schema, table_name)
            WHERE t.table_type = 'BASE TABLE'
              AND c.data_type = 'timestamp without time zone'
              AND c.table_schema NOT IN ('pg_catalog', 'information_schema', 'public')
        SQL))->filter(fn ($c) => in_array($c->column_name, self::COLUMNAS_SIN_SUFIJO, true)
            || str_ends_with($c->column_name, '_at')
            || ($c->table_schema === 'clientes' && $c->table_name === 'deportista_representantes' && in_array($c->column_name, ['fecha_inicio', 'fecha_fin'], true))
            || ($c->table_schema === 'acceso' && $c->table_name === 'credenciales' && in_array($c->column_name, ['vigencia_inicio', 'vigencia_fin'], true)));

        DB::transaction(function () use ($columnas): void {
            foreach ($columnas->groupBy(fn ($c) => "\"{$c->table_schema}\".\"{$c->table_name}\"") as $tabla => $campos) {
                $nombres = $campos->pluck('column_name');
                $set = $nombres->map(fn ($c) => "\"{$c}\" = ".$this->valorConvertido($tabla, $c, $nombres->contains('created_at')))->implode(', ');
                $filtro = $nombres->contains('created_at') ? " WHERE created_at IS NULL OR created_at::time <> '00:00:00'" : '';
                DB::statement("UPDATE {$tabla} SET {$set}{$filtro}");
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Esta conversión se revierte restaurando el respaldo de la base.');
    }

    private function valorConvertido(string $tabla, string $columna, bool $tieneCreatedAt): string
    {
        $restar = "\"{$columna}\" - INTERVAL '5 hours'";
        $fechaSinHora = "\"{$columna}\"::time = '00:00:00'";

        if (in_array("{$tabla}.{$columna}", ['"ventas"."ventas".fecha_venta', '"ventas"."pagos".fecha_pago'], true) || in_array($columna, ['fecha_venta', 'fecha_pago'], true)) {
            return "CASE WHEN ABS(EXTRACT(EPOCH FROM (\"{$columna}\" - created_at))) < 120 AND NOT {$fechaSinHora} THEN {$restar} ELSE \"{$columna}\" END";
        }

        if ($tabla === '"ventas"."turnos_caja"' && $columna === 'fecha_cierre') {
            return "CASE WHEN tipo_cierre = 'AUTOMATICO' OR {$fechaSinHora} THEN \"{$columna}\" ELSE {$restar} END";
        }

        return "CASE WHEN {$fechaSinHora} THEN \"{$columna}\" ELSE {$restar} END";
    }
};
