<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('clientes.valoracion_mediciones', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('registro_id')->unique();
            $table->unsignedBigInteger('deportista_id');
            foreach (['peso_kg','talla_cm','cintura_cm','grasa_corporal_pct','imc','indice_cintura_altura','masa_magra_kg'] as $campo) {
                $table->decimal($campo, 12, 4)->nullable();
            }
            $table->timestamps();
            $table->foreign('registro_id')->references('id')->on('clientes.valoracion_registros')->restrictOnDelete();
            $table->foreign('deportista_id')->references('id')->on('clientes.deportistas')->restrictOnDelete();
            $table->index(['deportista_id','registro_id'], 'valoracion_mediciones_deportista_idx');
        });
        Schema::create('clientes.valoracion_criterios', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('registro_id');
            $table->unsignedBigInteger('deportista_id');
            $table->string('seccion', 30);
            $table->string('campo', 80);
            $table->string('valor', 100);
            $table->timestamps();
            $table->unique(['registro_id','campo'], 'valoracion_criterios_registro_campo_uk');
            $table->index(['seccion','campo','valor'], 'valoracion_criterios_reporte_idx');
            $table->foreign('registro_id')->references('id')->on('clientes.valoracion_registros')->restrictOnDelete();
            $table->foreign('deportista_id')->references('id')->on('clientes.deportistas')->restrictOnDelete();
        });

        $campos = ['actividad_fisica','objetivo_codigo','unidad_medida','prioridad','estado_objetivo',
            'condicion_fisica','movilidad_general','experiencia_entrenamiento','requiere_adaptacion','frecuencia_seguimiento'];
        DB::table('clientes.valoracion_registros')->orderBy('id')->chunk(100, function ($registros) use ($campos): void {
            foreach ($registros as $registro) {
                $datos = json_decode($registro->datos ?: '{}', true) ?: [];
                if ($registro->seccion === 'evaluacion') {
                    $valores = [];
                    foreach (['peso_kg','talla_cm','cintura_cm','grasa_corporal_pct','imc','indice_cintura_altura','masa_magra_kg'] as $campo) {
                        $valor = $datos[$campo] ?? null;
                        $valores[$campo] = is_numeric($valor) && is_finite((float) $valor) ? $valor : null;
                    }
                    if ($valores['peso_kg'] > 0 && $valores['talla_cm'] > 0) $valores['imc'] = round($valores['peso_kg'] / (($valores['talla_cm'] / 100) ** 2), 2);
                    if ($valores['cintura_cm'] > 0 && $valores['talla_cm'] > 0) $valores['indice_cintura_altura'] = round($valores['cintura_cm'] / $valores['talla_cm'], 4);
                    if ($valores['peso_kg'] > 0 && $valores['grasa_corporal_pct'] !== null && $valores['grasa_corporal_pct'] >= 0 && $valores['grasa_corporal_pct'] <= 100) {
                        $valores['masa_magra_kg'] = round($valores['peso_kg'] * (1 - $valores['grasa_corporal_pct'] / 100), 2);
                    }
                    DB::table('clientes.valoracion_mediciones')->insert([
                        'registro_id'=>$registro->id, 'deportista_id'=>$registro->deportista_id,
                        ...$valores, 'created_at'=>now(), 'updated_at'=>now(),
                    ]);
                }
                foreach ($campos as $campo) {
                    if (!isset($datos[$campo]) || !is_scalar($datos[$campo]) || trim((string) $datos[$campo]) === '') continue;
                    DB::table('clientes.valoracion_criterios')->insert([
                        'registro_id'=>$registro->id,'deportista_id'=>$registro->deportista_id,
                        'seccion'=>$registro->seccion,'campo'=>$campo,'valor'=>(string) $datos[$campo],
                        'created_at'=>now(),'updated_at'=>now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes.valoracion_criterios');
        Schema::dropIfExists('clientes.valoracion_mediciones');
    }
};
