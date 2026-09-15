<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institucional.sedes', function (Blueprint $t): void {
            $t->string('direccion', 250)->nullable();
            $t->string('ciudad', 120)->nullable();
            $t->string('provincia', 120)->nullable();
            $t->string('telefono', 30)->nullable();
            $t->string('whatsapp', 30)->nullable();
            $t->string('email', 180)->nullable();
            $t->time('hora_apertura')->nullable();
            $t->time('hora_cierre')->nullable();
            $t->boolean('maneja_caja')->default(true);
            $t->boolean('maneja_inventario')->default(true);
            $t->boolean('permite_reservas')->default(true);
            $t->boolean('permite_entrenamiento')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('institucional.sedes', function (Blueprint $t): void {
            $t->dropColumn([
                'direccion',
                'ciudad',
                'provincia',
                'telefono',
                'whatsapp',
                'email',
                'hora_apertura',
                'hora_cierre',
                'maneja_caja',
                'maneja_inventario',
                'permite_reservas',
                'permite_entrenamiento',
            ]);
        });
    }
};
