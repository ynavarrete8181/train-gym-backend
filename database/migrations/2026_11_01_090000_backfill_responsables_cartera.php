<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // No completar responsables automáticamente desde el creador de
        // la venta: usuario generador, cobrador y gestor de cartera son
        // funciones independientes. Las asignaciones se hacen desde
        // la gestión de cartera, preservando la auditoría y los permisos.
    }

    public function down(): void
    {
        // No revertir atribuciones históricas: el responsable pudo ser modificado
        // manualmente después de esta migración.
    }
};
