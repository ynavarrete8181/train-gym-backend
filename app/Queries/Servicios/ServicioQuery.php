<?php

namespace App\Queries\Servicios;

use Illuminate\Support\Facades\DB;

class ServicioQuery
{
    public function all(): array
    {
        return DB::table('train_gimnasio.tipos_servicios as t')
            ->join('train_gimnasio.categoria_servicios as c', 'c.id', '=', 't.categoria_id')
            ->leftJoin('seguridad.usuarios as au', 'au.id', '=', 't.user_id')
            ->leftJoin('core.personas as p', 'p.id', '=', 'au.persona_id')
            ->selectRaw("
                t.id,
                t.nombre,
                t.descripcion,
                t.breve_desc,
                t.categoria_id,
                t.estado_id,
                t.user_id,
                t.created_at,
                t.updated_at,
                c.nombre as categoria_nombre,
                c.descripcion as categoria_descripcion,
                au.email as usuario_email,
                p.nombres as usuario_nombres,
                p.apellidos as usuario_apellidos,
                CASE WHEN COALESCE(t.estado_id, 0) = 1 THEN 'Activo' ELSE 'Inactivo' END as estado_nombre,
                CASE WHEN COALESCE(t.estado_id, 0) = 1 THEN 'ACTIVO' ELSE 'INACTIVO' END as estado_codigo
            ")
            ->orderByDesc('t.id')
            ->get()
            ->map(fn ($row) => $this->mapRow($row))
            ->all();
    }

    public function find(int $id): ?array
    {
        $row = DB::table('train_gimnasio.tipos_servicios as t')
            ->join('train_gimnasio.categoria_servicios as c', 'c.id', '=', 't.categoria_id')
            ->leftJoin('seguridad.usuarios as au', 'au.id', '=', 't.user_id')
            ->leftJoin('core.personas as p', 'p.id', '=', 'au.persona_id')
            ->selectRaw("
                t.id,
                t.nombre,
                t.descripcion,
                t.breve_desc,
                t.categoria_id,
                t.estado_id,
                t.user_id,
                t.created_at,
                t.updated_at,
                c.nombre as categoria_nombre,
                c.descripcion as categoria_descripcion,
                au.email as usuario_email,
                p.nombres as usuario_nombres,
                p.apellidos as usuario_apellidos,
                CASE WHEN COALESCE(t.estado_id, 0) = 1 THEN 'Activo' ELSE 'Inactivo' END as estado_nombre,
                CASE WHEN COALESCE(t.estado_id, 0) = 1 THEN 'ACTIVO' ELSE 'INACTIVO' END as estado_codigo
            ")
            ->where('t.id', $id)
            ->first();

        return $row ? $this->mapRow($row) : null;
    }

    private function mapRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'nombre' => $row->nombre,
            'descripcion' => $row->descripcion,
            'breve_desc' => $row->breve_desc,
            'categoria_id' => (int) $row->categoria_id,
            'categoria_nombre' => $row->categoria_nombre,
            'categoria_descripcion' => $row->categoria_descripcion,
            'estado_id' => (int) $row->estado_id,
            'activo' => (int) $row->estado_id === 1,
            'estado_nombre' => $row->estado_nombre,
            'estado_codigo' => $row->estado_codigo,
            'user_id' => (int) $row->user_id,
            'usuario_email' => $row->usuario_email,
            'usuario_nombres' => $row->usuario_nombres,
            'usuario_apellidos' => $row->usuario_apellidos,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }
}
