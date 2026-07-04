<?php

namespace App\Queries\Ejercicios;

use Illuminate\Support\Facades\DB;

class EjercicioQuery
{
    public function listar(array $filtros = [])
    {
        $query = DB::table('entrenamiento.ejercicios')
            ->where('activo', true);

        if (!empty($filtros['buscar'])) {
            $buscar = trim($filtros['buscar']);
            $query->where('nombre', 'ILIKE', "%{$buscar}%");
        }

        if (!empty($filtros['grupo_muscular'])) {
            $query->where('grupo_muscular', $filtros['grupo_muscular']);
        }

        if (!empty($filtros['equipamiento'])) {
            $query->where('equipamiento', $filtros['equipamiento']);
        }

        if (!empty($filtros['tipo_entrenamiento'])) {
            $query->where('tipo_entrenamiento', $filtros['tipo_entrenamiento']);
        }

        return $query->orderBy('nombre')->get()->map(fn ($row) => $this->mapEjercicio($row))->all();
    }

    public function obtenerPorId(int $id)
    {
        $row = DB::table('entrenamiento.ejercicios')
            ->where('id', $id)
            ->first();

        return $row ? $this->mapEjercicio($row) : null;
    }

    private function mapEjercicio($row): array
    {
        return [
            'id' => (int) $row->id,
            'gimnasio_id' => $row->gimnasio_id ? (int) $row->gimnasio_id : null,
            'nombre' => $row->nombre,
            'grupo_muscular' => $row->grupo_muscular,
            'equipamiento' => $row->equipamiento,
            'tipo_entrenamiento' => $row->tipo_entrenamiento ?? null,
            'instrucciones' => $row->instrucciones,
            'url_recurso' => $row->url_recurso,
            'activo' => (bool) $row->activo,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }
}
