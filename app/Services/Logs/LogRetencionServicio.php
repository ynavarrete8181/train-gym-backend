<?php

namespace App\Services\Logs;

use Illuminate\Support\Facades\DB;

class LogRetencionServicio
{
    public function limpiar(): array
    {
        $resultado = [
            'eventos_info' => 0,
            'eventos_warning' => 0,
            'eventos_error' => 0,
            'excepciones' => 0,
            'integraciones_ok' => 0,
            'integraciones_error' => 0,
        ];

        DB::transaction(function () use (&$resultado): void {
            // Las excepciones se eliminan antes que el evento para evitar huérfanos
            // cuando el FK esté configurado con nullOnDelete.
            $resultado['excepciones'] = DB::table('logs.excepciones')
                ->where('created_at', '<', now()->subDays(90))
                ->delete();

            $resultado['eventos_info'] = DB::table('logs.eventos')
                ->where('nivel', 'INFO')
                ->where('created_at', '<', now()->subDays(30))
                ->delete();

            $resultado['eventos_warning'] = DB::table('logs.eventos')
                ->where('nivel', 'WARNING')
                ->where('created_at', '<', now()->subDays(60))
                ->delete();

            $resultado['eventos_error'] = DB::table('logs.eventos')
                ->where('nivel', 'ERROR')
                ->where('created_at', '<', now()->subDays(90))
                ->delete();

            $resultado['integraciones_ok'] = DB::table('logs.integraciones')
                ->whereNull('error')
                ->where(function ($query): void {
                    $query->whereNull('status_code')
                        ->orWhere('status_code', '<', 400);
                })
                ->where('created_at', '<', now()->subDays(30))
                ->delete();

            $resultado['integraciones_error'] = DB::table('logs.integraciones')
                ->where(function ($query): void {
                    $query->whereNotNull('error')
                        ->orWhere('status_code', '>=', 400);
                })
                ->where('created_at', '<', now()->subDays(90))
                ->delete();
        });

        $resultado['total'] = array_sum($resultado);

        return $resultado;
    }
}
