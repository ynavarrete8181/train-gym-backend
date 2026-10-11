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
            if (! Schema::hasColumn('gimnasio.entrenador_horarios', 'tipo_horario')) {
                $table->string('tipo_horario', 20)->default('PERSONALIZADO')->after('entrenador_id');
            }

            if (! Schema::hasColumn('gimnasio.entrenador_horarios', 'jornada_id')) {
                $table->unsignedBigInteger('jornada_id')->nullable()->after('tipo_horario');
                $table->foreign('jornada_id')->references('id')->on('gimnasio.jornadas')->nullOnDelete();
                $table->index('jornada_id');
            }
        });

        DB::statement('ALTER TABLE gimnasio.entrenador_horarios ALTER COLUMN fecha_inicio DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE gimnasio.entrenador_horarios SET fecha_inicio = CURRENT_DATE WHERE fecha_inicio IS NULL");
        DB::statement('ALTER TABLE gimnasio.entrenador_horarios ALTER COLUMN fecha_inicio SET NOT NULL');

        Schema::table('gimnasio.entrenador_horarios', function (Blueprint $table): void {
            if (Schema::hasColumn('gimnasio.entrenador_horarios', 'jornada_id')) {
                $table->dropForeign(['jornada_id']);
                $table->dropIndex(['jornada_id']);
                $table->dropColumn('jornada_id');
            }
            if (Schema::hasColumn('gimnasio.entrenador_horarios', 'tipo_horario')) {
                $table->dropColumn('tipo_horario');
            }
        });
    }
};
