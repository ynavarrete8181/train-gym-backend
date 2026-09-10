<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gimnasio.horario_bloques')) {
            Schema::create('gimnasio.horario_bloques', function (Blueprint $table): void {
                $table->id();
                $table->string('nombre', 150)->nullable();
                $table->foreignId('servicio_id')->constrained('gimnasio.servicios')->restrictOnDelete();
                $table->boolean('activo')->default(true);
                $table->timestamps();
            });
        }

        Schema::table('gimnasio.horarios_servicio', function (Blueprint $table): void {
            if (! Schema::hasColumn('gimnasio.horarios_servicio', 'horario_bloque_id')) {
                $table->foreignId('horario_bloque_id')->nullable()->after('id')->constrained('gimnasio.horario_bloques')->nullOnDelete();
            }
        });

        $horarios = DB::table('gimnasio.horarios_servicio')
            ->whereNull('horario_bloque_id')
            ->orderBy('id')
            ->get();

        $bloques = [];

        foreach ($horarios as $horario) {
            $clave = implode('|', [
                $horario->nombre ?? '',
                $horario->servicio_id,
                $horario->hora_inicio,
                $horario->hora_fin,
                $horario->capacidad,
                $horario->activo ? '1' : '0',
            ]);

            if (! isset($bloques[$clave])) {
                $bloques[$clave] = DB::table('gimnasio.horario_bloques')->insertGetId([
                    'nombre' => $horario->nombre,
                    'servicio_id' => $horario->servicio_id,
                    'activo' => $horario->activo,
                    'created_at' => $horario->created_at ?? now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('gimnasio.horarios_servicio')
                ->where('id', $horario->id)
                ->update(['horario_bloque_id' => $bloques[$clave]]);
        }
    }

    public function down(): void
    {
        Schema::table('gimnasio.horarios_servicio', function (Blueprint $table): void {
            if (Schema::hasColumn('gimnasio.horarios_servicio', 'horario_bloque_id')) {
                $table->dropConstrainedForeignId('horario_bloque_id');
            }
        });

        Schema::dropIfExists('gimnasio.horario_bloques');
    }
};
