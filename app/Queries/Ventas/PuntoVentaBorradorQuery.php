<?php

namespace App\Queries\Ventas;

use Illuminate\Support\Facades\DB;

class PuntoVentaBorradorQuery
{
    private function sumItems(array $items): float
    {
        return round(array_reduce($items, function ($carry, $item) {
            $cantidad = (float) ($item['cantidad'] ?? 0);
            $precio = (float) (
                $item['precio_unitario']
                ?? $item['precio']
                ?? (($cantidad > 0 && isset($item['subtotal'])) ? ((float) $item['subtotal'] / $cantidad) : 0)
            );

            return $carry + ($cantidad * $precio);
        }, 0), 2);
    }

    public function listByUser(int $userId): array
    {
        if (!DB::getSchemaBuilder()->hasTable('ventas.punto_venta_borradores')) {
            return [];
        }

        return DB::table('ventas.punto_venta_borradores as b')
            ->leftJoin('core.personas as p', 'p.id', '=', 'b.persona_id')
            ->leftJoin('socios.socios as s', 's.persona_id', '=', 'p.id')
            ->selectRaw("
                b.id,
                b.usuario_id,
                b.sede_id,
                b.persona_id,
                b.membresia_id,
                b.referencia,
                b.observacion,
                b.forma_pago,
                b.estado_pago,
                b.tipo_venta,
                b.subtotal,
                b.iva,
                b.total,
                b.items,
                b.metadata,
                b.venta_id,
                b.updated_at,
                p.numero_identificacion as cliente_cedula,
                CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, '')) as cliente_nombre,
                s.codigo_socio
            ")
            ->where('b.usuario_id', $userId)
            ->where('b.estado_pago', 'BORRADOR')
            ->orderByDesc('b.updated_at')
            ->get()
            ->map(function ($row) {
                $items = json_decode($row->items ?? '[]', true);
                $metadata = json_decode($row->metadata ?? '{}', true);
                $items = is_array($items) ? $items : [];
                $metadata = is_array($metadata) ? $metadata : [];
                $subtotalItems = $this->sumItems($items);
                $subtotal = (float) $row->subtotal > 0 ? (float) $row->subtotal : $subtotalItems;
                $total = (float) $row->total > 0 ? (float) $row->total : $subtotal;

                return [
                    'id' => (int) $row->id,
                    'usuario_id' => (int) $row->usuario_id,
                    'sede_id' => (int) $row->sede_id,
                    'persona_id' => $row->persona_id ? (int) $row->persona_id : null,
                    'membresia_id' => $row->membresia_id ? (int) $row->membresia_id : null,
                    'referencia' => $row->referencia,
                    'observacion' => $row->observacion,
                    'forma_pago' => $row->forma_pago,
                    'estado_pago' => $row->estado_pago,
                    'tipo_venta' => $row->tipo_venta,
                    'subtotal' => $subtotal,
                    'iva' => (float) $row->iva,
                    'total' => $total,
                    'items' => $items,
                    'items_count' => (int) array_sum(array_map(fn ($item) => (int) ($item['cantidad'] ?? 1), $items)),
                    'cliente_cedula' => $row->cliente_cedula,
                    'cliente_nombre' => trim((string) $row->cliente_nombre),
                    'codigo_socio' => $row->codigo_socio,
                    'venta_id' => $row->venta_id ? (int) $row->venta_id : null,
                    'updated_at' => $row->updated_at,
                    'metadata' => $metadata,
                ];
            })
            ->all();
    }

    public function findById(int $draftId, int $userId): ?array
    {
        if (!DB::getSchemaBuilder()->hasTable('ventas.punto_venta_borradores')) {
            return null;
        }

        $draft = DB::table('ventas.punto_venta_borradores')
            ->where('id', $draftId)
            ->where('usuario_id', $userId)
            ->where('estado_pago', 'BORRADOR')
            ->first();

        if (!$draft) {
            return null;
        }

        return $this->normalizeRow($draft);
    }

    public function findOpenByPersona(int $personaId, int $userId): ?array
    {
        if (!DB::getSchemaBuilder()->hasTable('ventas.punto_venta_borradores')) {
            return null;
        }

        $draft = DB::table('ventas.punto_venta_borradores')
            ->where('persona_id', $personaId)
            ->where('usuario_id', $userId)
            ->where('estado_pago', 'BORRADOR')
            ->orderByDesc('updated_at')
            ->first();

        return $draft ? $this->normalizeRow($draft) : null;
    }

    private function normalizeRow(object $row): array
    {
        $items = json_decode($row->items ?? '[]', true);
        $metadata = json_decode($row->metadata ?? '{}', true);
        $items = is_array($items) ? $items : [];
        $metadata = is_array($metadata) ? $metadata : [];
        $subtotalItems = $this->sumItems($items);
        $subtotal = (float) $row->subtotal > 0 ? (float) $row->subtotal : $subtotalItems;
        $total = (float) $row->total > 0 ? (float) $row->total : $subtotal;

        return [
            'id' => (int) $row->id,
            'usuario_id' => (int) $row->usuario_id,
            'sede_id' => (int) $row->sede_id,
            'persona_id' => $row->persona_id ? (int) $row->persona_id : null,
            'membresia_id' => $row->membresia_id ? (int) $row->membresia_id : null,
            'referencia' => $row->referencia,
            'observacion' => $row->observacion,
            'forma_pago' => $row->forma_pago,
            'estado_pago' => $row->estado_pago,
            'tipo_venta' => $row->tipo_venta,
            'subtotal' => $subtotal,
            'iva' => (float) $row->iva,
            'total' => $total,
            'items' => $items,
            'venta_id' => $row->venta_id ? (int) $row->venta_id : null,
            'metadata' => $metadata,
        ];
    }
}
