<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('membresias.motivos_ciclo_vida')) {
            Schema::create('membresias.motivos_ciclo_vida', function (Blueprint $table): void {
                $table->id();
                $table->string('codigo', 60)->unique();
                $table->string('nombre', 160);
                $table->string('operacion', 32);
                $table->text('descripcion')->nullable();
                $table->boolean('activo')->default(true);
                $table->unsignedSmallInteger('orden')->default(100);
                $table->timestamps();
                $table->index(['operacion', 'activo', 'orden'], 'motivos_ciclo_operacion_estado_idx');
            });
        }

        // Actualización idempotente; jamás borra motivos creados por usuarios.
        $catalogo = [
            ['VACACIONES', 'Vacaciones', 'CONGELAR', 10],
            ['VIAJE', 'Viaje laboral o estudios', 'CONGELAR', 20],
            ['LESION', 'Lesión deportiva', 'CONGELAR', 30],
            ['ENFERMEDAD', 'Enfermedad y recuperación', 'CONGELAR', 40],
            ['EMBARAZO', 'Embarazo o posparto', 'CONGELAR', 50],
            ['FUERZA_MAYOR', 'Fuerza mayor', 'CONGELAR', 60],
            ['PERSONAL', 'Motivo personal', 'CONGELAR', 70],
            ['ECONOMICO', 'Dificultad económica', 'CONGELAR', 80],
            ['REGRESO', 'Regreso del deportista', 'REACTIVAR', 10],
            ['REGRESO_ANTICIPADO', 'Regreso anticipado', 'REACTIVAR', 20],
            ['FIN_AUTORIZADO', 'Fin del período autorizado', 'REACTIVAR', 30],
            ['RETIRO_VOLUNTARIO', 'Retiro voluntario', 'CERRAR', 10],
            ['FIN_CONTRATO', 'Fin contractual', 'CERRAR', 20],
            ['INCUMPLIMIENTO', 'Incumplimiento contractual', 'CERRAR', 30],
            ['ERROR_ADMINISTRATIVO', 'Corrección administrativa', 'REABRIR', 10],
            ['REAPERTURA_EXCEPCIONAL', 'Reapertura excepcional', 'REABRIR', 20],
        ];
        foreach ($catalogo as [$codigo, $nombre, $operacion, $orden]) {
            DB::table('membresias.motivos_ciclo_vida')->insertOrIgnore([
                'codigo' => $codigo, 'nombre' => $nombre,
                'operacion' => $operacion, 'orden' => $orden,
                'activo' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Catálogo nuevo; rollback explícito únicamente en entornos de pruebas.
        Schema::dropIfExists('membresias.motivos_ciclo_vida');
    }
};
