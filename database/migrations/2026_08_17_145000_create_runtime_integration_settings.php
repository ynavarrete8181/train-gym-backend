<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integraciones.configuraciones', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('clave', 120)->unique();
            $table->text('valor');
            $table->string('tipo', 30)->default('STRING');
            $table->string('descripcion', 255)->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable()->index();
            $table->timestampsTz();
            $table->foreign('actualizado_por')->references('id')->on('seguridad.users')->nullOnDelete();
        });

        DB::table('integraciones.configuraciones')->insert([
            'clave' => 'notificaciones.correos_por_minuto',
            'valor' => '10',
            'tipo' => 'INTEGER',
            'descripcion' => 'Cantidad máxima de correos que puede procesar la cola por minuto.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('integraciones.configuraciones');
    }
};
