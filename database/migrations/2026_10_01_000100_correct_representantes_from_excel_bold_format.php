<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Importación histórica: nunca poblar una base limpia de producción.
        if (App::environment('production')) {
            return;
        }

        /*
         * Regla tomada del formato original del Excel:
         * solo el segundo nombre en NEGRITA después de " - " es representante legal.
         *
         * Excepción acordada:
         * "MAMA - PATRICIA PEREZ" se conserva como cliente PATRICIA PEREZ,
         * no como relación representante.
         */
        $clientesImportados = [
            'CARLOS ZAMBRANO',
            'MATEO ZAMBRANO',
            'RAIMON PALMA',
            'SOFIA',
            'JAVIER',
            'LUCAS',
            'GIA',
            'ALEZEQUIEL PALMA',
            'DANIELA PALMA',
            'GABRIEL ALAVA',
            'SARA ALAVA',
            'THEO',
            'SARA',
            'MILENA',
        ];

        $relacionesCorrectas = [
            ['CARLOS ZAMBRANO', 'ADRIANA AZUA'],
            ['MATEO ZAMBRANO', 'ADRIANA AZUA'],
            ['JAVIER', 'PATRICIA PEREZ'],
            ['LUCAS', 'PATRICIA PEREZ'],
            ['GIA', 'KAROL AVENDAÑO'],
            ['DANIELA PALMA', 'JACINTO PALMA'],
            ['GABRIEL ALAVA', 'IVETTE LOPEZ'],
            ['SARA ALAVA', 'IVETTE LOPEZ'],
            ['THEO', 'THAISE DE MACEDO'],
            ['SARA', 'THAISE DE MACEDO'],
        ];

        DB::transaction(function () use ($clientesImportados, $relacionesCorrectas): void {
            foreach ($clientesImportados as $nombreCliente) {
                $deportistaId = $this->deportistaIdPorNombre($nombreCliente);
                if (! $deportistaId) {
                    continue;
                }

                DB::table('clientes.deportistas')
                    ->where('id', $deportistaId)
                    ->update([
                        'requiere_representante_legal' => false,
                        'updated_at' => now(),
                    ]);

                DB::table('clientes.deportista_representantes')
                    ->where('deportista_id', $deportistaId)
                    ->where('activo', true)
                    ->update([
                        'activo' => false,
                        'updated_at' => now(),
                    ]);
            }

            foreach ($relacionesCorrectas as [$nombreCliente, $nombreRepresentante]) {
                $deportistaId = $this->deportistaIdPorNombre($nombreCliente);
                $representantePersonaId = $this->personaIdPorNombre($nombreRepresentante);

                if (! $deportistaId || ! $representantePersonaId) {
                    continue;
                }

                DB::table('clientes.deportista_representantes')->updateOrInsert(
                    [
                        'deportista_id' => $deportistaId,
                        'representante_persona_id' => $representantePersonaId,
                    ],
                    [
                        'tipo_relacion' => 'REPRESENTANTE_LEGAL',
                        'es_principal' => true,
                        'responsable_pago' => true,
                        'activo' => true,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );

                DB::table('clientes.deportistas')
                    ->where('id', $deportistaId)
                    ->update([
                        'requiere_representante_legal' => true,
                        'updated_at' => now(),
                    ]);
            }
        });
    }

    public function down(): void
    {
        // No revertimos datos reales de clientes/representantes automáticamente.
    }

    private function personaIdPorNombre(string $nombre): ?int
    {
        $id = DB::table('personas.personas')
            ->whereRaw('UPPER(TRIM(nombre_completo)) = UPPER(TRIM(?))', [$nombre])
            ->value('id');

        return $id ? (int) $id : null;
    }

    private function deportistaIdPorNombre(string $nombre): ?int
    {
        $personaId = $this->personaIdPorNombre($nombre);

        if (! $personaId) {
            return null;
        }

        $id = DB::table('clientes.deportistas')
            ->where('persona_id', $personaId)
            ->value('id');

        return $id ? (int) $id : null;
    }
};
