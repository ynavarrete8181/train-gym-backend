<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reorganiza el antiguo esquema monolítico gimnasio en dominios funcionales.
     *
     * IMPORTANTE:
     * - ALTER TABLE ... SET SCHEMA conserva datos, PK, FK, índices y secuencias.
     * - Se crean vistas simples en gimnasio.* como capa temporal de compatibilidad.
     * - Las vistas simples de PostgreSQL son actualizables para INSERT/UPDATE/DELETE
     *   mientras el backend termina de migrar sus referencias.
     */
    public function up(): void
    {
        foreach (['personas', 'clientes', 'membresias', 'servicios', 'entrenamiento', 'agenda'] as $schema) {
            DB::statement("CREATE SCHEMA IF NOT EXISTS {$schema}");
        }

        $this->crearBasePersonas();

        $mapa = $this->mapaTablas();

        // Primero se eliminan exclusivamente vistas de compatibilidad previas.
        // No usamos DROP VIEW IF EXISTS directamente porque PostgreSQL falla
        // si en ese nombre existe una tabla real.
        foreach ($mapa as $destino => $tablas) {
            foreach ($tablas as $tabla) {
                if ($this->esVista('gimnasio', $tabla)) {
                    DB::statement("DROP VIEW gimnasio.{$tabla}");
                }
            }
        }

        // Mover físicamente las tablas. PostgreSQL mantiene las FK por OID.
        foreach ($mapa as $destino => $tablas) {
            foreach ($tablas as $tabla) {
                if ($this->esTablaBase('gimnasio', $tabla)) {
                    DB::statement("ALTER TABLE gimnasio.{$tabla} SET SCHEMA {$destino}");
                }
            }
        }

        $this->prepararRelacionesPersonas();

        // Capa de compatibilidad temporal para no romper el backend existente.
        foreach ($mapa as $destino => $tablas) {
            foreach ($tablas as $tabla) {
                if ($this->esTablaBase($destino, $tabla)) {
                    DB::statement("CREATE OR REPLACE VIEW gimnasio.{$tabla} AS SELECT * FROM {$destino}.{$tabla}");
                }
            }
        }
    }

    public function down(): void
    {
        $mapa = $this->mapaTablas();

        foreach ($mapa as $destino => $tablas) {
            foreach ($tablas as $tabla) {
                if ($this->esVista('gimnasio', $tabla)) {
                    DB::statement("DROP VIEW gimnasio.{$tabla}");
                }
            }
        }

        foreach (array_reverse($mapa, true) as $destino => $tablas) {
            foreach ($tablas as $tabla) {
                if ($this->esTablaBase($destino, $tabla) && ! $this->relacionExiste('gimnasio', $tabla)) {
                    DB::statement("ALTER TABLE {$destino}.{$tabla} SET SCHEMA gimnasio");
                }
            }
        }

        if (Schema::hasColumn('seguridad.users', 'persona_id')) {
            Schema::table('seguridad.users', function (Blueprint $table): void {
                $table->dropForeign(['persona_id']);
                $table->dropColumn('persona_id');
            });
        }

        Schema::dropIfExists('personas.personas');

        foreach (['agenda', 'entrenamiento', 'servicios', 'membresias', 'clientes', 'personas'] as $schema) {
            DB::statement("
                DO \$\$
                BEGIN
                    IF EXISTS (
                        SELECT 1
                        FROM pg_namespace n
                        WHERE n.nspname = '{$schema}'
                    )
                    AND NOT EXISTS (
                        SELECT 1
                        FROM pg_class c
                        JOIN pg_namespace n ON n.oid = c.relnamespace
                        WHERE n.nspname = '{$schema}'
                          AND c.relkind IN ('r','p','v','m','S')
                    ) THEN
                        EXECUTE 'DROP SCHEMA {$schema}';
                    END IF;
                END
                \$\$;
            ");
        }
    }

    private function crearBasePersonas(): void
    {
        if (! Schema::hasTable('personas.personas')) {
            Schema::create('personas.personas', function (Blueprint $table): void {
                $table->id();
                $table->string('tipo_identificacion', 30)->nullable();
                $table->string('identificacion', 50)->nullable();
                $table->string('nombres', 150)->nullable();
                $table->string('apellidos', 150)->nullable();
                $table->string('nombre_completo', 300);
                $table->date('fecha_nacimiento')->nullable();
                $table->string('genero', 30)->nullable();
                $table->string('telefono', 30)->nullable();
                $table->string('email', 190)->nullable();
                $table->text('direccion')->nullable();
                $table->boolean('activo')->default(true);
                $table->timestamps();

                $table->index('identificacion', 'idx_personas_identificacion');
                $table->index('email', 'idx_personas_email');
                $table->index(['apellidos', 'nombres'], 'idx_personas_nombre');
            });
        }

        if (! Schema::hasColumn('seguridad.users', 'persona_id')) {
            Schema::table('seguridad.users', function (Blueprint $table): void {
                $table->unsignedBigInteger('persona_id')->nullable()->after('id');
                $table->foreign('persona_id')
                    ->references('id')
                    ->on('personas.personas')
                    ->nullOnDelete();
                $table->index('persona_id');
            });
        }
    }

    private function prepararRelacionesPersonas(): void
    {
        if (Schema::hasTable('clientes.deportistas')) {
            if (! Schema::hasColumn('clientes.deportistas', 'persona_id')) {
                Schema::table('clientes.deportistas', function (Blueprint $table): void {
                    $table->unsignedBigInteger('persona_id')->nullable()->after('id');
                    $table->foreign('persona_id')
                        ->references('id')
                        ->on('personas.personas')
                        ->restrictOnDelete();
                    $table->index('persona_id');
                });
            }

            // Un cliente puede existir administrativamente sin cuenta de acceso.
            if (Schema::hasColumn('clientes.deportistas', 'usuario_id')) {
                DB::statement('ALTER TABLE clientes.deportistas ALTER COLUMN usuario_id DROP NOT NULL');
            }
        }

        if (Schema::hasTable('entrenamiento.entrenadores')) {
            if (! Schema::hasColumn('entrenamiento.entrenadores', 'persona_id')) {
                Schema::table('entrenamiento.entrenadores', function (Blueprint $table): void {
                    $table->unsignedBigInteger('persona_id')->nullable()->after('id');
                    $table->foreign('persona_id')
                        ->references('id')
                        ->on('personas.personas')
                        ->restrictOnDelete();
                    $table->index('persona_id');
                });
            }

            if (Schema::hasColumn('entrenamiento.entrenadores', 'usuario_id')) {
                DB::statement('ALTER TABLE entrenamiento.entrenadores ALTER COLUMN usuario_id DROP NOT NULL');
            }
        }
    }

    /**
     * Dominio destino => tablas actuales del esquema gimnasio.
     */
    private function mapaTablas(): array
    {
        return [
            'clientes' => [
                'deportistas',
            ],
            'membresias' => [
                'planes',
                'plan_precios_sede',
                'plan_servicios',
                'membresias',
                'membresia_sedes',
                'membresia_periodos',
            ],
            'servicios' => [
                'categorias_servicio',
                'servicios',
            ],
            'entrenamiento' => [
                'entrenadores',
                'entrenador_servicios',
                'asignaciones_entrenador_cliente',
            ],
            'agenda' => [
                'horario_bloques',
                'horarios_servicio',
                'horario_entrenadores',
                'jornadas',
                'jornada_detalles',
                'recesos',
                'asignaciones_horario_entrenador',
                'excepciones_horario',
                'reservas_dia',
                'entrenador_horarios',
                'entrenador_horario_franjas',
                'entrenador_horario_recesos',
            ],
        ];
    }

    private function esTablaBase(string $schema, string $tabla): bool
    {
        return DB::table('pg_class as c')
            ->join('pg_namespace as n', 'n.oid', '=', 'c.relnamespace')
            ->where('n.nspname', $schema)
            ->where('c.relname', $tabla)
            ->whereIn('c.relkind', ['r', 'p'])
            ->exists();
    }

    private function esVista(string $schema, string $tabla): bool
    {
        return DB::table('pg_class as c')
            ->join('pg_namespace as n', 'n.oid', '=', 'c.relnamespace')
            ->where('n.nspname', $schema)
            ->where('c.relname', $tabla)
            ->whereIn('c.relkind', ['v', 'm'])
            ->exists();
    }

    private function relacionExiste(string $schema, string $tabla): bool
    {
        return DB::table('pg_class as c')
            ->join('pg_namespace as n', 'n.oid', '=', 'c.relnamespace')
            ->where('n.nspname', $schema)
            ->where('c.relname', $tabla)
            ->exists();
    }
};
