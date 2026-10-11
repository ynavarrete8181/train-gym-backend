<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('clientes.deportistas', 'requiere_representante_legal')) {
            Schema::table('clientes.deportistas', function (Blueprint $table): void {
                $table->boolean('requiere_representante_legal')->default(false)->after('persona_id');
            });
        }

        if (! Schema::hasTable('clientes.deportista_representantes')) {
            Schema::create('clientes.deportista_representantes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('deportista_id')->constrained('clientes.deportistas')->cascadeOnDelete();
                $table->unsignedBigInteger('representante_persona_id');
                $table->string('tipo_relacion', 40)->default('REPRESENTANTE_LEGAL');
                $table->boolean('es_principal')->default(true);
                $table->boolean('responsable_pago')->default(false);
                $table->boolean('activo')->default(true);
                $table->timestamps();

                $table->foreign('representante_persona_id')
                    ->references('id')
                    ->on('personas.personas')
                    ->restrictOnDelete();

                $table->unique(
                    ['deportista_id', 'representante_persona_id'],
                    'uq_deportista_representante'
                );
                $table->index(['representante_persona_id', 'activo']);
            });
        }

        $relaciones = [
            ['CARLOS ZAMBRANO', 'ADRIANA AZUA'],
            ['MATEO ZAMBRANO', 'ADRIANA AZUA'],
            ['RAIMON PALMA', 'RAMON PALMA'],
            ['SOFIA', 'PATRICIA PEREZ'],
            ['JAVIER', 'PATRICIA PEREZ'],
            ['LUCAS', 'PATRICIA PEREZ'],
            ['GIA', 'KAROL AVENDAÑO'],
            ['ALEZEQUIEL PALMA', 'JACINTO PALMA'],
            ['DANIELA PALMA', 'JACINTO PALMA'],
            ['GABRIEL ALAVA', 'IVETTE LOPEZ'],
            ['SARA ALAVA', 'IVETTE LOPEZ'],
            ['THEO', 'THAISE DE MACEDO'],
            ['SARA', 'THAISE DE MACEDO'],
            ['MILENA', 'ANITA LOOR'],
        ];

        DB::transaction(function () use ($relaciones): void {
            foreach ($relaciones as [$deportistaNombre, $representanteNombre]) {
                $deportistaPersonaId = $this->personaId($deportistaNombre);
                if (! $deportistaPersonaId) {
                    continue;
                }

                $deportistaId = DB::table('clientes.deportistas')
                    ->where('persona_id', $deportistaPersonaId)
                    ->value('id');

                if (! $deportistaId) {
                    continue;
                }

                $representantePersonaId = $this->obtenerOCrearPersona($representanteNombre);

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

            $this->limpiarRepresentantesCreadosComoDeportistas([
                'ADRIANA AZUA',
                'RAMON PALMA',
                'KAROL AVENDAÑO',
                'JACINTO PALMA',
                'THAISE DE MACEDO',
                'ANITA LOOR',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes.deportista_representantes');

        if (Schema::hasColumn('clientes.deportistas', 'requiere_representante_legal')) {
            Schema::table('clientes.deportistas', function (Blueprint $table): void {
                $table->dropColumn('requiere_representante_legal');
            });
        }
    }

    private function personaId(string $nombre): ?int
    {
        $id = DB::table('personas.personas')
            ->whereRaw('UPPER(TRIM(nombre_completo)) = UPPER(TRIM(?))', [$nombre])
            ->value('id');

        return $id ? (int) $id : null;
    }

    private function obtenerOCrearPersona(string $nombre): int
    {
        $id = $this->personaId($nombre);
        if ($id) {
            return $id;
        }

        return (int) DB::table('personas.personas')->insertGetId([
            'tipo_identificacion' => null,
            'identificacion' => null,
            'nombres' => null,
            'apellidos' => null,
            'nombre_completo' => mb_convert_case(mb_strtolower($nombre, 'UTF-8'), MB_CASE_TITLE, 'UTF-8'),
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

    private function limpiarRepresentantesCreadosComoDeportistas(array $nombres): void
    {
        foreach ($nombres as $nombre) {
            $personaId = $this->personaId($nombre);
            if (! $personaId) {
                continue;
            }

            $deportista = DB::table('clientes.deportistas')->where('persona_id', $personaId)->first();
            if (! $deportista || $deportista->usuario_id) {
                continue;
            }

            $tieneOperacion =
                DB::table('membresias.membresias')->where('deportista_id', $deportista->id)->exists()
                || DB::table('agenda.reservas_dia')->where('cliente_id', $deportista->id)->exists()
                || DB::table('entrenamiento.asignaciones_entrenador_cliente')->where('deportista_id', $deportista->id)->exists()
                || DB::table('ventas.ventas')->where('cliente_id', $deportista->id)->exists();

            if (! $tieneOperacion) {
                DB::table('clientes.deportistas')->where('id', $deportista->id)->delete();
            }
        }
    }
};
