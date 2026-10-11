<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('seguridad.cpu_usermenu')
            ->where('menu', 'Administración')
            ->update([
                'menu' => 'Seguridad',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('seguridad.cpu_usermenu')
            ->where('menu', 'Seguridad')
            ->update([
                'menu' => 'Administración',
                'updated_at' => now(),
            ]);
    }
};
