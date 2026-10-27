<?php

namespace App\Services\Seguridad;

use Illuminate\Support\Facades\DB;

/**
 * Inventario de perfiles operativos. No concede roles ni permisos.
 * Una persona puede ser cliente y entrenador simultáneamente.
 */
class PerfilesPersonaService
{
    public function consultar(int $personaId): array
    {
        $persona = DB::table('personas.personas')->where('id', $personaId)->first(['id']);
        if (! $persona) {
            return ['persona_id' => $personaId, 'existe' => false, 'cliente' => false,
                'usuario_id' => null, 'entrenador' => false, 'rol_principal' => null];
        }

        $usuario = DB::table('seguridad.users as u')
            ->leftJoin('seguridad.cpu_userrole as r', 'r.id_userrole', '=', 'u.usr_tipo')
            ->where('u.persona_id', $personaId)
            ->first(['u.id', 'r.role']);

        return [
            'persona_id' => $personaId,
            'existe' => true,
            'cliente' => DB::table('clientes.deportistas')->where('persona_id', $personaId)->exists(),
            'usuario_id' => $usuario?->id,
            'entrenador' => $usuario !== null && DB::table('gimnasio.entrenadores')
                ->where('usuario_id', $usuario->id)->exists(),
            'rol_principal' => $usuario?->role,
        ];
    }
}
