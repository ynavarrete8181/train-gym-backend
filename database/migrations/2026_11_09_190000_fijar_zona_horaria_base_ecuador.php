<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Deja la base en hora de Ecuador para toda conexión nueva, no solo para la de Laravel
 * (que ya fija America/Guayaquil en config/database.php). Así los valores por defecto
 * CURRENT_TIMESTAMP, los respaldos y las consultas manuales usan la misma hora.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared("DO \$\$ BEGIN EXECUTE format('ALTER DATABASE %I SET timezone TO %L', current_database(), 'America/Guayaquil'); END \$\$;");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared("DO \$\$ BEGIN EXECUTE format('ALTER DATABASE %I RESET timezone', current_database()); END \$\$;");
    }
};
