<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! App::environment(['local', 'testing'])) {
            return;
        }

        DB::transaction(function (): void {
            $tieneMembresiaEnAsignaciones = DB::table('information_schema.columns')
                ->where('table_schema', 'gimnasio')
                ->where('table_name', 'asignaciones_entrenador_cliente')
                ->where('column_name', 'membresia_id')
                ->exists();

            if ($tieneMembresiaEnAsignaciones) {
                DB::table('gimnasio.asignaciones_entrenador_cliente')
                    ->whereNotNull('membresia_id')
                    ->update([
                        'membresia_id' => null,
                        'updated_at' => now(),
                    ]);
            }

            DB::table('gimnasio.membresias')->delete();

            DB::statement("SELECT setval(pg_get_serial_sequence('gimnasio.membresias', 'id'), 1, false)");
        });
    }

    public function down(): void
    {
        // Limpieza intencional de datos de prueba. No es reversible.
    }
};
