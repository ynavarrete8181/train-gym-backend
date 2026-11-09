<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes.deportista_representantes', function (Blueprint $table): void {
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->string('motivo_fin', 250)->nullable();
            $table->unsignedBigInteger('finalizado_por')->nullable();
        });

        DB::statement("UPDATE clientes.deportista_representantes SET fecha_inicio = COALESCE(created_at, updated_at, now())::date");
        DB::statement("UPDATE clientes.deportista_representantes SET fecha_fin = COALESCE(updated_at, now())::date WHERE activo = false");

        // Un periodo por fila: el mismo tutor puede volver sin perder su historial,
        // pero solo puede existir un vínculo activo con él a la vez.
        DB::statement('ALTER TABLE clientes.deportista_representantes DROP CONSTRAINT IF EXISTS uq_deportista_representante');
        DB::statement('CREATE UNIQUE INDEX uq_deportista_representante_activo ON clientes.deportista_representantes (deportista_id, representante_persona_id) WHERE activo = true');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS clientes.uq_deportista_representante_activo');
        Schema::table('clientes.deportista_representantes', function (Blueprint $table): void {
            $table->dropColumn(['fecha_inicio', 'fecha_fin', 'motivo_fin', 'finalizado_por']);
        });
    }
};
