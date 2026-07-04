<?php

namespace App\Queries\Seguridad;

use Illuminate\Support\Facades\DB;

class UsuarioQuery
{
    public function listar(array $filters = []): array
    {
        $query = DB::table('seguridad.usuarios as u')
            ->leftJoin('core.personas as p', 'p.id', '=', 'u.persona_id')
            ->selectRaw("
                u.id,
                u.gimnasio_id,
                u.persona_id,
                u.email,
                u.estado,
                u.fecha_baja,
                u.foto_perfil_url,
                u.created_at,
                u.updated_at,
                COALESCE(u.cedula, p.numero_identificacion) as cedula,
                p.nombres,
                p.apellidos
            ");

        if (!empty($filters['buscar'])) {
            $buscar = '%' . trim((string) $filters['buscar']) . '%';
            $query->where(function ($q) use ($buscar) {
                $q->where('u.email', 'like', $buscar)
                    ->orWhere('u.cedula', 'like', $buscar)
                    ->orWhere('p.numero_identificacion', 'like', $buscar)
                    ->orWhere('p.nombres', 'like', $buscar)
                    ->orWhere('p.apellidos', 'like', $buscar);
            });
        }

        if (!empty($filters['estado'])) {
            $query->where('u.estado', $filters['estado']);
        }

        if (!empty($filters['rol_id'])) {
            $rolId = (int) $filters['rol_id'];
            $query->whereExists(function ($q) use ($rolId) {
                $q->select(DB::raw(1))
                    ->from('seguridad.usuario_roles as ur')
                    ->whereColumn('ur.usuario_id', 'u.id')
                    ->where('ur.rol_id', $rolId);
            });
        }

        $usuarios = $query
            ->orderBy('p.nombres')
            ->orderBy('p.apellidos')
            ->orderBy('u.email')
            ->get();

        return $usuarios->map(fn ($usuario) => $this->mapUsuario($usuario))->all();
    }

    public function obtenerPorId(int $id): ?array
    {
        $row = DB::table('seguridad.usuarios as u')
            ->leftJoin('core.personas as p', 'p.id', '=', 'u.persona_id')
            ->selectRaw("
                u.id,
                u.gimnasio_id,
                u.persona_id,
                u.email,
                u.estado,
                u.fecha_baja,
                u.foto_perfil_url,
                u.created_at,
                u.updated_at,
                COALESCE(u.cedula, p.numero_identificacion) as cedula,
                p.nombres,
                p.apellidos
            ")
            ->where('u.id', $id)
            ->first();

        return $row ? $this->mapUsuario($row) : null;
    }

    public function roles(): array
    {
        return DB::table('seguridad.roles')
            ->select('id', 'codigo', 'nombre', 'descripcion', 'activo')
            ->orderBy('nombre')
            ->get()
            ->map(fn ($rol) => [
                'id' => (int) $rol->id,
                'codigo' => $rol->codigo,
                'nombre' => $rol->nombre,
                'descripcion' => $rol->descripcion,
                'activo' => (bool) $rol->activo,
            ])
            ->all();
    }

    public function personasDisponibles(): array
    {
        return DB::table('core.personas as p')
            ->leftJoin('seguridad.usuarios as u', 'u.persona_id', '=', 'p.id')
            ->selectRaw("
                p.id,
                p.numero_identificacion as cedula,
                p.nombres,
                p.apellidos,
                p.email,
                u.id as usuario_id,
                u.email as usuario_email,
                u.estado as usuario_estado
            ")
            ->orderBy('p.nombres')
            ->orderBy('p.apellidos')
            ->limit(300)
            ->get()
            ->map(fn ($persona) => [
                'id' => (int) $persona->id,
                'cedula' => $persona->cedula,
                'nombre_completo' => trim(($persona->nombres ?? '') . ' ' . ($persona->apellidos ?? '')),
                'email' => $persona->email,
                'usuario_id' => $persona->usuario_id ? (int) $persona->usuario_id : null,
                'usuario_email' => $persona->usuario_email,
                'usuario_estado' => $persona->usuario_estado,
            ])
            ->all();
    }

    public function sedes(): array
    {
        return DB::table('core.sedes')
            ->select('id', 'nombre', 'direccion', 'activa')
            ->where('activa', true)
            ->orderBy('nombre')
            ->get()
            ->map(fn ($sede) => [
                'id' => (int) $sede->id,
                'nombre' => $sede->nombre,
                'direccion' => $sede->direccion,
                'activa' => (bool) $sede->activa,
            ])
            ->all();
    }

    private function mapUsuario(object $usuario): array
    {
        $roles = DB::table('seguridad.usuario_roles as ur')
            ->join('seguridad.roles as r', 'r.id', '=', 'ur.rol_id')
            ->where('ur.usuario_id', $usuario->id)
            ->orderBy('r.nombre')
            ->get(['r.id', 'r.codigo', 'r.nombre'])
            ->map(fn ($rol) => [
                'id' => (int) $rol->id,
                'codigo' => $rol->codigo,
                'nombre' => $rol->nombre,
            ])
            ->all();

        $sedes = DB::table('seguridad.usuario_sedes as us')
            ->join('core.sedes as s', 's.id', '=', 'us.sede_id')
            ->where('us.usuario_id', $usuario->id)
            ->where('us.activo', true)
            ->orderBy('s.nombre')
            ->get(['s.id', 's.nombre'])
            ->map(fn ($sede) => [
                'id' => (int) $sede->id,
                'nombre' => $sede->nombre,
            ])
            ->all();

        return [
            'id' => (int) $usuario->id,
            'gimnasio_id' => $usuario->gimnasio_id ? (int) $usuario->gimnasio_id : null,
            'persona_id' => $usuario->persona_id ? (int) $usuario->persona_id : null,
            'email' => $usuario->email,
            'estado' => $usuario->estado,
            'fecha_baja' => $usuario->fecha_baja,
            'foto_perfil_url' => $usuario->foto_perfil_url,
            'created_at' => $usuario->created_at,
            'updated_at' => $usuario->updated_at,
            'cedula' => $usuario->cedula,
            'nombre_completo' => trim(($usuario->nombres ?? '') . ' ' . ($usuario->apellidos ?? '')) ?: $usuario->email,
            'roles' => $roles,
            'roles_ids' => collect($roles)->pluck('id')->values()->all(),
            'sedes' => $sedes,
            'sedes_ids' => collect($sedes)->pluck('id')->values()->all(),
        ];
    }
}
