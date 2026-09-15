<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('seguridad.cpu_userrole')->updateOrInsert(
            ['role' => 'RESPONSABLE'],
            [
                'activo' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        $rolId = DB::table('seguridad.cpu_userrole')
            ->where('role', 'RESPONSABLE')
            ->value('id_userrole');

        if (! $rolId) {
            return;
        }

        $tieneUsuarios = DB::table('seguridad.users')
            ->where('usr_tipo', $rolId)
            ->exists();

        if (! $tieneUsuarios) {
            DB::table('seguridad.cpu_userrole')
                ->where('id_userrole', $rolId)
                ->delete();
        }
    }
};
