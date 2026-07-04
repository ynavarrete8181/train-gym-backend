<?php

namespace App\Queries\Inventarios;

use Illuminate\Support\Facades\DB;

class ProveedorQuery
{
    public function all(): array
    {
        return array_map(fn ($row) => $this->mapRow($row), DB::select($this->baseSql() . ' ORDER BY p.prov_nombre ASC'));
    }

    public function find(int $id): ?array
    {
        $rows = DB::select($this->baseSql('WHERE p.prov_id = ?') . ' LIMIT 1', [$id]);
        $row = $rows[0] ?? null;

        return $row ? $this->mapRow($row) : null;
    }

    private function mapRow(object $row): array
    {
        return [
            'id' => (int) $row->prov_id,
            'ruc' => $row->prov_ruc,
            'nombre' => $row->prov_nombre,
            'direccion' => $row->prov_direccion,
            'telefono' => $row->prov_telefono,
            'correo' => $row->prov_correo,
            'usuario_id' => $row->prov_id_usuario ? (int) $row->prov_id_usuario : null,
            'estado' => (int) $row->prov_estado,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    private function baseSql(string $where = ''): string
    {
        return "
            SELECT
                p.prov_id,
                p.prov_ruc,
                p.prov_nombre,
                p.prov_direccion,
                p.prov_telefono,
                p.prov_correo,
                p.prov_id_usuario,
                p.prov_estado,
                p.created_at,
                p.updated_at
            FROM inventario.proveedores p
            {$where}
        ";
    }
}
