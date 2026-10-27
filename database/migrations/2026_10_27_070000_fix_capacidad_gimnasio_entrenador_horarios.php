<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gimnasio.entrenador_horarios')
            && ! Schema::hasColumn('gimnasio.entrenador_horarios', 'capacidad')) {
            Schema::table('gimnasio.entrenador_horarios', function (Blueprint $table): void {
                $table->unsignedSmallInteger('capacidad')->default(15);
            });
        }
    }

    public function down(): void
    {
        // No eliminar una columna potencialmente utilizada por configuraciones activas.
    }
};
