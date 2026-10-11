<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gimnasio.asignaciones_entrenador_cliente', function (Blueprint $table): void {
            if (! Schema::hasColumn('gimnasio.asignaciones_entrenador_cliente', 'sede_id')) {
                $table->unsignedBigInteger('sede_id')->nullable()->after('membresia_id');
                $table->foreign('sede_id')->references('id_sede')->on('institucional.sedes')->restrictOnDelete();
                $table->index(['membresia_id', 'sede_id', 'estado']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('gimnasio.asignaciones_entrenador_cliente', function (Blueprint $table): void {
            if (Schema::hasColumn('gimnasio.asignaciones_entrenador_cliente', 'sede_id')) {
                $table->dropForeign(['sede_id']);
                $table->dropIndex(['membresia_id', 'sede_id', 'estado']);
                $table->dropColumn('sede_id');
            }
        });
    }
};
