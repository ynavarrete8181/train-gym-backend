<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TiendaProductosController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'sede_id' => ['nullable', 'integer', 'exists:pgsql.institucional.sedes,id_sede'],
        ]);

        $sedeId = isset($datos['sede_id']) ? (int) $datos['sede_id'] : null;

        $query = DB::table('inventario.productos as p')
            ->where('p.activo', true)
            ->select([
                'p.id',
                'p.codigo',
                'p.nombre',
                'p.descripcion',
                'p.marca',
                'p.imagen_url',
                'p.imagen_path',
                'p.controla_stock',
            ]);

        if ($sedeId) {
            $query
                ->leftJoin('inventario.producto_precios_sede as ps', function ($join) use ($sedeId): void {
                    $join->on('ps.producto_id', '=', 'p.id')
                        ->where('ps.sede_id', '=', $sedeId)
                        ->where('ps.activo', '=', true);
                })
                ->leftJoin('inventario.producto_stock_sede as ss', function ($join) use ($sedeId): void {
                    $join->on('ss.producto_id', '=', 'p.id')
                        ->where('ss.sede_id', '=', $sedeId);
                })
                ->selectRaw('COALESCE(ps.precio, p.precio_venta) as precio_venta')
                ->selectRaw('COALESCE(ss.stock_actual, 0) as stock_actual')
                ->where(function ($builder): void {
                    $builder->whereNull('ps.id')->orWhereNotNull('ps.precio');
                });
        } else {
            $query->addSelect('p.precio_venta', 'p.stock_actual');
        }

        $productos = $query
            ->orderBy('p.nombre')
            ->limit(30)
            ->get()
            ->map(function ($producto): array {
                $imagen = $producto->imagen_url;
                if (! $imagen && $producto->imagen_path) {
                    $imagen = url(Storage::url($producto->imagen_path));
                }

                return [
                    'id' => $producto->id,
                    'nombre' => $producto->nombre,
                    'descripcion' => $producto->descripcion,
                    'marca' => $producto->marca,
                    'imagen_url' => $imagen,
                    'precio_venta' => (float) $producto->precio_venta,
                    'stock_actual' => (float) $producto->stock_actual,
                    'disponible' => ! $producto->controla_stock || (float) $producto->stock_actual > 0,
                ];
            })
            ->values()
            ->all();

        return ApiResponse::exito('Catálogo de productos disponible.', $productos);
    }
}
