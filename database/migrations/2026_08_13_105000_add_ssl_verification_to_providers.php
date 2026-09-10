<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE integraciones.proveedores ADD COLUMN verificar_ssl BOOLEAN NOT NULL DEFAULT TRUE');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE integraciones.proveedores DROP COLUMN IF EXISTS verificar_ssl');
    }
};
