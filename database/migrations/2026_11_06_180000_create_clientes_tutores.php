<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('clientes.tutores')) {
            Schema::create('clientes.tutores', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('persona_id')->unique();
                $table->string('estado', 30)->default('ACTIVO');
                $table->boolean('activo')->default(true);
                $table->timestamps();

                $table->foreign('persona_id')
                    ->references('id')
                    ->on('personas.personas')
                    ->restrictOnDelete();
            });
        }

        // Quienes ya representan a un deportista quedan identificados como tutores.
        $personas = DB::table('clientes.deportista_representantes')
            ->where('activo', true)
            ->distinct()
            ->pluck('representante_persona_id');

        foreach ($personas as $personaId) {
            DB::table('clientes.tutores')->insertOrIgnore([
                'persona_id' => $personaId,
                'estado' => 'ACTIVO',
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes.tutores');
    }
};
