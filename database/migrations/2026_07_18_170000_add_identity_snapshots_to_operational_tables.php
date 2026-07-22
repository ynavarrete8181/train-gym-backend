<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $tables = [
        'auditoria.eventos' => [
            ['usuario', 'usuario_id', 'usuario_cedula'],
            ['persona', 'persona_id_afectada', 'persona_afectada_cedula'],
        ],
        'auditoria.aud_cambios' => [
            ['usuario', 'actor_usuario_id', 'actor_usuario_cedula'],
            ['persona', 'actor_persona_id', 'actor_persona_cedula'],
        ],
        'logs.eventos' => [
            ['usuario', 'usuario_id', 'usuario_cedula'],
            ['persona', 'persona_id', 'persona_cedula'],
        ],
        'staff.perfiles' => [
            ['persona', 'persona_id', 'persona_cedula'],
            ['usuario', 'usuario_id', 'usuario_cedula'],
        ],
        'staff.cliente_asignaciones' => [
            ['persona', 'persona_id', 'persona_cedula'],
        ],
        'reservas.reservas' => [
            ['persona', 'persona_id', 'persona_cedula'],
            ['usuario', 'coach_usuario_id', 'coach_usuario_cedula'],
            ['usuario', 'created_by_usuario_id', 'created_by_usuario_cedula'],
        ],
        'asistencia.registros' => [
            ['persona', 'persona_id', 'persona_cedula'],
            ['usuario', 'registrado_por_usuario_id', 'registrado_por_usuario_cedula'],
        ],
        'acceso.credenciales' => [
            ['persona', 'persona_id', 'persona_cedula'],
        ],
        'acceso.eventos' => [
            ['persona', 'persona_id', 'persona_cedula'],
        ],
        'notificaciones.notificaciones' => [
            ['usuario', 'created_by_usuario_id', 'created_by_usuario_cedula'],
        ],
        'notificaciones.destinatarios' => [
            ['usuario', 'usuario_id', 'usuario_cedula'],
            ['persona', 'persona_id', 'persona_cedula'],
        ],
        'notificaciones.dispositivos_push' => [
            ['usuario', 'usuario_id', 'usuario_cedula'],
            ['persona', 'persona_id', 'persona_cedula'],
        ],
        'ventas.ventas' => [
            ['persona', 'persona_id', 'persona_cedula'],
            ['usuario', 'vendedor_usuario_id', 'vendedor_usuario_cedula'],
        ],
        'ventas.punto_venta_borradores' => [
            ['usuario', 'usuario_id', 'usuario_cedula'],
            ['persona', 'persona_id', 'persona_cedula'],
        ],
        'socios.socios' => [
            ['persona', 'persona_id', 'persona_cedula'],
        ],
        'core.persona_tipo_detalle' => [
            ['persona', 'persona_id', 'persona_cedula'],
        ],
        'salud.fichas_tecnicas' => [
            ['persona', 'persona_id', 'persona_cedula'],
        ],
        'entrenamiento.planes' => [
            ['persona', 'persona_id', 'persona_cedula'],
        ],
        'entrenamiento.plan_asignaciones' => [
            ['persona', 'persona_id', 'persona_cedula'],
        ],
    ];

    public function up(): void
    {
        $this->createResolverFunctions();

        foreach ($this->tables as $table => $pairs) {
            $validPairs = array_values(array_filter($pairs, fn ($pair) => $this->tableExists($table) && $this->columnExists($table, $pair[1])));
            if (!$validPairs) {
                continue;
            }

            foreach ($validPairs as [$kind, $source, $target]) {
                DB::statement("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS {$target} VARCHAR(30)");
                $resolver = $kind === 'usuario' ? 'core.resolve_usuario_cedula' : 'core.resolve_persona_cedula';
                DB::statement("
                    UPDATE {$table} t
                    SET {$target} = {$resolver}(t.{$source})
                    WHERE t.{$source} IS NOT NULL
                      AND COALESCE(NULLIF(TRIM(t.{$target}), ''), '') = ''
                ");

                $index = $this->indexName($table, $target);
                DB::statement("CREATE INDEX IF NOT EXISTS {$index} ON {$table} ({$target}) WHERE {$target} IS NOT NULL");
            }

            $trigger = $this->triggerName($table);
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger} ON {$table}");
            DB::unprepared("
                CREATE TRIGGER {$trigger}
                BEFORE INSERT OR UPDATE ON {$table}
                FOR EACH ROW
                EXECUTE FUNCTION core.sync_identity_snapshot({$this->triggerArgs($validPairs)})
            ");
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table => $pairs) {
            if (!$this->tableExists($table)) {
                continue;
            }

            DB::unprepared('DROP TRIGGER IF EXISTS ' . $this->triggerName($table) . " ON {$table}");

            foreach ($pairs as [, , $target]) {
                if ($this->columnExists($table, $target)) {
                    DB::statement('DROP INDEX IF EXISTS ' . $this->indexName($table, $target));
                    DB::statement("ALTER TABLE {$table} DROP COLUMN IF EXISTS {$target}");
                }
            }
        }

        DB::statement('DROP FUNCTION IF EXISTS core.sync_identity_snapshot()');
        DB::statement('DROP FUNCTION IF EXISTS core.resolve_usuario_cedula(BIGINT)');
        DB::statement('DROP FUNCTION IF EXISTS core.resolve_persona_cedula(BIGINT)');
    }

    private function createResolverFunctions(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION core.resolve_persona_cedula(p_persona_id BIGINT)
RETURNS VARCHAR
LANGUAGE sql
STABLE
AS $$
    SELECT NULLIF(TRIM(numero_identificacion), '')
    FROM core.personas
    WHERE id = p_persona_id
$$;

CREATE OR REPLACE FUNCTION core.resolve_usuario_cedula(p_usuario_id BIGINT)
RETURNS VARCHAR
LANGUAGE sql
STABLE
AS $$
    SELECT COALESCE(NULLIF(TRIM(u.cedula), ''), NULLIF(TRIM(p.numero_identificacion), ''))
    FROM seguridad.usuarios u
    LEFT JOIN core.personas p ON p.id = u.persona_id
    WHERE u.id = p_usuario_id
$$;

CREATE OR REPLACE FUNCTION core.sync_identity_snapshot()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    idx INTEGER := 0;
    kind TEXT;
    source_col TEXT;
    target_col TEXT;
    source_value BIGINT;
    resolved_cedula VARCHAR(30);
BEGIN
    WHILE idx < TG_NARGS LOOP
        kind := TG_ARGV[idx];
        source_col := TG_ARGV[idx + 1];
        target_col := TG_ARGV[idx + 2];

        EXECUTE format('SELECT ($1).%I', source_col)
        INTO source_value
        USING NEW;

        IF source_value IS NULL THEN
            resolved_cedula := NULL;
        ELSIF kind = 'usuario' THEN
            resolved_cedula := core.resolve_usuario_cedula(source_value);
        ELSE
            resolved_cedula := core.resolve_persona_cedula(source_value);
        END IF;

        NEW := jsonb_populate_record(NEW, jsonb_build_object(target_col, resolved_cedula));
        idx := idx + 3;
    END LOOP;

    RETURN NEW;
END;
$$;
SQL);
    }

    private function tableExists(string $table): bool
    {
        $row = DB::selectOne('SELECT to_regclass(?) AS table_name', [$table]);
        return !empty($row?->table_name);
    }

    private function columnExists(string $table, string $column): bool
    {
        [$schema, $name] = explode('.', $table, 2);
        $row = DB::selectOne(
            'SELECT 1 FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?',
            [$schema, $name, $column]
        );

        return !empty($row);
    }

    private function indexName(string $table, string $column): string
    {
        return str_replace('.', '_', $table) . '_' . $column . '_idx';
    }

    private function triggerName(string $table): string
    {
        return 'trg_' . str_replace('.', '_', $table) . '_identity_snapshot';
    }

    private function triggerArgs(array $pairs): string
    {
        return collect($pairs)
            ->flatMap(fn ($pair) => $pair)
            ->map(fn ($arg) => DB::getPdo()->quote($arg))
            ->implode(', ');
    }
};
