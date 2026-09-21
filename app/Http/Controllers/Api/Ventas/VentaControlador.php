<?php

namespace App\Http\Controllers\Api\Ventas;

use App\Http\Controllers\Controller;
use App\Services\Ventas\CajaServicio;
use App\Services\Ventas\TurnoCajaServicio;
use App\Services\Ventas\VentaFiltroServicio;
use App\Services\Ventas\VentaPosServicio;
use App\Services\Ventas\VentaServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VentaControlador extends Controller
{
    public function __construct(
        private readonly VentaServicio $ventas,
        private readonly VentaFiltroServicio $filtros,
        private readonly TurnoCajaServicio $turnos,
        private readonly CajaServicio $cajas,
        private readonly VentaPosServicio $pos,
    ) {
    }

    public function cajas(Request $request)
    {
        return $this->respuesta(
            'Cajas consultadas.',
            $this->ventas->listarCajas($request->all(), $request->user()?->id),
            $request->user()?->id,
        );
    }

    public function ventas(Request $request)
    {
        return $this->respuesta(
            'Ventas consultadas.',
            $this->ventas->listarVentas($request->all(), $request->user()?->id),
            $request->user()?->id,
        );
    }

    public function contextoPos(Request $request)
    {
        return ApiResponse::exito(
            'Contexto POS consultado.',
            $this->pos->contexto((int) $request->user()->id),
        );
    }

    public function guardarVentaPos(Request $request)
    {
        $datos = $request->validate([
            'cliente_id' => 'nullable|exists:pgsql.gimnasio.deportistas,id',
            'descuento' => 'nullable|numeric|min:0',
            'impuesto' => 'nullable|numeric|min:0',
            'observaciones' => 'nullable|string|max:1000',
            'detalles' => 'required|array|min:1',
            'detalles.*.tipo' => 'required|string|in:PRODUCTO,MEMBRESIA,SERVICIO,OTRO',
            'detalles.*.referencia_id' => 'nullable|integer',
            'detalles.*.producto_id' => 'nullable|exists:pgsql.inventario.productos,id',
            'detalles.*.descripcion' => 'required|string|max:180',
            'detalles.*.cantidad' => 'required|numeric|min:0.01',
            'detalles.*.precio_unitario' => 'required|numeric|min:0',
            'detalles.*.total_linea' => 'required|numeric|min:0.01',
        ]);

        return ApiResponse::exito(
            'Venta POS registrada correctamente.',
            (array) $this->pos->guardar($datos, (int) $request->user()->id),
            [],
            201,
        );
    }

    public function cobrarVentaPos(Request $request)
    {
        $datos = $request->validate([
            'cliente_id' => 'nullable|exists:pgsql.gimnasio.deportistas,id',
            'descuento' => 'nullable|numeric|min:0',
            'impuesto' => 'nullable|numeric|min:0',
            'observaciones' => 'nullable|string|max:1000',
            'metodo_pago' => 'required|string|in:EFECTIVO,TARJETA,TRANSFERENCIA,DEPOSITO,OTRO',
            'referencia_pago' => 'nullable|string|max:120',
            'observaciones_pago' => 'nullable|string|max:1000',
            'detalles' => 'required|array|min:1',
            'detalles.*.tipo' => 'required|string|in:PRODUCTO,MEMBRESIA,SERVICIO,OTRO',
            'detalles.*.referencia_id' => 'nullable|integer',
            'detalles.*.producto_id' => 'nullable|integer',
            'detalles.*.descripcion' => 'nullable|string|max:180',
            'detalles.*.cantidad' => 'required|numeric|min:0.01',
            'detalles.*.precio_unitario' => 'nullable|numeric|min:0',
            'detalles.*.total_linea' => 'nullable|numeric|min:0',
        ]);

        return ApiResponse::exito(
            'Venta, pago y comprobante registrados correctamente.',
            (array) $this->pos->cobrar($datos, (int) $request->user()->id),
            [],
            201,
        );
    }

    public function pagos(Request $request)
    {
        return $this->respuesta(
            'Pagos consultados.',
            $this->ventas->listarPagos($request->all(), $request->user()?->id),
            $request->user()?->id,
        );
    }

    public function comprobantes(Request $request)
    {
        return $this->respuesta(
            'Comprobantes consultados.',
            $this->ventas->listarComprobantes($request->all(), $request->user()?->id),
            $request->user()?->id,
        );
    }

    public function guardarCaja(Request $request, ?int $id = null)
    {
        $datos = $request->validate([
            'sede_id' => 'required|exists:pgsql.institucional.sedes,id_sede',
            'nombre' => 'required|string|max:120',
            'descripcion' => 'nullable|string|max:500',
            'activa' => 'boolean',
        ]);

        return ApiResponse::exito(
            'Caja guardada correctamente.',
            (array) $this->cajas->guardar($datos, $id, $request->user()?->id),
            [],
            $id ? 200 : 201,
        );
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

        $turno = $this->turnos->turnoAbiertoUsuario($request->user()?->id);
        if ($turno) {
            if (! empty($datos['caja_id']) && (int) $datos['caja_id'] !== (int) $turno->caja_id) {
                throw ValidationException::withMessages([
                    'caja_id' => 'La venta debe registrarse en la caja del turno actualmente abierto.',
                ]);
            }

            $datos['caja_id'] = (int) $turno->caja_id;
            $datos['turno_caja_id'] = (int) $turno->id;
        }

        return ApiResponse::exito(
            'Venta guardada correctamente.',
            (array) $this->ventas->guardarVenta($datos, $id, $request->user()?->id),
            [],
            $id ? 200 : 201,
        );
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

        $turno = $this->turnos->turnoAbiertoUsuario($request->user()?->id);
        if (! $turno) {
            throw ValidationException::withMessages([
                'turno_caja_id' => 'Debes abrir un turno de caja antes de registrar un pago.',
            ]);
        }

        if (! empty($datos['caja_id']) && (int) $datos['caja_id'] !== (int) $turno->caja_id) {
            throw ValidationException::withMessages([
                'caja_id' => 'El pago debe registrarse en la caja del turno actualmente abierto.',
            ]);
        }

        $datos['caja_id'] = (int) $turno->caja_id;
        $datos['turno_caja_id'] = (int) $turno->id;

        return ApiResponse::exito(
            'Pago registrado correctamente.',
            (array) $this->ventas->guardarPago($datos, $request->user()?->id),
            [],
            201,
        );
    }

    private function respuesta(string $mensaje, $paginador, ?int $usuarioId)
    {
        return ApiResponse::exito($mensaje, $paginador->items(), [
            'pagina_actual' => $paginador->currentPage(),
            'por_pagina' => $paginador->perPage(),
            'total' => $paginador->total(),
            'ultima_pagina' => $paginador->lastPage(),
            'opciones_filtro' => $this->filtros->opciones($usuarioId),
            'catalogos' => $this->ventas->catalogos($usuarioId),
        ]);
    }
}
