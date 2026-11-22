<?php

namespace App\Http\Controllers\Api\Ventas;

use App\Http\Controllers\Controller;
use App\Services\Ventas\CajaServicio;
use App\Services\Ventas\ComprobanteCorreoServicio;
use App\Services\Ventas\ComprobantePdfServicio;
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
        private readonly ComprobantePdfServicio $pdfs,
        private readonly ComprobanteCorreoServicio $comprobantesCorreo,
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

    public function anularVentaPos(Request $request, int $id)
    {
        $datos = $request->validate(['motivo' => 'required|string|min:8|max:500']);

        return ApiResponse::exito(
            'Venta anulada. Su historial se conserva.',
            (array) $this->ventas->anularVentaPendiente($id, $datos['motivo'], (int) $request->user()->id),
        );
    }

    public function anularVentaPagada(Request $request, int $id)
    {
        $datos = $request->validate(['motivo' => 'required|string|min:8|max:500']);

        return ApiResponse::exito(
            'Venta anulada: se registró la devolución y el inventario regresó al stock.',
            (array) $this->ventas->anularVentaPagada($id, $datos['motivo'], (int) $request->user()->id),
        );
    }

    public function cuentasAbiertasPos(Request $request)
    {
        return ApiResponse::exito(
            'Cuentas abiertas del turno consultadas.',
            $this->pos->cuentasAbiertas((int) $request->user()->id),
        );
    }

    public function contextoPos(Request $request)
    {
        return ApiResponse::exito(
            'Contexto POS consultado.',
            $this->pos->contexto((int) $request->user()->id),
        );
    }

    public function cotizarBeneficioPos(Request $request)
    {
        $datos = $request->validate([
            'cliente_id' => 'required|integer|exists:pgsql.clientes.deportistas,id',
            'plan_id' => 'required|integer|exists:pgsql.membresias.planes,id',
            'membresia_id' => 'nullable|integer|exists:pgsql.membresias.membresias,id',
        ]);
        return ApiResponse::exito('Beneficio y tarifa cotizados.', $this->pos->cotizarBeneficio(
            (int) $request->user()->id,
            (int) $datos['cliente_id'],
            (int) $datos['plan_id'],
            isset($datos['membresia_id']) ? (int) $datos['membresia_id'] : null,
        ));
    }

    public function guardarVentaPos(Request $request)
    {
        $datos = $request->validate([
            'cliente_id' => 'nullable|exists:pgsql.clientes.deportistas,id',
            'membresia_id' => 'nullable|exists:pgsql.membresias.membresias,id',
            'descuento' => 'nullable|numeric|min:0',
            'impuesto' => 'nullable|numeric|min:0',
            'observaciones' => 'nullable|string|max:1000',
            'detalles' => 'required|array|min:1',
            'detalles.*.tipo' => 'required|string|in:PRODUCTO,MEMBRESIA,SERVICIO,OTRO,PASE_DIARIO',
            'detalles.*.referencia_id' => 'nullable|integer',
            'detalles.*.membresia_id' => 'nullable|exists:pgsql.membresias.membresias,id',
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

    public function actualizarVentaPos(Request $request, int $id)
    {
        $datos = $request->validate([
            'cliente_id' => 'nullable|exists:pgsql.clientes.deportistas,id',
            'membresia_id' => 'nullable|exists:pgsql.membresias.membresias,id',
            'descuento' => 'nullable|numeric|min:0',
            'impuesto' => 'nullable|numeric|min:0',
            'observaciones' => 'nullable|string|max:1000',
            'detalles' => 'required|array|min:1',
            'detalles.*.tipo' => 'required|string|in:PRODUCTO,MEMBRESIA,SERVICIO,OTRO,PASE_DIARIO',
            'detalles.*.referencia_id' => 'nullable|integer',
            'detalles.*.membresia_id' => 'nullable|exists:pgsql.membresias.membresias,id',
            'detalles.*.producto_id' => 'nullable|exists:pgsql.inventario.productos,id',
            'detalles.*.descripcion' => 'required|string|max:180',
            'detalles.*.cantidad' => 'required|numeric|min:0.01',
            'detalles.*.precio_unitario' => 'required|numeric|min:0',
            'detalles.*.total_linea' => 'required|numeric|min:0.01',
        ]);

        return ApiResponse::exito(
            'Cuenta abierta actualizada correctamente.',
            (array) $this->pos->guardar($datos, (int) $request->user()->id, $id),
        );
    }

    public function cobrarVentaPos(Request $request)
    {
        $datos = $request->validate([
            'cliente_id' => 'nullable|exists:pgsql.clientes.deportistas,id',
            'membresia_id' => 'nullable|exists:pgsql.membresias.membresias,id',
            'descuento' => 'nullable|numeric|min:0',
            'impuesto' => 'nullable|numeric|min:0',
            'observaciones' => 'nullable|string|max:1000',
            'metodo_pago' => 'nullable|required_without:pagos|string|in:EFECTIVO,TARJETA,TRANSFERENCIA,DEPOSITO,OTRO',
            'referencia_pago' => 'nullable|string|max:120',
            'observaciones_pago' => 'nullable|string|max:1000',
            'pagos' => 'nullable|required_without:metodo_pago|array|min:1',
            'pagos.*.metodo_pago' => 'required|string|in:EFECTIVO,TARJETA,TRANSFERENCIA,DEPOSITO,OTRO',
            'pagos.*.monto' => 'required|numeric|min:0.01',
            'pagos.*.efectivo_recibido' => 'nullable|numeric|min:0',
            'pagos.*.cambio_entregado' => 'nullable|numeric|min:0',
            'pagos.*.referencia' => 'nullable|string|max:120',
            'pagos.*.institucion_financiera_id' => 'nullable|integer|exists:pgsql.configuracion.instituciones_financieras,id',
            'pagos.*.observaciones' => 'nullable|string|max:1000',
            'detalles' => 'required|array|min:1',
            'detalles.*.tipo' => 'required|string|in:PRODUCTO,MEMBRESIA,SERVICIO,OTRO,PASE_DIARIO',
            'detalles.*.referencia_id' => 'nullable|integer',
            'detalles.*.membresia_id' => 'nullable|exists:pgsql.membresias.membresias,id',
            'detalles.*.producto_id' => 'nullable|integer',
            'detalles.*.descripcion' => 'nullable|string|max:180',
            'detalles.*.cantidad' => 'required|numeric|min:0.01',
            'detalles.*.precio_unitario' => 'nullable|numeric|min:0',
            'detalles.*.total_linea' => 'nullable|numeric|min:0',
        ]);

        $venta = $this->pos->cobrar($datos, (int) $request->user()->id);
        $respuesta = (array) $venta;

        if (strtoupper((string) ($venta->estado ?? '')) === 'PAGADA') {
            try {
                $respuesta['correo_comprobante'] = $this->comprobantesCorreo->enviar((int) $venta->id, (int) $request->user()->id);
            } catch (\Throwable $e) {
                $respuesta['correo_comprobante'] = ['ok' => false, 'mensaje' => $e->getMessage()];
            }
        }

        return ApiResponse::exito('Venta y cobro registrados correctamente.', $respuesta, [], 201);
    }

    public function cobrarVentaPosExistente(Request $request, int $id)
    {
        $datos = $request->validate([
            'cliente_id' => 'nullable|exists:pgsql.clientes.deportistas,id',
            'membresia_id' => 'nullable|exists:pgsql.membresias.membresias,id',
            'descuento' => 'nullable|numeric|min:0',
            'impuesto' => 'nullable|numeric|min:0',
            'observaciones' => 'nullable|string|max:1000',
            'metodo_pago' => 'nullable|required_without:pagos|string|in:EFECTIVO,TARJETA,TRANSFERENCIA,DEPOSITO,OTRO',
            'referencia_pago' => 'nullable|string|max:120',
            'observaciones_pago' => 'nullable|string|max:1000',
            'pagos' => 'nullable|required_without:metodo_pago|array|min:1',
            'pagos.*.metodo_pago' => 'required|string|in:EFECTIVO,TARJETA,TRANSFERENCIA,DEPOSITO,OTRO',
            'pagos.*.monto' => 'required|numeric|min:0.01',
            'pagos.*.efectivo_recibido' => 'nullable|numeric|min:0',
            'pagos.*.cambio_entregado' => 'nullable|numeric|min:0',
            'pagos.*.referencia' => 'nullable|string|max:120',
            'pagos.*.institucion_financiera_id' => 'nullable|integer|exists:pgsql.configuracion.instituciones_financieras,id',
            'pagos.*.observaciones' => 'nullable|string|max:1000',
            'detalles' => 'required|array|min:1',
            'detalles.*.tipo' => 'required|string|in:PRODUCTO,MEMBRESIA,SERVICIO,OTRO,PASE_DIARIO',
            'detalles.*.referencia_id' => 'nullable|integer',
            'detalles.*.membresia_id' => 'nullable|exists:pgsql.membresias.membresias,id',
            'detalles.*.producto_id' => 'nullable|integer',
            'detalles.*.descripcion' => 'nullable|string|max:180',
            'detalles.*.cantidad' => 'required|numeric|min:0.01',
            'detalles.*.precio_unitario' => 'nullable|numeric|min:0',
            'detalles.*.total_linea' => 'nullable|numeric|min:0',
        ]);

        $venta = $this->pos->cobrar($datos, (int) $request->user()->id, $id);
        $respuesta = (array) $venta;

        if (strtoupper((string) ($venta->estado ?? '')) === 'PAGADA') {
            try {
                $respuesta['correo_comprobante'] = $this->comprobantesCorreo->enviar((int) $venta->id, (int) $request->user()->id);
            } catch (\Throwable $e) {
                $respuesta['correo_comprobante'] = ['ok' => false, 'mensaje' => $e->getMessage()];
            }
        }

        return ApiResponse::exito('Cuenta cobrada correctamente.', $respuesta, [], 200);
    }

    /** Un tutor paga en una sola operación las cuentas que tiene a cargo. */
    public function cobrarGrupoPos(Request $request)
    {
        $datos = $request->validate([
            'responsable_persona_id' => 'required|integer|exists:pgsql.personas.personas,id',
            'venta_ids' => 'required|array|min:1',
            'venta_ids.*' => 'required|integer|distinct|exists:pgsql.ventas.ventas,id',
            'observaciones' => 'nullable|string|max:1000',
            'pagos' => 'required|array|min:1',
            'pagos.*.metodo_pago' => 'required|string|in:EFECTIVO,TARJETA,TRANSFERENCIA,DEPOSITO,OTRO',
            'pagos.*.monto' => 'required|numeric|min:0.01',
            'pagos.*.efectivo_recibido' => 'nullable|numeric|min:0',
            'pagos.*.referencia' => 'nullable|string|max:120',
            'pagos.*.institucion_financiera_id' => 'nullable|integer|exists:pgsql.configuracion.instituciones_financieras,id',
        ]);

        return ApiResponse::exito(
            'Cobro del tutor registrado correctamente.',
            $this->pos->cobrarGrupo($datos, (int) $request->user()->id),
        );
    }

    /** Recibo de un cobro: reúne todas las cuentas pagadas con el mismo código. */
    public function comprobanteCobroPdf(Request $request, string $codigo)
    {
        $pagos = \Illuminate\Support\Facades\DB::table('ventas.pagos as p')
            ->leftJoin('seguridad.users as u', 'u.id', '=', 'p.usuario_id')
            ->leftJoin('personas.personas as pagador', 'pagador.id', '=', 'p.pagador_persona_id')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'p.caja_id')
            ->where('p.codigo_cobro', $codigo)
            ->where('p.estado', 'CONFIRMADO')
            ->orderBy('p.id')
            ->get(['p.*', 'u.name as cobrado_por_nombre', 'c.nombre as caja_nombre',
                \Illuminate\Support\Facades\DB::raw('TRIM(pagador.nombre_completo) as pagador_nombre'),
                'pagador.identificacion as pagador_identificacion']);
        if ($pagos->isEmpty()) {
            throw ValidationException::withMessages(['codigo' => 'El cobro no existe.']);
        }

        $ventas = $pagos->pluck('venta_id')->unique()->values()
            ->map(fn ($id) => $this->ventas->detalleVenta((int) $id, $request->user()?->id));
        $pdf = $this->pdfs->generarCobro($pagos, $ventas);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $codigo . '.pdf"',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    public function detalleVenta(Request $request, int $id)
    {
        return ApiResponse::exito(
            'Detalle de venta consultado.',
            (array) $this->ventas->detalleVenta($id, $request->user()?->id),
        );
    }

    public function comprobantePdf(Request $request, int $id)
    {
        $venta = $this->ventas->detalleVenta($id, $request->user()?->id);
        $pdf = $this->pdfs->generar($venta);
        $nombre = ($venta->comprobante?->numero ?? $venta->numero ?? ('VENTA-' . $id)) . '.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $nombre . '"',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    public function pagos(Request $request)
    {
        $usuarioId = $request->user()?->id;
        $paginador = $this->ventas->listarPagos($request->all(), $usuarioId);
        $alcance = ['solo_turno' => false];
        if (app(\App\Services\Seguridad\AlcanceOperativoService::class)->esCajero($usuarioId)) {
            $turno = $this->ventas->turnoCobrosCajero((int) $usuarioId);
            $alcance = ['solo_turno' => true, 'turno_abierto' => (bool) $turno, 'desde' => $turno?->fecha_apertura];
        }

        return ApiResponse::exito('Cobros consultados.', $paginador->items(), [
            'pagina_actual' => $paginador->currentPage(),
            'por_pagina' => $paginador->perPage(),
            'total' => $paginador->total(),
            'ultima_pagina' => $paginador->lastPage(),
            'opciones_filtro' => $this->filtros->opciones($usuarioId),
            'catalogos' => $this->ventas->catalogos($usuarioId),
            'alcance_cobros' => $alcance,
        ]);
    }

    public function reenviarComprobante(Request $request, int $id)
    {
        $comprobante = \Illuminate\Support\Facades\DB::table('ventas.comprobantes')->where('id', $id)->first();
        if (! $comprobante) {
            throw ValidationException::withMessages(['comprobante' => 'El comprobante no existe.']);
        }

        return ApiResponse::exito(
            'Comprobante reenviado correctamente.',
            $this->comprobantesCorreo->enviar((int) $comprobante->venta_id, (int) $request->user()->id, true),
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
            'cliente_id' => 'nullable|exists:pgsql.clientes.deportistas,id',
            'membresia_id' => 'nullable|exists:pgsql.membresias.membresias,id',
            'caja_id' => 'nullable|exists:pgsql.ventas.cajas,id',
            'numero' => ['nullable', 'string', 'max:60', Rule::unique('pgsql.ventas.ventas', 'numero')->ignore($id)],
            'tipo_venta' => 'required|string|in:PRODUCTO,MEMBRESIA,SERVICIO,OTRO,PASE_DIARIO',
            'concepto' => 'required|string|max:180',
            'subtotal' => 'nullable|numeric|min:0',
            'descuento' => 'nullable|numeric|min:0',
            'impuesto' => 'nullable|numeric|min:0',
            'total' => 'required|numeric|min:0.01',
            'estado' => [
                'required',
                'string',
                Rule::exists('pgsql.configuracion.estados_catalogo', 'valor_interno')
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

    public function corregirMetodoPago(Request $request, int $id)
    {
        $datos = $request->validate([
            'metodo_pago' => 'required|string|in:EFECTIVO,TARJETA,TRANSFERENCIA,DEPOSITO,OTRO',
            'referencia' => 'nullable|string|max:120',
            'institucion_financiera_id' => 'nullable|integer|exists:pgsql.configuracion.instituciones_financieras,id',
            'motivo' => 'required|string|min:8|max:300',
        ]);
        $pago = app(\App\Services\Ventas\TurnoCajaServicio::class)->corregirMetodoPago($id, $datos, (int) $request->user()->id);
        return ApiResponse::exito('Método de pago corregido.', (array) $pago);
    }

    public function completarBancoCobro(Request $request, string $operacion)
    {
        $datos = $request->validate([
            'metodo_pago' => 'required|string|in:TRANSFERENCIA,DEPOSITO',
            'institucion_financiera_id' => 'required|integer|exists:pgsql.configuracion.instituciones_financieras,id',
            'referencia' => 'nullable|string|max:120',
            'motivo' => 'nullable|string|max:300',
        ]);

        return ApiResponse::exito('Banco del cobro actualizado.',
            app(\App\Services\Ventas\TurnoCajaServicio::class)->completarBancoCobro($operacion, $datos, (int) $request->user()->id));
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
                Rule::exists('pgsql.configuracion.estados_catalogo', 'valor_interno')
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
