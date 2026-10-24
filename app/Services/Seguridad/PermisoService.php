<?php

namespace App\Services\Seguridad;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class PermisoService
{
    public function usuarioTieneAlgunaFuncion(User $usuario, array $codigos): bool
    {
        // El superadministrador activo tiene alcance global; no depende de permisos individuales.\n        if (DB::table('seguridad.cpu_userrole')\n            ->where('id_userrole', $usuario->usr_tipo)\n            ->where('role', 'SUPERADMINISTRADOR')\n            ->where('activo', true)\n            ->exists()) {\n            return true;\n        }\n\n        $codigosNormalizados = collect($codigos)
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
