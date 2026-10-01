<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $nombres = [
            'ADRIANA AZUA',
            'RAMON PALMA',
            'KAROL AVENDAÑO',
            'JACINTO PALMA',
            'THAISE DE MACEDO',
            'ANITA LOOR',
        ];

        DB::transaction(function () use ($nombres): void {
            DB::statement('LOCK TABLE personas.personas IN SHARE ROW EXCLUSIVE MODE');
            DB::statement('LOCK TABLE clientes.deportistas IN SHARE ROW EXCLUSIVE MODE');

            foreach ($nombres as $nombreOriginal) {
                $nombre = trim((string) preg_replace('/\\s+/', ' ', $nombreOriginal));

                $personaId = DB::table('personas.personas')
                    ->whereRaw('UPPER(TRIM(nombre_completo)) = UPPER(TRIM(?))', [$nombre])
                    ->value('id');

                if (! $personaId) {
                    $personaId = DB::table('personas.personas')->insertGetId([
                        'tipo_identificacion' => null,
                        'identificacion' => null,
                        'nombres' => null,
                        'apellidos' => null,
                        'nombre_completo' => mb_convert_case(
                            mb_strtolower($nombre, 'UTF-8'),
                            MB_CASE_TITLE,
                            'UTF-8'
                        ),
                        'fecha_nacimiento' => null,
                        'genero' => null,
                        'telefono' => null,
                        'email' => null,
                        'direccion' => null,
                        'activo' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                if (DB::table('clientes.deportistas')->where('persona_id', $personaId)->exists()) {
                    continue;
                }

                $id = DB::table('clientes.deportistas')->insertGetId([
                    'persona_id' => $personaId,
                    'usuario_id' => null,
                    'codigo_deportista' => 'TMP-' . Str::uuid(),
                    'fecha_nacimiento' => null,
                    'genero' => null,
                    'telefono' => null,
                    'contacto_emergencia_nombre' => null,
                    'contacto_emergencia_telefono' => null,
                    'observaciones_medicas' => null,
                    'sede_principal_id' => null,
                    'estado' => 'ACTIVO',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('clientes.deportistas')
                    ->where('id', $id)
                    ->update([
                        'codigo_deportista' => 'DEP-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT),
                        'updated_at' => now(),
                    ]);
            }
        });
    }

    public function down(): void
    {
        // No elimina personas/clientes reales para preservar integridad histórica.
    }
};
