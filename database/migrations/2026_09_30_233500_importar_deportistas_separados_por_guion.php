<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Sin operación.
        //
        // Regla corregida:
        // cuando el Excel contiene "DEPORTISTA - REPRESENTANTE", el segundo
        // nombre corresponde al representante legal y no debe crearse como
        // deportista por esta migración.
        //
        // La normalización definitiva se realiza en la migración posterior
        // de representantes legales.
    }

    public function down(): void
    {
        //
    }
};
