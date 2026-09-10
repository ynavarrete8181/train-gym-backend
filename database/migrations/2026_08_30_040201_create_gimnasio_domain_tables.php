<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS gimnasio');

        Schema::create('gimnasio.deportistas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('usuario_id')->constrained('seguridad.users')->cascadeOnDelete();
            $t->string('codigo_deportista', 40)->unique();
            $t->date('fecha_nacimiento')->nullable();
            $t->string('genero', 20)->nullable();
            $t->string('telefono', 30)->nullable();
            $t->string('contacto_emergencia_nombre', 150)->nullable();
            $t->string('contacto_emergencia_telefono', 30)->nullable();
            $t->text('observaciones_medicas')->nullable();
            $t->unsignedBigInteger('sede_principal_id')->nullable();
            $t->string('estado', 30)->default('ACTIVO'); // PROSPECTO, ACTIVO, INACTIVO, SUSPENDIDO
            $t->timestamps();

            $t->foreign('sede_principal_id')->references('id_sede')->on('institucional.sedes')->nullOnDelete();
        });

        Schema::create('gimnasio.planes', function (Blueprint $t) {
            $t->id();
            $t->string('codigo', 50)->unique();
            $t->string('nombre', 150);
            $t->text('descripcion')->nullable();
            $t->string('tipo_duracion', 20); // DIAS, MESES, ANIOS
            $t->integer('duracion');
            $t->decimal('precio_base', 10, 2);
            $t->decimal('tarifa_inscripcion', 10, 2)->default(0);
            $t->boolean('activo')->default(true);
            $t->timestamps();
        });

        Schema::create('gimnasio.membresias', function (Blueprint $t) {
            $t->id();
            $t->foreignId('deportista_id')->constrained('gimnasio.deportistas')->cascadeOnDelete();
            $t->foreignId('plan_id')->constrained('gimnasio.planes')->restrictOnDelete();
            $t->string('codigo_contrato', 60)->unique();
            $t->date('fecha_inicio');
            $t->date('fecha_fin');
            $t->date('fecha_congelacion_inicio')->nullable();
            $t->date('fecha_congelacion_fin')->nullable();
            $t->string('estado', 30); // PENDIENTE_PAGO, ACTIVA, VENCIDA, CONGELADA, CANCELADA
            $t->integer('dias_gracia')->default(0);
            $t->boolean('renovacion_automatica')->default(false);
            $t->timestamps();
            
            $t->index('estado');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gimnasio.membresias');
        Schema::dropIfExists('gimnasio.planes');
        Schema::dropIfExists('gimnasio.deportistas');
        DB::statement('DROP SCHEMA IF EXISTS gimnasio CASCADE');
    }
};
