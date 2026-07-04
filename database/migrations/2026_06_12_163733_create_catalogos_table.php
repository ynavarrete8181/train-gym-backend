<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public.catalogos', function (Blueprint $table) {
            $table->id();
            $table->string('grupo', 50);
            $table->string('codigo', 50);
            $table->string('nombre', 100);
            $table->string('valor_adicional', 255)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['grupo', 'codigo']);
        });

        // Seed default catalogs
        DB::table('public.catalogos')->insert([
            // Estados de Registro
            ['grupo' => 'ESTADO_REGISTRO', 'codigo' => 'ACTIVO', 'nombre' => 'Activo', 'valor_adicional' => '🟢', 'created_at' => now(), 'updated_at' => now()],
            ['grupo' => 'ESTADO_REGISTRO', 'codigo' => 'INACTIVO', 'nombre' => 'Inactivo', 'valor_adicional' => '🔴', 'created_at' => now(), 'updated_at' => now()],
            ['grupo' => 'ESTADO_REGISTRO', 'codigo' => 'CANCELADO', 'nombre' => 'Cancelado', 'valor_adicional' => '⚫', 'created_at' => now(), 'updated_at' => now()],
            ['grupo' => 'ESTADO_REGISTRO', 'codigo' => 'SUSPENDIDO', 'nombre' => 'Suspendido', 'valor_adicional' => '🟡', 'created_at' => now(), 'updated_at' => now()],

            // Estados de Pago / Membresía
            ['grupo' => 'ESTADO_PAGO', 'codigo' => 'PAGADO', 'nombre' => 'Pagado', 'valor_adicional' => '🟢', 'created_at' => now(), 'updated_at' => now()],
            ['grupo' => 'ESTADO_PAGO', 'codigo' => 'PENDIENTE', 'nombre' => 'Pendiente', 'valor_adicional' => '🟡', 'created_at' => now(), 'updated_at' => now()],
            ['grupo' => 'ESTADO_PAGO', 'codigo' => 'VENCIDO', 'nombre' => 'Vencido', 'valor_adicional' => '🔴', 'created_at' => now(), 'updated_at' => now()],
            ['grupo' => 'ESTADO_PAGO', 'codigo' => 'ANULADO', 'nombre' => 'Anulado', 'valor_adicional' => '⚫', 'created_at' => now(), 'updated_at' => now()],

            // Métodos de Pago
            ['grupo' => 'METODO_PAGO', 'codigo' => 'EFECTIVO', 'nombre' => 'Efectivo', 'valor_adicional' => '💵', 'created_at' => now(), 'updated_at' => now()],
            ['grupo' => 'METODO_PAGO', 'codigo' => 'TRANSFERENCIA', 'nombre' => 'Transferencia', 'valor_adicional' => '📱', 'created_at' => now(), 'updated_at' => now()],
            ['grupo' => 'METODO_PAGO', 'codigo' => 'TARJETA', 'nombre' => 'Tarjeta Crédito/Débito', 'valor_adicional' => '💳', 'created_at' => now(), 'updated_at' => now()],
            ['grupo' => 'METODO_PAGO', 'codigo' => 'CHEQUE', 'nombre' => 'Cheque', 'valor_adicional' => '✍️', 'created_at' => now(), 'updated_at' => now()],

            // Niveles de Rendimiento / Resultados de Evaluaciones
            ['grupo' => 'NIVEL_RENDIMIENTO', 'codigo' => 'BAJO', 'nombre' => 'Bajo', 'valor_adicional' => '🔴', 'created_at' => now(), 'updated_at' => now()],
            ['grupo' => 'NIVEL_RENDIMIENTO', 'codigo' => 'MEDIO', 'nombre' => 'Medio', 'valor_adicional' => '🟡', 'created_at' => now(), 'updated_at' => now()],
            ['grupo' => 'NIVEL_RENDIMIENTO', 'codigo' => 'ALTO', 'nombre' => 'Alto', 'valor_adicional' => '🟢', 'created_at' => now(), 'updated_at' => now()],
            ['grupo' => 'NIVEL_RENDIMIENTO', 'codigo' => 'EXCELENTE', 'nombre' => 'Excelente', 'valor_adicional' => '🏆', 'created_at' => now(), 'updated_at' => now()],
            ['grupo' => 'NIVEL_RENDIMIENTO', 'codigo' => 'MEJORO_TECNICA', 'nombre' => 'Mejoró Técnica', 'valor_adicional' => '💪', 'created_at' => now(), 'updated_at' => now()],

            // Tipos de Evaluaciones
            ['grupo' => 'TIPO_EVALUACION', 'codigo' => 'CORPORAL', 'nombre' => 'Corporal', 'valor_adicional' => '⚖️', 'created_at' => now(), 'updated_at' => now()],
            ['grupo' => 'TIPO_EVALUACION', 'codigo' => 'FUNCIONAL', 'nombre' => 'Funcional', 'valor_adicional' => '🏃', 'created_at' => now(), 'updated_at' => now()],
            ['grupo' => 'TIPO_EVALUACION', 'codigo' => 'MOVILIDAD', 'nombre' => 'Movilidad', 'valor_adicional' => '🧘', 'created_at' => now(), 'updated_at' => now()],
            ['grupo' => 'TIPO_EVALUACION', 'codigo' => 'DEPORTIVA', 'nombre' => 'Deportiva', 'valor_adicional' => '⚽', 'created_at' => now(), 'updated_at' => now()],
            ['grupo' => 'TIPO_EVALUACION', 'codigo' => 'REHABILITACION', 'nombre' => 'Rehabilitación', 'valor_adicional' => '🩹', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('public.catalogos');
    }
};
