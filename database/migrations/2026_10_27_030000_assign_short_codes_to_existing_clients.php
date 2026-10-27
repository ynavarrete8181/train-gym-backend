<?php

use App\Services\Gimnasio\CodigoClienteServicio;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            if (! Schema::hasTable('clientes.deportista_codigos_historicos')) {
                Schema::create('clientes.deportista_codigos_historicos', function (Blueprint $table): void {
                    $table->id();
                    $table->unsignedBigInteger('deportista_id');
                    $table->string('codigo_anterior', 40)->unique();
                    $table->string('codigo_nuevo', 5)->unique();
                    $table->timestamp('fecha_cambio');
                    $table->index('deportista_id');
                });
            }

            $generador = app(CodigoClienteServicio::class);
            DB::table('clientes.deportistas')
                ->select(['id', 'codigo_deportista'])
                ->orderBy('id')
                ->chunkById(200, function ($clientes) use ($generador): void {
                    foreach ($clientes as $cliente) {
                        // No repetir la asignación cuando existe un registro de trazabilidad.
                        if (DB::table('clientes.deportista_codigos_historicos')
                            ->where('deportista_id', $cliente->id)->exists()) {
                            continue;
                        }

                        $nuevo = $generador->generar();
                        DB::table('clientes.deportista_codigos_historicos')->insert([
                            'deportista_id' => $cliente->id,
                            'codigo_anterior' => $cliente->codigo_deportista,
                            'codigo_nuevo' => $nuevo,
                            'fecha_cambio' => now(),
                        ]);

                        DB::table('clientes.deportistas')->where('id', $cliente->id)
                            ->update(['codigo_deportista' => $nuevo, 'updated_at' => now()]);
                    }
                });
        });
    }

    public function down(): void
    {
        // No revertir códigos potencialmente impresos en tarjetas o utilizados en accesos.
        // Se conserva la trazabilidad del código anterior.
    }
};
