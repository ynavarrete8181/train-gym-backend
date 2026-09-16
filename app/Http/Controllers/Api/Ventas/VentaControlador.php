<?php

namespace App\Http\Controllers\Api\Ventas;

use App\Http\Controllers\Controller;
use App\Services\Ventas\VentaServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VentaControlador extends Controller
{
    public function __construct(private readonly VentaServicio $ventas)
    {
    }

    public function cajas(Request $request) { return $this->respuesta('Cajas consultadas.', $this->ventas->listarCajas($request->all())); }
    public function ventas(Request $request) { return $this->respuesta('Ventas consultadas.', $this->ventas->listarVentas($request->all())); }
    public function pagos(Request $request) { return $this->respuesta('Pagos consultados.', $this->ventas->listarPagos($request->all())); }
    public function comprobantes(Request $request) { return $this->respuesta('Comprobantes consultados.', $this->ventas->listarComprobantes($request->all())); }

    public function guardarCaja(Request $request, ?int $id = null)
    {
        $datos = $request->validate([
            'sede_id' => 'nullable|exists:pgsql.institucional.sedes,id_sede',
            'codigo' => ['required', 'string', 'max:40', Rule::unique('ventas.cajas', 'codigo')->ignore($id)],
            'nombre' => 'required|string|max:120',
            'descripcion' => 'nullable|string',
            'saldo_inicial' => 'required|numeric|min:0',
            'activa' => 'boolean',
        ]);

        return ApiResponse::exito('Caja guardada correctamente.', (array) $this->ventas->guardarCaja($datos, $id), [], $id ? 200 : 201);
    }

    public function guardarVenta(Request $request, ?int $id = null)
    {
        $datos = $request->validate([
            'cliente_id' => 'nullable|exists:pgsql.gimnasio.deportistas,id',
            'membresia_id' => 'nullable|exists:pgsql.gimnasio.membresias,id',
            'caja_id' => 'nullable|exists:pgsql.ventas.cajas,id',
            'numero' => ['nullable', 'string', 'max:60', Rule::unique('ventas.ventas', 'numero')->ignore($id)],
            'tipo_venta' => 'required|string|in:PRODUCTO,MEMBRESIA,SERVICIO,OTRO',
            'concepto' => 'required|string|max:180',
            'subtotal' => 'nullable|numeric|min:0',
            'descuento' => 'nullable|numeric|min:0',
            'impuesto' => 'nullable|numeric|min:0',
            'total' => 'required|numeric|min:0.01',
            'estado' => [
                'required',
                'string',
                Rule::exists('configuracion.estados_catalogo', 'valor_interno')
                    ->where(fn ($q) => $q->where('entidad', 'VENTA')->where('activo', true)),
            ],
            'observaciones' => 'nullable|string',
            'detalle' => 'nullable|array',
            'detalle.producto_id' => 'nullable|exists:pgsql.inventario.productos,id',
            'detalle.descripcion' => 'nullable|string|max:180',
            'detalle.cantidad' => 'nullable|numeric|min:0.01',
            'detalle.precio_unitario' => 'nullable|numeric|min:0',
            'detalle.total_linea' => 'nullable|numeric|min:0',
        ]);

        return ApiResponse::exito('Venta guardada correctamente.', (array) $this->ventas->guardarVenta($datos, $id, $request->user()?->id), [], $id ? 200 : 201);
    }

    public function guardarPago(Request $request)
    {
        $datos = $request->validate([
            'venta_id' => 'required|exists:pgsql.ventas.ventas,id',
            'caja_id' => 'nullable|exists:pgsql.ventas.cajas,id',
            'numero_comprobante' => 'nullable|string|max:60|unique:pgsql.ventas.pagos,numero_comprobante',
            'metodo_pago' => 'required|string|in:EFECTIVO,TARJETA,TRANSFERENCIA,DEPOSITO,OTRO',
            'monto' => 'required|numeric|min:0.01',
            'estado' => [
                'required',
                'string',
                Rule::exists('configuracion.estados_catalogo', 'valor_interno')
                    ->where(fn ($q) => $q->where('entidad', 'PAGO')->where('activo', true)),
            ],
            'referencia' => 'nullable|string|max:120',
            'observaciones' => 'nullable|string',
        ]);

        return ApiResponse::exito('Pago registrado correctamente.', (array) $this->ventas->guardarPago($datos, $request->user()?->id), [], 201);
    }

    private function respuesta(string $mensaje, $paginador)
    {
        return ApiResponse::exito($mensaje, $paginador->items(), [
            'pagina_actual' => $paginador->currentPage(),
            'por_pagina' => $paginador->perPage(),
            'total' => $paginador->total(),
            'ultima_pagina' => $paginador->lastPage(),
            'opciones_filtro' => $this->ventas->opcionesFiltro(),
            'catalogos' => $this->ventas->catalogos(),
        ]);
    }
}
