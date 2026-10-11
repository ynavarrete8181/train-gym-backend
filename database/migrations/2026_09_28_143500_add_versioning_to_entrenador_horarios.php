<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gimnasio.entrenador_horarios', function (Blueprint $table): void {
            if (! Schema::hasColumn('gimnasio.entrenador_horarios', 'version')) {
                $table->unsignedInteger('version')->default(1)->after('entrenador_id');
                $table->index(['entrenador_id', 'version']);
            }

            if (! Schema::hasColumn('gimnasio.entrenador_horarios', 'version_anterior_id')) {
                $table->unsignedBigInteger('version_anterior_id')->nullable()->after('version');
                $table->foreign('version_anterior_id')
                    ->references('id')
                    ->on('gimnasio.entrenador_horarios')
                    ->nullOnDelete();
            }
        });

        DB::statement('
            WITH versiones AS (
                SELECT id,
                       ROW_NUMBER() OVER (
                           PARTITION BY entrenador_id
                           ORDER BY created_at ASC NULLS FIRST, id ASC
                       ) AS numero
                FROM gimnasio.entrenador_horarios
            )
            UPDATE gimnasio.entrenador_horarios h
            SET version = versiones.numero
            FROM versiones
            WHERE versiones.id = h.id
        ');
    }

    public function down(): void
    {
        Schema::table('gimnasio.entrenador_horarios', function (Blueprint $table): void {
            if (Schema::hasColumn('gimnasio.entrenador_horarios', 'version_anterior_id')) {
                $table->dropForeign(['version_anterior_id']);
                $table->dropColumn('version_anterior_id');
            }

            if (Schema::hasColumn('gimnasio.entrenador_horarios', 'version')) {
                $table->dropIndex(['entrenador_id', 'version']);
                $table->dropColumn('version');
            }
        });
    }
};
