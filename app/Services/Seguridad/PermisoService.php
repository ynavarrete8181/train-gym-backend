<?php

namespace App\Services\Seguridad;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class PermisoService
{
    public function usuarioTieneAlgunaFuncion(User $usuario, array $codigos): bool
    {
        $codigosNormalizados = collect($codigos)
            ->map(fn (string $codigo): string => mb_strtoupper(trim($codigo)))
            ->filter()
            ->unique()
            ->values();

        if ($codigosNormalizados->isEmpty()) {
            return false;
        }

        return DB::table('seguridad.cpu_userfunction')
            ->where('id_users', $usuario->id)
            ->where('activo', true)
            ->whereIn('id_menu', $codigosNormalizados)
            ->exists();
    }
}
