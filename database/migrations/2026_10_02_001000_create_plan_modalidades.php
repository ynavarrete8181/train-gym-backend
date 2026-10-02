<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membresias.planes', function (Blueprint $table): void {
            if (! Schema::hasColumn('membresias.planes', 'requiere_modalidades')) {
                $table->boolean('requiere_modalidades')->default(false)->after('renovable');
            }
        });

        if (! Schema::hasTable('membresias.plan_modalidades')) {
            Schema::create('membresias.plan_modalidades', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('plan_id')->constrained('membresias.planes')->cascadeOnDelete();
                $table->string('codigo', 60);
                $table->string('nombre', 120);
                $table->text('descripcion')->nullable();

                $table->unsignedTinyInteger('dias_por_semana')->nullable();
                $table->unsignedTinyInteger('usos_por_semana')->nullable();
                $table->boolean('uso_ilimitado')->default(false);

                $table->string('tipo_duracion', 20)->default('SEMANAS');
                $table->unsignedSmallInteger('duracion')->default(4);

                $table->decimal('precio_base', 12, 2)->default(0);
                $table->decimal('tarifa_inscripcion', 12, 2)->default(0);

                $table->string('modelo_cobro', 40)->default('FIJO_POR_PERIODO');
                $table->string('momento_cobro', 20)->default('ANTICIPADO');
                $table->boolean('permite_prorrateo')->default(false);
                $table->boolean('permite_extension')->default(true);
                $table->boolean('extension_automatica')->default(false);
                $table->boolean('permite_rollover')->default(false);
                $table->boolean('activo')->default(true);
                $table->timestamps();

                $table->unique(['plan_id', 'codigo']);
                $table->index(['plan_id', 'activo']);
            });
        }

        if (! Schema::hasTable('membresias.plan_modalidad_precios_sede')) {
            Schema::create('membresias.plan_modalidad_precios_sede', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('modalidad_id')->constrained('membresias.plan_modalidades')->cascadeOnDelete();
                $table->unsignedBigInteger('sede_id');
                $table->decimal('precio', 12, 2);
                $table->boolean('activo')->default(true);
                $table->timestamps();

                $table->foreign('sede_id')->references('id_sede')->on('institucional.sedes')->cascadeOnDelete();
                $table->unique(['modalidad_id', 'sede_id']);
                $table->index(['sede_id', 'activo']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('membresias.plan_modalidad_precios_sede');
        Schema::dropIfExists('membresias.plan_modalidades');

        Schema::table('membresias.planes', function (Blueprint $table): void {
            if (Schema::hasColumn('membresias.planes', 'requiere_modalidades')) {
                $table->dropColumn('requiere_modalidades');
            }
        });
    }
};
