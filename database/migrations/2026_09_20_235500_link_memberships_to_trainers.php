<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gimnasio.membresias', function (Blueprint $table): void {
            if (! Schema::hasColumn('gimnasio.membresias', 'entrenador_id')) {
                $table->unsignedBigInteger('entrenador_id')->nullable()->after('sede_id');
                $table->foreign('entrenador_id')->references('id')->on('gimnasio.entrenadores')->nullOnDelete();
                $table->index('entrenador_id');
            }
        });

        Schema::table('gimnasio.asignaciones_entrenador_cliente', function (Blueprint $table): void {
            if (! Schema::hasColumn('gimnasio.asignaciones_entrenador_cliente', 'membresia_id')) {
                $table->unsignedBigInteger('membresia_id')->nullable()->after('deportista_id');
                $table->foreign('membresia_id')->references('id')->on('gimnasio.membresias')->cascadeOnDelete();
                $table->index('membresia_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('gimnasio.asignaciones_entrenador_cliente', function (Blueprint $table): void {
            if (Schema::hasColumn('gimnasio.asignaciones_entrenador_cliente', 'membresia_id')) {
                $table->dropForeign(['membresia_id']);
                $table->dropIndex(['membresia_id']);
                $table->dropColumn('membresia_id');
            }
        });

        Schema::table('gimnasio.membresias', function (Blueprint $table): void {
            if (Schema::hasColumn('gimnasio.membresias', 'entrenador_id')) {
                $table->dropForeign(['entrenador_id']);
                $table->dropIndex(['entrenador_id']);
                $table->dropColumn('entrenador_id');
            }
        });
    }
};
