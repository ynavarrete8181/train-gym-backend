<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gimnasio.planes', function (Blueprint $table): void {
            if (! Schema::hasColumn('gimnasio.planes', 'requiere_entrenador')) {
                $table->boolean('requiere_entrenador')->default(false)->after('requiere_pago');
            }
        });

        if (! Schema::hasTable('gimnasio.membresia_sedes')) {
            Schema::create('gimnasio.membresia_sedes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('membresia_id');
                $table->unsignedBigInteger('sede_id');
                $table->boolean('es_principal')->default(false);
                $table->boolean('activo')->default(true);
                $table->timestamps();

                $table->foreign('membresia_id')->references('id')->on('gimnasio.membresias')->cascadeOnDelete();
                $table->foreign('sede_id')->references('id_sede')->on('institucional.sedes')->restrictOnDelete();
                $table->unique(['membresia_id', 'sede_id']);
                $table->index(['sede_id', 'activo']);
            });
        }

        DB::table('gimnasio.membresias')
            ->whereNotNull('sede_id')
            ->orderBy('id')
            ->chunkById(200, function ($membresias): void {
                foreach ($membresias as $membresia) {
                    DB::table('gimnasio.membresia_sedes')->updateOrInsert(
                        ['membresia_id' => $membresia->id, 'sede_id' => $membresia->sede_id],
                        ['es_principal' => true, 'activo' => true, 'updated_at' => now(), 'created_at' => now()],
                    );
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('gimnasio.membresia_sedes');

        Schema::table('gimnasio.planes', function (Blueprint $table): void {
            if (Schema::hasColumn('gimnasio.planes', 'requiere_entrenador')) {
                $table->dropColumn('requiere_entrenador');
            }
        });
    }
};
