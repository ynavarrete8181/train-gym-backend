<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Importación histórica: nunca poblar una base limpia de producción.
        if (App::environment('production')) {
            return;
        }

        $nombres = [
            'JESSAEL PALMA',
            'THIARA CHAMORRO',
            'PAUL PALMA PEREZ',
            'ROCIO ROLDAN',
            'CARLOS ZAMBRANO',
            'MATEO ZAMBRANO',
            'CARLOS MONTESDEOCA',
            'CARLITOS MONTESDEOCA',
            'EMA MONTESDEOCA',
            'MIRNA SAN LUCAS',
            'THANIA SAN LUCAS',
            'VALERIA PALACIOS',
            'RAIMON PALMA',
            'XAVIER MEZA',
            'SAUL GARCIA',
            'KEVIN VERA',
            'SOFIA',
            'JAVIER',
            'LUCAS',
            'PATRICIA PEREZ',
            'CRISTHIAN CASANOVA',
            'THALIA CEDEÑO',
            'MARIA EMILIA MEJIA',
            'ARIOSTO ANDRADE',
            'AURO FERNANDEZ',
            'KELLY FRANCO',
            'VIVIANA BARRETO',
            'MELBA LOZADA',
            'ABRAHAM PALMA',
            'PEDRO CEDEÑO',
            'LIAN CEDEÑO',
            'JACOB CEDEÑO',
            'ALEJANDRA OVIEDO',
            'GIA',
            'PAULA PALMA',
            'MARIA FERNANDA ANDRADE',
            'MAYBELINE CHAMBA',
            'AMIGA MAY',
            'MAURO BRIONES',
            'KLEVER LOOR',
            'ALEZEQUIEL PALMA',
            'DANIELA PALMA',
            'ALFREDO SALDARIIAGA',
            'SASKIA SALDARREAGA',
            'DENNIS PALACIOS',
            'GABRIEL ALAVA',
            'SARA ALAVA',
            'IVETTE LOPEZ',
            'THEO',
            'SARA',
            'MILENA',
            'NICOL CALDERERO',
            'GEOVANY CEVALLOS CEVALLOS',
            'JUAN TERAN',
        ];

        DB::transaction(function () use ($nombres): void {
            DB::statement('LOCK TABLE personas.personas IN SHARE ROW EXCLUSIVE MODE');
            DB::statement('LOCK TABLE clientes.deportistas IN SHARE ROW EXCLUSIVE MODE');

            foreach ($nombres as $nombreOriginal) {
                $nombre = $this->normalizarEspacios($nombreOriginal);

                $personaId = DB::table('personas.personas')
                    ->whereRaw('UPPER(TRIM(nombre_completo)) = UPPER(TRIM(?))', [$nombre])
                    ->value('id');

                if (! $personaId) {
                    $personaId = DB::table('personas.personas')->insertGetId([
                        'tipo_identificacion' => null,
                        'identificacion' => null,
                        'nombres' => null,
                        'apellidos' => null,
                        'nombre_completo' => $this->formatoNombre($nombre),
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

                $yaEsDeportista = DB::table('clientes.deportistas')
                    ->where('persona_id', $personaId)
                    ->exists();

                if ($yaEsDeportista) {
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
        // Esta migración carga personas reales. No se eliminan automáticamente
        // en rollback para evitar borrar clientes que luego puedan tener ventas,
        // membresías, reservas u otras relaciones operativas.
    }

    private function normalizarEspacios(string $nombre): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $nombre));
    }

    private function formatoNombre(string $nombre): string
    {
        return mb_convert_case(
            mb_strtolower($nombre, 'UTF-8'),
            MB_CASE_TITLE,
            'UTF-8'
        );
    }
};
