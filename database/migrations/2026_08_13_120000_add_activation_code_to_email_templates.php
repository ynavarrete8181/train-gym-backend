<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('notificaciones.plantillas')
            ->whereIn('tipo', ['ACCESO', 'RESTABLECIMIENTO'])
            ->orderBy('id')
            ->get()
            ->each(function (object $plantilla): void {
                if (str_contains((string) $plantilla->cuerpo_html, '{{codigo_activacion}}')) {
                    return;
                }

                $variables = is_string($plantilla->variables) ? json_decode($plantilla->variables, true) : (array) $plantilla->variables;
                $variables[] = 'codigo_activacion';

                DB::table('notificaciones.plantillas')->where('id', $plantilla->id)->update([
                    'cuerpo_html' => $plantilla->cuerpo_html.'<p>Código de verificación: <strong style="font-size:20px;letter-spacing:2px">{{codigo_activacion}}</strong></p>',
                    'cuerpo_texto' => trim((string) $plantilla->cuerpo_texto)."\n\nCódigo de verificación: {{codigo_activacion}}",
                    'variables' => json_encode(array_values(array_unique($variables))),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void {}
};
