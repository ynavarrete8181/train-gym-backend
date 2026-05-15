<?php

namespace App\Queries\Ventas;

use Illuminate\Support\Facades\DB;

class PuntoVentaQuery
{
    public function sedeExists(int $sedeId): bool
    {
        return DB::table('train_gimnasio.sedes')
            ->where('id', $sedeId)
            ->where('activa', true)
            ->exists();
    }

    public function availableLotsForSale(int $productoId, int $sedeId, bool $excludeExpired = false): array
    {
        $query = DB::table('train_gimnasio.producto_lotes')
            ->select('id', 'codigo_lote', 'fecha_elaboracion', 'fecha_vencimiento', 'stock_actual')
            ->where('producto_id', $productoId)
            ->where('sede_id', $sedeId)
            ->where('estado', 1)
            ->where('stock_actual', '>', 0);

        if ($excludeExpired) {
            $query->where(function ($builder) {
                $builder->whereNull('fecha_vencimiento')
                    ->orWhere('fecha_vencimiento', '>=', now()->toDateString());
            });
        }

        return $query
            ->orderByRaw("
                CASE
                    WHEN fecha_vencimiento IS NULL THEN 1
                    ELSE 0
                END ASC
            ")
            ->orderBy('fecha_vencimiento')
            ->orderBy('fecha_elaboracion')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'codigo_lote' => $row->codigo_lote,
                'fecha_elaboracion' => $row->fecha_elaboracion,
                'fecha_vencimiento' => $row->fecha_vencimiento,
                'stock_actual' => (float) $row->stock_actual,
            ])
            ->all();
    }
}
