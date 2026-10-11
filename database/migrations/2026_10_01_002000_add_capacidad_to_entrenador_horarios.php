<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('agenda.entrenador_horarios', 'capacidad')) {
            Schema::table('agenda.entrenador_horarios', function (Blueprint $table): void {
                $table->unsignedSmallInteger('capacidad')->default(15)->after('activo');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('agenda.entrenador_horarios', 'capacidad')) {
            Schema::table('agenda.entrenador_horarios', function (Blueprint $table): void {
                $table->dropColumn('capacidad');
            });
        }
    }
};
