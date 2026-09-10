<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('gimnasio.membresias', 'sede_id')) {
            Schema::table('gimnasio.membresias', function (Blueprint $table): void {
                $table->unsignedBigInteger('sede_id')->nullable()->after('plan_id');
                $table->decimal('precio_aplicado', 10, 2)->nullable()->after('sede_id');
            });

            Schema::table('gimnasio.membresias', function (Blueprint $table): void {
                $table->foreign('sede_id')->references('id_sede')->on('institucional.sedes')->restrictOnDelete();
            });

            DB::statement('CREATE INDEX IF NOT EXISTS idx_membresias_sede ON gimnasio.membresias (sede_id)');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('gimnasio.membresias', 'sede_id')) {
            Schema::table('gimnasio.membresias', function (Blueprint $table): void {
                $table->dropForeign(['sede_id']);
            });

            Schema::table('gimnasio.membresias', function (Blueprint $table): void {
                $table->dropColumn(['sede_id', 'precio_aplicado']);
            });
        }
    }
};
