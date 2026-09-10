<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Migración recuperada: el archivo original se perdió del disco (probablemente
 * borrado por error en algún momento), pero Postgres seguía esperando este
 * timestamp en la lista de migraciones pendientes, lo que bloqueaba
 * "php artisan migrate" por completo (Laravel exige todos los archivos
 * pendientes antes de correr cualquiera). Ningún archivo del proyecto hace
 * referencia a "liquidaciones_lotes" (verificado con grep), así que se
 * recrea como no-op para desbloquear las migraciones reales sin inventar
 * una tabla que nada usa. Si en el futuro se necesita una tabla de
 * liquidaciones, se debe crear con una migración nueva.
 */
return new class extends Migration
{
    public function up(): void
    {
        // No-op: la tabla original nunca llegó a usarse en el código actual.
    }

    public function down(): void
    {
        // No-op.
    }
};
