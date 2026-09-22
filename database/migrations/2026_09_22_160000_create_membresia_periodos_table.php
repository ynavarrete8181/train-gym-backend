<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gimnasio.membresia_periodos')) {
            Schema::create('gimnasio.membresia_periodos', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('membresia_id')->constrained('gimnasio.membresias')->cascadeOnDelete();
                $table->unsignedInteger('numero_periodo');
                $table->date('fecha_inicio');
                $table->date('fecha_fin');
                $table->decimal('precio', 12, 2)->default(0);
                $table->string('estado', 30)->default('PENDIENTE_PAGO');
                $table->unsignedBigInteger('venta_id')->nullable();
                $table->timestamp('generado_at')->nullable();
                $table->timestamps();

                $table->foreign('venta_id')->references('id')->on('ventas.ventas')->nullOnDelete();
                $table->unique(['membresia_id', 'numero_periodo']);
                $table->index(['membresia_id', 'fecha_inicio', 'fecha_fin']);
            });
        }

        $membresias = DB::table('gimnasio.membresias')->orderBy('id')->get();

        foreach ($membresias as $membresia) {
            $existe = DB::table('gimnasio.membresia_periodos')
                ->where('membresia_id', $membresia->id)
                ->exists();

            if ($existe) {
                continue;
            }

            $ventaId = DB::table('ventas.ventas')
                ->where('membresia_id', $membresia->id)
                ->where('estado', '!=', 'ANULADA')
                ->orderByDesc('id')
                ->value('id');

            DB::table('gimnasio.membresia_periodos')->insert([
                'membresia_id' => $membresia->id,
                'numero_periodo' => 1,
                'fecha_inicio' => $membresia->fecha_inicio,
                'fecha_fin' => $membresia->fecha_fin,
                'precio' => $membresia->precio_aplicado ?? 0,
                'estado' => $membresia->estado ?? 'PENDIENTE_PAGO',
                'venta_id' => $ventaId,
                'generado_at' => $membresia->created_at ?? now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gimnasio.membresia_periodos');
    }
};
