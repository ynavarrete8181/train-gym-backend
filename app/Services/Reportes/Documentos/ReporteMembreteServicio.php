<?php

namespace App\Services\Reportes\Documentos;

use Illuminate\Support\Facades\DB;

class ReporteMembreteServicio
{
    public function construir(
        string $titulo,
        string $descripcion,
        array $metadata,
        int $usuarioId,
    ): array {
        $usuario = DB::table('seguridad.users as u')
            ->leftJoin('seguridad.cpu_userrole as r', 'r.id_userrole', '=', 'u.usr_tipo')
            ->where('u.id', $usuarioId)
            ->first(['u.name', 'u.email', 'r.role']);

        return [
            'institucion' => 'REVIVE',
            'subtitulo' => 'REPORTE INSTITUCIONAL',
            'titulo' => $titulo,
            'descripcion' => $descripcion,
            'logo_path' => public_path('brand/revive-logo.jpeg'),
            'generado_por' => $usuario->name ?? 'Usuario',
            'rol' => $usuario->role ?? 'Sin rol',
            'correo' => $usuario->email ?? '',
            'generado_el' => now()->format('d/m/Y H:i:s'),
            'metadata' => $metadata,
        ];
    }
}
