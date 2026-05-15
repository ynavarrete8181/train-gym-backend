<?php

namespace App\Queries\Servicios;

use Illuminate\Support\Facades\DB;

class CategoriaServicioQuery
{
    public function all(): array
    {
        return DB::table('train_gimnasio.categoria_servicios as c')
            ->leftJoin('train_gimnasio.auth_usuarios as au', 'au.id', '=', 'c.user_id')
            ->leftJoin('train_gimnasio.personas as p', 'p.id', '=', 'au.persona_id')
            ->selectRaw("
                c.id,
                c.nombre,
                c.descripcion,
                c.estado_id,
                c.user_id,
                c.created_at,
                c.updated_at,
                au.email as usuario_email,
                p.nombres as usuario_nombres,
                p.apellidos as usuario_apellidos,
                CASE WHEN COALESCE(c.estado_id, 9) = 8 THEN 'Activo' ELSE 'Inactivo' END as estado_nombre,
                CASE WHEN COALESCE(c.estado_id, 9) = 8 THEN 'ACTIVO' ELSE 'INACTIVO' END as estado_codigo
            ")
            ->orderByDesc('c.id')
            ->get()
            ->map(fn ($row) => $this->mapRow($row))
            ->all();
    }

    public function find(int $id): ?array
    {
        $row = DB::table('train_gimnasio.categoria_servicios as c')
            ->leftJoin('train_gimnasio.auth_usuarios as au', 'au.id', '=', 'c.user_id')
            ->leftJoin('train_gimnasio.personas as p', 'p.id', '=', 'au.persona_id')
            ->selectRaw("
                c.id,
                c.nombre,
                c.descripcion,
                c.estado_id,
                c.user_id,
                c.created_at,
                c.updated_at,
                au.email as usuario_email,
                p.nombres as usuario_nombres,
                p.apellidos as usuario_apellidos,
                CASE WHEN COALESCE(c.estado_id, 9) = 8 THEN 'Activo' ELSE 'Inactivo' END as estado_nombre,
                CASE WHEN COALESCE(c.estado_id, 9) = 8 THEN 'ACTIVO' ELSE 'INACTIVO' END as estado_codigo
            ")
            ->where('c.id', $id)
            ->first();

        return $row ? $this->mapRow($row) : null;
    }

    public function serviciosByCategoria(int $categoriaId): array
    {
        return DB::table('train_gimnasio.tipos_servicios')
            ->select('id', 'categoria_id', 'nombre', 'descripcion', 'breve_desc', 'estado_id')
            ->where('categoria_id', $categoriaId)
            ->where('estado_id', 1)
            ->orderBy('nombre')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'categoria_id' => (int) $row->categoria_id,
                'nombre' => $row->nombre,
                'descripcion' => $row->descripcion,
                'breve_desc' => $row->breve_desc,
                'estado_id' => (int) $row->estado_id,
            ])
            ->all();
    }

    private function mapRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'nombre' => $row->nombre,
            'descripcion' => $row->descripcion,
            'estado_id' => (int) ($row->estado_id ?? 9),
            'activo' => (int) ($row->estado_id ?? 9) === 8,
            'estado_nombre' => $row->estado_nombre,
            'estado_codigo' => $row->estado_codigo,
            'user_id' => $row->user_id ? (int) $row->user_id : null,
            'usuario_email' => $row->usuario_email,
            'usuario_nombres' => $row->usuario_nombres,
            'usuario_apellidos' => $row->usuario_apellidos,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }
}

