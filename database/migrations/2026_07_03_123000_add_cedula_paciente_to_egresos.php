<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!$this->tableExists()) {
            return;
        }

        DB::statement("ALTER TABLE inventarios.egresos ADD COLUMN IF NOT EXISTS ee_cedula_paciente VARCHAR(20)");

        if ($this->tableExists('public.cpu_personas')) {
            DB::statement("
                UPDATE inventarios.egresos e
                SET ee_cedula_paciente = p.cedula
                FROM public.cpu_personas p
                WHERE e.ee_id_paciente = p.id
                  AND COALESCE(NULLIF(TRIM(e.ee_cedula_paciente), ''), '') = ''
                  AND COALESCE(NULLIF(TRIM(p.cedula), ''), '') <> ''
            ");
        }
    }

    public function down(): void
    {
        if ($this->tableExists()) {
            DB::statement("ALTER TABLE inventarios.egresos DROP COLUMN IF EXISTS ee_cedula_paciente");
        }
    }

    private function tableExists(string $table = 'inventarios.egresos'): bool
    {
        $row = DB::selectOne('SELECT to_regclass(?) as table_name', [$table]);

        return !empty($row?->table_name);
    }
};
