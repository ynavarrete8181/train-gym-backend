<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La migración original omitía crear la tabla cuando ya existía, por lo que algunas
     * bases conservan la estructura heredada y no pueden registrar eventos. Solo agrega
     * lo que falta: las columnas heredadas se conservan.
     */
    public function up(): void
    {
        $faltantes = [
            'usuario_nombre' => fn (Blueprint $t) => $t->string('usuario_nombre', 160)->nullable(),
            'rol' => fn (Blueprint $t) => $t->string('rol', 60)->nullable(),
            'tabla' => fn (Blueprint $t) => $t->string('tabla', 120)->nullable(),
            'registro_id' => fn (Blueprint $t) => $t->string('registro_id', 40)->nullable(),
            'datos_antes' => fn (Blueprint $t) => $t->jsonb('datos_antes')->nullable(),
            'datos_despues' => fn (Blueprint $t) => $t->jsonb('datos_despues')->nullable(),
        ];

        foreach ($faltantes as $columna => $definir) {
            if (! Schema::hasColumn('auditoria.eventos', $columna)) {
                Schema::table('auditoria.eventos', $definir);
            }
        }

        if (Schema::hasColumn('auditoria.eventos', 'origen')) {
            DB::statement("ALTER TABLE auditoria.eventos ALTER COLUMN origen SET DEFAULT 'SISTEMA'");
        }

        // Hay acciones más largas que el límite original de 30 caracteres.
        DB::statement('ALTER TABLE auditoria.eventos ALTER COLUMN accion TYPE varchar(80)');
        DB::statement('ALTER TABLE auditoria.eventos ALTER COLUMN descripcion TYPE text');
        DB::statement('CREATE INDEX IF NOT EXISTS auditoria_eventos_tabla_registro_idx ON auditoria.eventos (tabla, registro_id)');
    }

    public function down(): void
    {
        // No se revierte: eliminar estas columnas borraría eventos ya registrados.
    }
};
