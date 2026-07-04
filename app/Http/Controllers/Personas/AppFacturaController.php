<?php

namespace App\Http\Controllers\Personas;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AppFacturaController extends Controller
{
    public function getFacturas(Request $request)
    {
        $personaId = null;
        if ($request->user()) {
            $personaId = $request->user()->persona_id;
        } else {
            // Mock para entorno local si no hay token
            $persona = DB::table('core.personas')->where('nombres', 'like', '%Yandry%')->first();
            $personaId = $persona ? $persona->id : null;
        }

        if (!$personaId) {
            return response()->json(['message' => 'Persona no encontrada'], 404);
        }

        $facturas = DB::table('ventas.ventas')
            ->join('core.sedes', 'ventas.ventas.sede_id', '=', 'core.sedes.id')
            ->leftJoin('seguridad.usuarios as vendedor_user', 'ventas.ventas.vendedor_usuario_id', '=', 'vendedor_user.id')
            ->leftJoin('core.personas as vendedor_persona', 'vendedor_user.persona_id', '=', 'vendedor_persona.id')
            ->leftJoin('core.personas as cliente_persona', 'ventas.ventas.persona_id', '=', 'cliente_persona.id')
            ->leftJoin('socios.membresias as membresia', 'ventas.ventas.membresia_id', '=', 'membresia.id')
            ->where('ventas.ventas.persona_id', $personaId)
            ->select(
                'ventas.ventas.*',
                'core.sedes.nombre as sede_nombre',
                'core.sedes.telefono as sede_telefono',
                'membresia.nombre as membresia_nombre',
                DB::raw("CONCAT(vendedor_persona.nombres, ' ', COALESCE(vendedor_persona.apellidos, '')) as vendedor_nombre"),
                DB::raw("CONCAT(cliente_persona.nombres, ' ', COALESCE(cliente_persona.apellidos, '')) as cliente_nombre")
            )
            ->orderBy('ventas.ventas.fecha', 'desc')
            ->get();

        // Obtener detalles de las facturas
        $detalleColumns = Schema::getColumnListing('ventas.venta_detalles');
        $hasMembresiaId = in_array('membresia_id', $detalleColumns, true);
        $hasTipoDetalle = in_array('tipo_detalle', $detalleColumns, true);
        $hasDescripcion = in_array('descripcion', $detalleColumns, true);

        foreach ($facturas as $factura) {
            $detallesQuery = DB::table('ventas.venta_detalles')
                ->leftJoin('inventario.productos as producto', 'ventas.venta_detalles.producto_id', '=', 'producto.id')
                ->where('ventas.venta_detalles.venta_id', $factura->id)
                ->select(
                    'ventas.venta_detalles.*',
                    'producto.nombre as producto_nombre',
                    'producto.imagen_url as producto_imagen_url',
                    'producto.marca as producto_marca',
                    'producto.unidad_medida as producto_unidad'
                );

            if ($hasMembresiaId) {
                $detallesQuery->leftJoin('socios.membresias as membresia_detalle', 'ventas.venta_detalles.membresia_id', '=', 'membresia_detalle.id')
                    ->addSelect('membresia_detalle.nombre as membresia_nombre');
            } else {
                $detallesQuery->addSelect(DB::raw('NULL as membresia_nombre'));
            }

            $tipoDetalleExpr = $hasTipoDetalle
                ? ($hasMembresiaId
                    ? "COALESCE(ventas.venta_detalles.tipo_detalle, CASE WHEN ventas.venta_detalles.membresia_id IS NOT NULL THEN 'MEMBRESIA' ELSE 'PRODUCTO' END)"
                    : "COALESCE(ventas.venta_detalles.tipo_detalle, 'PRODUCTO')")
                : ($hasMembresiaId
                    ? "CASE WHEN ventas.venta_detalles.membresia_id IS NOT NULL THEN 'MEMBRESIA' ELSE 'PRODUCTO' END"
                    : "'PRODUCTO'");

            $detalleNombreExpr = $hasMembresiaId
                ? ($hasDescripcion
                    ? "COALESCE(producto.nombre, membresia_detalle.nombre, ventas.venta_detalles.descripcion, 'Detalle')"
                    : "COALESCE(producto.nombre, membresia_detalle.nombre, 'Detalle')")
                : ($hasDescripcion
                    ? "COALESCE(producto.nombre, ventas.venta_detalles.descripcion, 'Detalle')"
                    : "COALESCE(producto.nombre, 'Detalle')");

            $detalles = $detallesQuery
                ->addSelect(DB::raw("{$tipoDetalleExpr} as tipo_detalle"))
                ->addSelect(DB::raw("{$detalleNombreExpr} as detalle_nombre"))
                ->get();

            if ($detalles->isEmpty() && strtoupper((string) ($factura->tipo_venta ?? '')) === 'MEMBRESIA') {
                $detalles = collect([[
                    'id' => null,
                    'producto_id' => null,
                    'membresia_id' => $factura->membresia_id,
                    'tipo_detalle' => 'MEMBRESIA',
                    'cantidad' => 1,
                    'precio_unitario' => $factura->total,
                    'subtotal' => $factura->total,
                    'producto_nombre' => $factura->membresia_nombre ?: 'Membresia Revive',
                    'producto_imagen_url' => null,
                    'producto_marca' => 'Membresia',
                    'producto_unidad' => 'UNIDAD',
                    'detalle_nombre' => $factura->membresia_nombre ?: 'Membresia Revive',
                ]]);
            }

            $factura->detalles = $detalles;
        }

        return response()->json([
            'message' => 'Facturas obtenidas exitosamente',
            'data' => $facturas
        ]);
    }
}
