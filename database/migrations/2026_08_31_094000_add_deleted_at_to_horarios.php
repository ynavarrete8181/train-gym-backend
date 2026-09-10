<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gimnasio.horario_bloques', function (Blueprint $table): void {
            if (! Schema::hasColumn('gimnasio.horario_bloques', 'deleted_at')) {
                $table->timestamp('deleted_at')->nullable()->after('updated_at');
            }
        });

        Schema::table('gimnasio.horarios_servicio', function (Blueprint $table): void {
            if (! Schema::hasColumn('gimnasio.horarios_servicio', 'deleted_at')) {
                $table->timestamp('deleted_at')->nullable()->after('updated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('gimnasio.horarios_servicio', function (Blueprint $table): void {
            if (Schema::hasColumn('gimnasio.horarios_servicio', 'deleted_at')) {
                $table->dropColumn('deleted_at');
            }
        });

        Schema::table('gimnasio.horario_bloques', function (Blueprint $table): void {
            if (Schema::hasColumn('gimnasio.horario_bloques', 'deleted_at')) {
                $table->dropColumn('deleted_at');
            }
        });
    }
};
