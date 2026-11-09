<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MisFacturasController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $deportista = DB::table('clientes.deportistas')
            ->where('usuario_id', $request->user()->id)
            ->first(['id']);

        if (! $deportista) {
            return ApiResponse::exito('La cuenta no tiene un deportista vinculado.', []);
        }

        $ventas = DB::table('ventas.ventas as v')
            ->leftJoin('ventas.comprobantes as c', 'c.venta_id', '=', 'v.id')
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', 'm.sede_id')
            ->leftJoin('seguridad.users as vendedor', 'vendedor.id', '=', 'v.usuario_id')
            ->where('v.cliente_id', $deportista->id)
            ->orderByDesc('v.fecha_venta')->orderByDesc('v.id')
            ->get([
                'v.id', 'v.numero', 'v.concepto', 'v.subtotal', 'v.descuento', 'v.impuesto',
                'v.total', 'v.estado', 'v.fecha_venta', 'v.observaciones',
                'c.numero as comprobante_numero', 'c.tipo_comprobante',
                'c.estado as comprobante_estado', 'c.emitido_at',
                's.nombre as sede_nombre', 's.telefono as sede_telefono',
                'vendedor.name as vendedor_nombre',
            ])->map(function ($venta) use ($request): array {
                $pagos = DB::table('ventas.pagos')->where('venta_id', $venta->id)
                    ->where('estado', 'CONFIRMADO')->orderByDesc('fecha_pago')->get();
                $pagado = round((float) $pagos->sum('monto'), 2);
                $saldo = max(0, round((float) $venta->total - $pagado, 2));
                $ultimoPago = $pagos->first();
                $detalles = DB::table('ventas.venta_detalles as d')
                    ->leftJoin('inventario.productos as p', 'p.id', '=', 'd.producto_id')
                    ->where('d.venta_id', $venta->id)->orderBy('d.id')
                    ->get([
                        'd.id', 'd.descripcion', 'd.cantidad', 'd.precio_unitario',
                        'd.total_linea', 'p.nombre as producto_catalogo', 'p.marca',
                        'p.unidad_medida', 'p.imagen_url',
                    ])->map(fn ($detalle): array => [
                        'id' => (int) $detalle->id,
                        'producto_nombre' => $detalle->producto_catalogo ?: $detalle->descripcion,
                        'producto_marca' => $detalle->marca,
                        'producto_unidad' => $detalle->unidad_medida ?: 'UND',
                        'producto_imagen_url' => $detalle->imagen_url,
                        'cantidad' => (float) $detalle->cantidad,
                        'precio_unitario' => (float) $detalle->precio_unitario,
                        'subtotal' => (float) $detalle->total_linea,
                    ])->values()->all();

                return [
                    'id' => (int) $venta->id,
                    'referencia' => $venta->comprobante_numero ?: $venta->numero,
                    'numero_venta' => $venta->numero,
                    'tipo_comprobante' => $venta->tipo_comprobante ?: 'RECIBO',
                    'estado_comprobante' => $venta->comprobante_estado,
                    'fecha' => $venta->emitido_at ?: $venta->fecha_venta,
                    'cliente_nombre' => $request->user()->name,
                    'vendedor_nombre' => $venta->vendedor_nombre,
                    'sede_nombre' => $venta->sede_nombre ?: 'Revive',
                    'sede_telefono' => $venta->sede_telefono,
                    'forma_pago' => $ultimoPago?->metodo_pago ?: 'PENDIENTE',
                    'estado_pago' => $saldo <= 0.009 && $venta->estado !== 'ANULADA' ? 'PAGADO' : 'PENDIENTE',
                    'estado' => $saldo <= 0.009 && $venta->estado !== 'ANULADA' ? 1 : 0,
                    'subtotal' => (float) $venta->subtotal,
                    'iva' => (float) $venta->impuesto,
                    'total' => (float) $venta->total,
                    'total_pagado' => $pagado,
                    'saldo_pendiente' => $saldo,
                    'observacion' => $venta->observaciones ?: $venta->concepto,
                    'detalles' => $detalles,
                ];
            })->values()->all();

        return ApiResponse::exito('Ventas y comprobantes personales consultados.', $ventas);
    }
}
