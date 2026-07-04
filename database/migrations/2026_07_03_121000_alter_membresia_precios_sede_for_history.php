<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE IF EXISTS socios.membresia_precios_sede
            ADD COLUMN IF NOT EXISTS vigencia_inicio DATE DEFAULT CURRENT_DATE
        ");

        DB::statement("
            ALTER TABLE IF EXISTS socios.membresia_precios_sede
            ADD COLUMN IF NOT EXISTS vigencia_fin DATE NULL
        ");

        DB::statement("
            DO $$
            DECLARE
                constraint_name TEXT;
            BEGIN
                SELECT conname INTO constraint_name
                FROM pg_constraint
                WHERE conrelid = 'socios.membresia_precios_sede'::regclass
                  AND contype = 'u'
                  AND conkey = ARRAY[
                      (SELECT attnum FROM pg_attribute WHERE attrelid = 'socios.membresia_precios_sede'::regclass AND attname = 'membresia_id'),
                      (SELECT attnum FROM pg_attribute WHERE attrelid = 'socios.membresia_precios_sede'::regclass AND attname = 'sede_id')
                  ];

                IF constraint_name IS NOT NULL THEN
                    EXECUTE format('ALTER TABLE socios.membresia_precios_sede DROP CONSTRAINT %I', constraint_name);
                END IF;
            END $$;
        ");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE IF EXISTS socios.membresia_precios_sede DROP COLUMN IF EXISTS vigencia_fin");
        DB::statement("ALTER TABLE IF EXISTS socios.membresia_precios_sede DROP COLUMN IF EXISTS vigencia_inicio");
    }
};
