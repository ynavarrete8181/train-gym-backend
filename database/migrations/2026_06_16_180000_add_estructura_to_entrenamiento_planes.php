<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entrenamiento.planes', function (Blueprint $table) {
            $table->string('estructura', 30)->default('SEMANAL')->after('tipo');
        });

        DB::table('entrenamiento.planes')
            ->whereNull('estructura')
            ->update(['estructura' => 'SEMANAL']);
    }

    public function down(): void
    {
        Schema::table('entrenamiento.planes', function (Blueprint $table) {
            $table->dropColumn('estructura');
        });
    }
};
