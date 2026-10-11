<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notificaciones.campania_destinatarios', function (Blueprint $table): void {
            $table->string('estado_app', 20)->default('OMITIDO');
            $table->text('error_app')->nullable();
        });

        DB::statement(<<<'SQL'
            UPDATE notificaciones.campania_destinatarios AS d
            SET estado_app = CASE WHEN c.estado = 'BORRADOR' THEN 'PENDIENTE' ELSE 'ENVIADA' END
            FROM notificaciones.campanias AS c
            WHERE c.id = d.campania_id
              AND c.publicar_inicio_app = TRUE
              AND d.usuario_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('notificaciones.campania_destinatarios', function (Blueprint $table): void {
            $table->dropColumn(['estado_app', 'error_app']);
        });
    }
};
