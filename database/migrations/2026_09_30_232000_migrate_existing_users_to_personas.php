<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('personas.personas')) {
            throw new RuntimeException('No existe personas.personas. Ejecuta primero la modularización de esquemas.');
        }

        DB::transaction(function (): void {
            $usuarios = DB::table('seguridad.users')
                ->orderBy('id')
                ->get();

            foreach ($usuarios as $usuario) {
                $personaId = $usuario->persona_id ?? null;

                if ($personaId && DB::table('personas.personas')->where('id', $personaId)->exists()) {
                    continue;
                }

                $identificacion = $this->limpiar($usuario->cedula ?? null);
                $email = $this->limpiar($usuario->email ?? null);
                $nombres = $this->limpiar($usuario->nombres ?? null);
                $apellidos = $this->limpiar($usuario->apellidos ?? null);
                $nombreCompleto = $this->limpiar($usuario->name ?? null)
                    ?: trim(implode(' ', array_filter([$nombres, $apellidos])));

                if ($nombreCompleto === '') {
                    $nombreCompleto = $email ?: ('Persona usuario #' . $usuario->id);
                }

                $persona = null;

                if ($identificacion) {
                    $persona = DB::table('personas.personas')
                        ->whereRaw('LOWER(TRIM(identificacion)) = LOWER(TRIM(?))', [$identificacion])
                        ->first();
                }

                if (! $persona && $email) {
                    $persona = DB::table('personas.personas')
                        ->whereNotNull('email')
                        ->whereRaw('LOWER(TRIM(email)) = LOWER(TRIM(?))', [$email])
                        ->first();
                }

                if ($persona) {
                    $personaId = (int) $persona->id;

                    DB::table('personas.personas')
                        ->where('id', $personaId)
                        ->update([
                            'tipo_identificacion' => $persona->tipo_identificacion
                                ?: ($identificacion ? 'CEDULA' : null),
                            'identificacion' => $persona->identificacion ?: $identificacion,
                            'nombres' => $persona->nombres ?: $nombres,
                            'apellidos' => $persona->apellidos ?: $apellidos,
                            'nombre_completo' => $persona->nombre_completo ?: $nombreCompleto,
                            'email' => $persona->email ?: $email,
                            'updated_at' => now(),
                        ]);
                } else {
                    $personaId = DB::table('personas.personas')->insertGetId([
                        'tipo_identificacion' => $identificacion ? 'CEDULA' : null,
                        'identificacion' => $identificacion,
                        'nombres' => $nombres,
                        'apellidos' => $apellidos,
                        'nombre_completo' => $nombreCompleto,
                        'fecha_nacimiento' => null,
                        'genero' => null,
                        'telefono' => null,
                        'email' => $email,
                        'direccion' => null,
                        'activo' => true,
                        'created_at' => $usuario->created_at ?? now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('seguridad.users')
                    ->where('id', $usuario->id)
                    ->update([
                        'persona_id' => $personaId,
                        'updated_at' => now(),
                    ]);
            }

            DB::statement(<<<'SQL'
                UPDATE clientes.deportistas d
                SET persona_id = u.persona_id,
                    updated_at = CURRENT_TIMESTAMP
                FROM seguridad.users u
                WHERE d.usuario_id = u.id
                  AND d.persona_id IS NULL
                  AND u.persona_id IS NOT NULL
            SQL);

            DB::statement(<<<'SQL'
                UPDATE entrenamiento.entrenadores e
                SET persona_id = u.persona_id,
                    updated_at = CURRENT_TIMESTAMP
                FROM seguridad.users u
                WHERE e.usuario_id = u.id
                  AND e.persona_id IS NULL
                  AND u.persona_id IS NOT NULL
            SQL);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            if (Schema::hasTable('clientes.deportistas') && Schema::hasColumn('clientes.deportistas', 'persona_id')) {
                DB::table('clientes.deportistas')->update(['persona_id' => null]);
            }

            if (Schema::hasTable('entrenamiento.entrenadores') && Schema::hasColumn('entrenamiento.entrenadores', 'persona_id')) {
                DB::table('entrenamiento.entrenadores')->update(['persona_id' => null]);
            }

            if (Schema::hasTable('seguridad.users') && Schema::hasColumn('seguridad.users', 'persona_id')) {
                DB::table('seguridad.users')->update(['persona_id' => null]);
            }

            if (Schema::hasTable('personas.personas')) {
                DB::table('personas.personas')->delete();
            }
        });
    }

    private function limpiar(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }
};
