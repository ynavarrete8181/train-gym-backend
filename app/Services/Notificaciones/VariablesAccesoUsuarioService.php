<?php

namespace App\Services\Notificaciones;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class VariablesAccesoUsuarioService
{
    public function obtener(User $usuario, string $urlActivacion, mixed $expira, string $codigoActivacion): array
    {
        $contextos = DB::table('institucional.usuario_contexto as uc')
            ->join('institucional.contextos as x', 'x.id_contexto', '=', 'uc.id_contexto')
            ->join('institucional.sedes as s', 's.id_sede', '=', 'x.id_sede')
            ->join('institucional.unidades as u', 'u.id_unidad', '=', 'x.id_unidad')
            ->leftJoin('institucional.carreras_areas as c', 'c.id_carrera_area', '=', 'x.id_carrera_area')
            ->where('uc.id_usuario', $usuario->id)->where('uc.activo', true)
            ->orderByDesc('uc.principal')->get(['s.nombre as sede', 'u.nombre as unidad', 'c.nombre as carrera_area']);
        $rol = DB::table('seguridad.cpu_userrole')->where('id_userrole', $usuario->usr_tipo)->value('role');
        $unicos = fn (string $campo) => $contextos->pluck($campo)->filter()->unique()->implode(', ');

        $carreraArea = $unicos('carrera_area') ?: 'Toda la unidad';

        return [
            'nombre_sistema' => config('app.name'), 'nombre_usuario' => $usuario->name,
            'email_usuario' => $usuario->email, 'cedula_usuario' => $usuario->cedula ?: 'No registrada',
            'roles_usuario' => $rol ?: 'Sin rol asignado', 'sede_usuario' => $unicos('sede') ?: 'No asignada',
            'facultad_direccion_usuario' => $unicos('unidad') ?: 'No asignada',
            'carrera_area_usuario' => $carreraArea, 'carrera_usuario' => $carreraArea,
            'url_activacion' => $urlActivacion, 'fecha_expiracion' => $expira->copy()->timezone(config('services.notificaciones.timezone'))->format('d/m/Y H:i'),
            'codigo_activacion' => $codigoActivacion,
            'url_sistema' => rtrim((string) config('services.notificaciones.frontend_url'), '/'),
            'password_temporal' => 'Se establece al activar la cuenta',
            'fecha_creacion' => optional($usuario->created_at)?->copy()->timezone(config('services.notificaciones.timezone'))->format('d/m/Y H:i') ?: now()->timezone(config('services.notificaciones.timezone'))->format('d/m/Y H:i'),
        ];
    }
}
