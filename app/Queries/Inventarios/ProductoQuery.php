<?php

namespace App\Queries\Inventarios;

use Illuminate\Support\Facades\DB;

class ProductoQuery
{
    public function all(): array
    {
        return array_map(fn ($row) => $this->mapRow($row), DB::select($this->baseSql() . ' ORDER BY p.id DESC'));
    }

    public function find(int $id): ?array
    {
        $rows = DB::select($this->baseSql('WHERE p.id = ?') . ' LIMIT 1', [$id]);
        $row = $rows[0] ?? null;

        return $row ? $this->mapRow($row) : null;
    }

    public function categories(): array
    {
        return DB::table('train_gimnasio.categorias_producto')
            ->selectRaw("
                id,
                nombre,
                descripcion,
                estado,
                created_at,
                updated_at,
                CASE WHEN COALESCE(estado, 0) = 1 THEN 'Activo' ELSE 'Inactivo' END as estado_nombre
            ")
            ->orderBy('nombre')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'nombre' => $row->nombre,
                'descripcion' => $row->descripcion,
                'estado' => (int) $row->estado,
                'activo' => (int) $row->estado === 1,
                'estado_nombre' => $row->estado_nombre,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ])
            ->all();
    }

    public function sedes(): array
    {
        return DB::table('train_gimnasio.sedes')
            ->select('id', 'nombre', 'direccion', 'telefono', 'activa')
            ->where('activa', true)
            ->orderBy('nombre')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'nombre' => $row->nombre,
                'direccion' => $row->direccion,
                'telefono' => $row->telefono,
                'activa' => (bool) $row->activa,
            ])
            ->all();
    }

    private function mapRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'codigo' => $row->codigo,
            'nombre' => $row->nombre,
            'descripcion' => $row->descripcion,
            'categoria_id' => (int) $row->categoria_id,
            'categoria_nombre' => $row->categoria_nombre,
            'marca' => $row->marca,
            'modelo' => $row->modelo,
            'sku' => $row->sku,
            'codigo_barras' => $row->codigo_barras,
            'unidad_medida' => $row->unidad_medida,
            'controla_stock' => (bool) $row->controla_stock,
            'permite_decimales' => (bool) $row->permite_decimales,
            'maneja_lotes' => (bool) $row->maneja_lotes,
            'maneja_vencimiento' => (bool) $row->maneja_vencimiento,
            'stock_minimo' => $row->stock_minimo,
            'stock_maximo' => $row->stock_maximo,
            'estado' => (int) $row->estado,
            'estado_nombre' => $row->estado_nombre,
            'imagen_url' => $row->imagen_url,
            'created_by' => $row->created_by ? (int) $row->created_by : null,
            'updated_by' => $row->updated_by ? (int) $row->updated_by : null,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
            'stock_actual' => $row->stock_actual,
            'stock_reservado' => $row->stock_reservado,
            'stock_disponible' => $row->stock_disponible,
            'stock_minimo_sede' => $row->stock_minimo_sede,
            'sede_principal_id' => $row->sede_principal_id ? (int) $row->sede_principal_id : null,
            'sede_principal_nombre' => $row->sede_principal_nombre,
            'ubicacion_principal' => $row->ubicacion_principal,
            'lotes_count' => (int) $row->lotes_count,
            'lotes' => json_decode($row->lotes ?? '[]', true) ?? [],
            'stocks' => json_decode($row->stocks ?? '[]', true) ?? [],
            'precio_costo' => $row->precio_costo,
            'precio_venta' => $row->precio_venta,
            'usuario_email' => $row->usuario_email,
            'usuario_nombre' => trim((string) $row->usuario_nombre),
        ];
    }

    private function baseSql(string $where = ''): string
    {
        return "
            SELECT
                p.id,
                p.codigo,
                p.nombre,
                p.descripcion,
                p.categoria_id,
                cp.nombre AS categoria_nombre,
                p.marca,
                p.modelo,
                p.sku,
                p.codigo_barras,
                p.unidad_medida,
                p.controla_stock,
                p.permite_decimales,
                p.maneja_lotes,
                p.maneja_vencimiento,
                p.stock_minimo,
                p.stock_maximo,
                p.estado,
                CASE
                    WHEN p.estado = 1 THEN 'Activo'
                    ELSE 'Inactivo'
                END AS estado_nombre,
                p.imagen_url,
                p.created_by,
                p.updated_by,
                p.created_at,
                p.updated_at,
                COALESCE(st.stock_actual, 0) AS stock_actual,
                COALESCE(st.stock_reservado, 0) AS stock_reservado,
                COALESCE(st.stock_disponible, 0) AS stock_disponible,
                COALESCE(st.stock_minimo, p.stock_minimo, 0) AS stock_minimo_sede,
                sp.sede_id AS sede_principal_id,
                sp.sede_nombre AS sede_principal_nombre,
                sp.ubicacion AS ubicacion_principal,
                COALESCE(lp.lotes_count, 0) AS lotes_count,
                COALESCE(lp.lotes, '[]'::json)::text AS lotes,
                COALESCE(sx.stocks, '[]'::json)::text AS stocks,
                pc.monto AS precio_costo,
                pv.monto AS precio_venta,
                au.email AS usuario_email,
                CONCAT(COALESCE(pe.nombres, ''), ' ', COALESCE(pe.apellidos, '')) AS usuario_nombre
            FROM train_gimnasio.productos p
            INNER JOIN train_gimnasio.categorias_producto cp
                ON cp.id = p.categoria_id
            LEFT JOIN LATERAL (
                SELECT
                    SUM(pss.stock_actual) AS stock_actual,
                    SUM(pss.stock_reservado) AS stock_reservado,
                    SUM(pss.stock_disponible) AS stock_disponible,
                    MIN(pss.stock_minimo) AS stock_minimo
                FROM train_gimnasio.producto_stock_sede pss
                WHERE pss.producto_id = p.id
                  AND pss.estado = 1
            ) st ON true
            LEFT JOIN LATERAL (
                SELECT
                    json_agg(
                        json_build_object(
                            'id', pss.id,
                            'sede_id', pss.sede_id,
                            'sede_nombre', s.nombre,
                            'stock_actual', pss.stock_actual,
                            'stock_reservado', pss.stock_reservado,
                            'stock_disponible', pss.stock_disponible,
                            'stock_minimo', pss.stock_minimo,
                            'ubicacion', pss.ubicacion,
                            'estado', pss.estado,
                            'stock_inicial', COALESCE((
                                SELECT SUM(mi.cantidad)
                                FROM train_gimnasio.movimientos_inventario mi
                                WHERE mi.producto_id = pss.producto_id
                                  AND mi.sede_id = pss.sede_id
                                  AND mi.tipo_movimiento = 'ENTRADA'
                                  AND mi.motivo = 'INVENTARIO_INICIAL'
                            ), 0),
                            'movimientos_total', COALESCE((
                                SELECT COUNT(*)
                                FROM train_gimnasio.movimientos_inventario mi
                                WHERE mi.producto_id = pss.producto_id
                                  AND mi.sede_id = pss.sede_id
                            ), 0),
                            'movimientos_no_inicial', COALESCE((
                                SELECT COUNT(*)
                                FROM train_gimnasio.movimientos_inventario mi
                                WHERE mi.producto_id = pss.producto_id
                                  AND mi.sede_id = pss.sede_id
                                  AND NOT (
                                      mi.tipo_movimiento = 'ENTRADA'
                                      AND mi.motivo = 'INVENTARIO_INICIAL'
                                  )
                            ), 0),
                            'inventario_inicial_editable', (
                                COALESCE((
                                    SELECT COUNT(*)
                                    FROM train_gimnasio.movimientos_inventario mi
                                    WHERE mi.producto_id = pss.producto_id
                                      AND mi.sede_id = pss.sede_id
                                      AND NOT (
                                          mi.tipo_movimiento = 'ENTRADA'
                                          AND mi.motivo = 'INVENTARIO_INICIAL'
                                      )
                                ), 0) = 0
                            )
                        )
                        ORDER BY pss.id ASC
                    ) AS stocks
                FROM train_gimnasio.producto_stock_sede pss
                LEFT JOIN train_gimnasio.sedes s
                    ON s.id = pss.sede_id
                WHERE pss.producto_id = p.id
                  AND pss.estado = 1
            ) sx ON true
            LEFT JOIN LATERAL (
                SELECT
                    pss.sede_id,
                    s.nombre AS sede_nombre,
                    pss.ubicacion
                FROM train_gimnasio.producto_stock_sede pss
                LEFT JOIN train_gimnasio.sedes s
                    ON s.id = pss.sede_id
                WHERE pss.producto_id = p.id
                  AND pss.estado = 1
                ORDER BY pss.id ASC
                LIMIT 1
            ) sp ON true
            LEFT JOIN LATERAL (
                SELECT
                    COUNT(pl.id) AS lotes_count,
                    json_agg(
                        json_build_object(
                            'id', pl.id,
                            'codigo_lote', pl.codigo_lote,
                            'fecha_elaboracion', pl.fecha_elaboracion,
                            'fecha_vencimiento', pl.fecha_vencimiento,
                            'stock_actual', pl.stock_actual,
                            'sede_id', pl.sede_id
                        )
                        ORDER BY pl.id DESC
                    ) AS lotes
                FROM train_gimnasio.producto_lotes pl
                WHERE pl.producto_id = p.id
                  AND pl.estado = 1
            ) lp ON true
            LEFT JOIN LATERAL (
                SELECT pp.monto
                FROM train_gimnasio.producto_precios pp
                WHERE pp.producto_id = p.id
                  AND pp.tipo_precio = 'COSTO'
                  AND pp.estado = 1
                  AND (pp.vigencia_fin IS NULL OR pp.vigencia_fin >= CURRENT_TIMESTAMP)
                ORDER BY pp.vigencia_inicio DESC, pp.id DESC
                LIMIT 1
            ) pc ON true
            LEFT JOIN LATERAL (
                SELECT pp.monto
                FROM train_gimnasio.producto_precios pp
                WHERE pp.producto_id = p.id
                  AND pp.tipo_precio = 'VENTA'
                  AND pp.estado = 1
                  AND (pp.vigencia_fin IS NULL OR pp.vigencia_fin >= CURRENT_TIMESTAMP)
                ORDER BY pp.vigencia_inicio DESC, pp.id DESC
                LIMIT 1
            ) pv ON true
            LEFT JOIN train_gimnasio.auth_usuarios au
                ON au.id = p.created_by
            LEFT JOIN train_gimnasio.personas pe
                ON pe.id = au.persona_id
            {$where}
        ";
    }
}
