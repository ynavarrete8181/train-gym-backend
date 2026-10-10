<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS logs_excepciones_created_at_idx ON logs.excepciones (created_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS logs_integraciones_created_at_idx ON logs.integraciones (created_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS logs_integraciones_status_created_at_idx ON logs.integraciones (status_code, created_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS logs.logs_excepciones_created_at_idx');
        DB::statement('DROP INDEX IF EXISTS logs.logs_integraciones_created_at_idx');
        DB::statement('DROP INDEX IF EXISTS logs.logs_integraciones_status_created_at_idx');
    }
};
