<?php

namespace App\Services\Ventas;

use App\Services\Concerns\RegistraAuditoria;
use App\Services\Configuracion\EstadoCatalogoServicio;
use App\Services\Inventario\InventarioServicio;
use App\Services\Seguridad\AlcanceOperativoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VentaServicio
{
    use RegistraAuditoria;

    public function __construct(
        private readonly EstadoCatalogoServicio $estados,
        private readonly AlcanceOperativoService $alcance,
        private readonly InventarioServicio $inventario,
    ) {
    }

    public function listarCajas(array $filtros, ?int $usuarioId = null)
    {
        $query = DB::table('ventas.cajas')
            ->leftJoin('institucional.sedes', 'ventas.cajas.sede_id', '=', 'institucional.sedes.id_sede')
            ->select('ventas.cajas.*', 'institucional.sedes.nombre as sede_nombre');

        $this->alcance->aplicarSedes($query, 'ventas.cajas.sede_id', $usuarioId, 'maneja_caja');
        $this->buscar($query, $filtros['busqueda'] ?? null, ['ventas.cajas.codigo', 'ventas.cajas.nombre', 'institucional.sedes.nombre']);
        $this->filtrarTexto($query, 'ventas.cajas.nombre', $filtros['nombre'] ?? null);
        $this->filtrarBooleano($query, 'ventas.cajas.activa', $filtros['estado'] ?? null);

        return $query->orderBy('ventas.cajas.nombre')->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function guardarCaja(array $datos, ?int $id = null, ?int $usuarioId = null): object
    {
        $this->alcance->validarSede($usuarioId, isset($datos['sede_id']) ? (int) $datos['sede_id'] : null, 'maneja_caja');
        return $this->guardar('ventas.cajas', $datos, $id);
    }

    public function listarVentas(array $filtros, ?int $usuarioId = null)
    {
        $query = DB::table('ventas.ventas')
            ->leftJoin('gimnasio.deportistas', 'ventas.ventas.cliente_id', '=', 'gimnasio.deportistas.id')
            ->leftJoin('seguridad.users as cliente_user', 'gimnasio.deportistas.usuario_id', '=', 'cliente_user.id')
            ->leftJoin('ventas.cajas', 'ventas.ventas.caja_id', '=', 'ventas.cajas.id')
            ->leftJoin('gimnasio.membresias as membresia_sede', 'ventas.ventas.membresia_id', '=', 'membresia_sede.id')
            ->leftJoin('institucional.sedes as sede_operacion', DB::raw('COALESCE(ventas.cajas.sede_id, membresia_sede.sede_id)'), '=', 'sede_operacion.id_sede')
            ->leftJoin('configuracion.estados_catalogo as estado_cfg', 'ventas.ventas.estado_id', '=', 'estado_cfg.id')
            ->select(
                'ventas.ventas.*',
                'cliente_user.name as cliente_nombre',
                'gimnasio.deportistas.codigo_deportista',
                'ventas.cajas.nombre as caja_nombre',
                DB::raw('COALESCE(ventas.cajas.sede_id, membresia_sede.sede_id) as sede_id'),
                'sede_operacion.nombre as sede_nombre',
                'estado_cfg.codigo as estado_codigo',
                'estado_cfg.valor_interno as estado_valor',
                'estado_cfg.nombre as estado_nombre',
                'estado_cfg.color as estado_color'
            );

        $this->aplicarAlcanceVenta($query, $usuarioId);
        $this->buscar($query, $filtros['busqueda'] ?? null, ['ventas.ventas.numero', 'ventas.ventas.concepto', 'ventas.ventas.tipo_venta', 'cliente_user.name', 'estado_cfg.nombre']);
        $this->filtrarTexto($query, 'ventas.ventas.numero', $filtros['numero'] ?? null);
        $this->filtrarTexto($query, 'cliente_user.name', $filtros['cliente'] ?? null);
        $this->filtrarTexto($query, 'ventas.ventas.tipo_venta', $filtros['tipo'] ?? null);
        $this->filtrarTexto($query, 'ventas.ventas.estado', $filtros['estado'] ?? null);

        return $query->orderByDesc('ventas.ventas.fecha_venta')->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function guardarVenta(array $datos, ?int $id = null, ?int $usuarioId = null): object
    {
        return DB::transaction(function () use ($datos, $id, $usuarioId): object {
            $detalle = $datos['detalle'] ?? [];
            unset($datos['detalle']);

            $existente = $id ? DB::table('ventas.ventas')->where('id', $id)->first() : null;
            $cajaId = array_key_exists('caja_id', $datos) ? $datos['caja_id'] : $existente?->caja_id;
            $membresiaId = array_key_exists('membresia_id', $datos) ? $datos['membresia_id'] : $existente?->membresia_id;
            $sedeCaja = $this->alcance->sedeDeCaja($cajaId ? (int) $cajaId : null);
            $sedeMembresia = $this->alcance->sedeDeMembresia($membresiaId ? (int) $membresiaId : null);

            if ($sedeCaja && $sedeMembresia && $sedeCaja !== $sedeMembresia) {
                throw ValidationException::withMessages([
                    'caja_id' => 'La caja y la membresía pertenecen a sedes diferentes.',
                ]);
            }

            $sedeOperacion = $sedeCaja ?: $sedeMembresia;
            $this->alcance->validarSede($usuarioId, $sedeOperacion, 'maneja_caja');

            $datos = $this->estados->aplicar($datos, 'VENTA');
            $datos['usuario_id'] = $datos['usuario_id'] ?? $usuarioId;
            $datos['numero'] = $datos['numero'] ?? $this->secuencia('VENTA');
            $datos['subtotal'] = $this->numero($datos['subtotal'] ?? $datos['total'] ?? 0);
            $datos['descuento'] = $this->numero($datos['descuento'] ?? 0);
            $datos['impuesto'] = $this->numero($datos['impuesto'] ?? 0);
            $datos['total'] = $this->numero($datos['total'] ?? (($datos['subtotal'] - $datos['descuento']) + $datos['impuesto']));

            $ventaId = $this->guardarRetornandoId('ventas.ventas', $datos, $id);
            DB::table('ventas.venta_detalles')->where('venta_id', $ventaId)->delete();
            DB::table('ventas.venta_detalles')->insert([
                'venta_id' => $ventaId,
                'producto_id' => $detalle['producto_id'] ?? null,
                'descripcion' => $detalle['descripcion'] ?? $datos['concepto'],
                'cantidad' => $this->numero($detalle['cantidad'] ?? 1),
                'precio_unitario' => $this->numero($detalle['precio_unitario'] ?? $datos['total']),
                'total_linea' => $this->numero($detalle['total_linea'] ?? $datos['total']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('ventas.comprobantes')->updateOrInsert(
                ['venta_id' => $ventaId],
                [
                    'tipo_comprobante' => 'RECIBO',
                    'numero' => $this->secuencia('COMP'),
                    'estado' => $datos['estado'] === 'ANULADA' ? 'ANULADO' : 'BORRADOR',
                    'subtotal' => $datos['subtotal'],
                    'impuesto' => $datos['impuesto'],
                    'total' => $datos['total'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            return $this->obtenerVenta($ventaId);
        });
    }

    public function detalleVenta(int $ventaId, ?int $usuarioId = null): object
    {
        $sedeId = $this->alcance->sedeDeVenta($ventaId);
        $this->alcance->validarSede($usuarioId, $sedeId, 'maneja_caja');

        $venta = DB::table('ventas.ventas as v')
            ->leftJoin('gimnasio.deportistas as d', 'd.id', '=', 'v.cliente_id')
            ->leftJoin('seguridad.users as cu', 'cu.id', '=', 'd.usuario_id')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
            ->leftJoin('gimnasio.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', DB::raw('COALESCE(c.sede_id, m.sede_id)'))
            ->leftJoin('configuracion.estados_catalogo as e', 'e.id', '=', 'v.estado_id')
            ->where('v.id', $ventaId)
            ->select(
                'v.*',
                'cu.name as cliente_nombre',
                'cu.email as cliente_email',
                'd.codigo_deportista',
                'c.nombre as caja_nombre',
                'c.codigo as caja_codigo',
                's.nombre as sede_nombre',
                'm.codigo_contrato as membresia_codigo',
                'e.nombre as estado_nombre',
                'e.color as estado_color'
            )
            ->first();

        if (! $venta) {
            throw ValidationException::withMessages(['venta_id' => 'La venta no existe.']);
        }

        $venta->detalles = DB::table('ventas.venta_detalles')
            ->where('venta_id', $ventaId)
            ->orderBy('id')
            ->get();

        $venta->pagos = DB::table('ventas.pagos as p')
            ->leftJoin('configuracion.estados_catalogo as e', 'e.id', '=', 'p.estado_id')
            ->where('p.venta_id', $ventaId)
            ->orderBy('p.id')
            ->get([
                'p.*',
                'e.nombre as estado_nombre',
                'e.color as estado_color',
            ]);

        $venta->comprobante = DB::table('ventas.comprobantes')
            ->where('venta_id', $ventaId)
            ->first();

        $venta->sedes_membresia = collect();
        $venta->entrenadores_membresia = collect();

        if ($venta->membresia_id) {
            $venta->sedes_membresia = DB::table('gimnasio.membresia_sedes as ms')
                ->join('institucional.sedes as s', 's.id_sede', '=', 'ms.sede_id')
                ->where('ms.membresia_id', $venta->membresia_id)
                ->where('ms.activo', true)
                ->orderByDesc('ms.es_principal')
                ->orderBy('s.nombre')
                ->get(['ms.sede_id', 'ms.es_principal', 's.nombre as sede_nombre']);

            $venta->entrenadores_membresia = DB::table('gimnasio.asignaciones_entrenador_cliente as a')
                ->join('gimnasio.entrenadores as e', 'e.id', '=', 'a.entrenador_id')
                ->join('seguridad.users as u', 'u.id', '=', 'e.usuario_id')
                ->leftJoin('institucional.sedes as s', 's.id_sede', '=', 'a.sede_id')
                ->leftJoin('gimnasio.horario_bloques as hb', 'hb.id', '=', 'a.horario_bloque_id')
                ->where('a.membresia_id', $venta->membresia_id)
                ->where('a.estado', 'ACTIVO')
                ->orderBy('s.nombre')
                ->orderBy('u.name')
                ->get([
                    'a.entrenador_id',
                    'a.sede_id',
                    'a.horario_bloque_id',
                    'u.name as entrenador_nombre',
                    'e.especialidad',
                    's.nombre as sede_nombre',
                    'hb.nombre as horario_nombre',
                ]);
        }

        $venta->movimientos_inventario = DB::table('inventario.movimientos as m')
            ->join('inventario.productos as p', 'p.id', '=', 'm.producto_id')
            ->leftJoin('inventario.lotes_producto as l', 'l.id', '=', 'm.lote_id')
            ->where('m.venta_id', $ventaId)
            ->orderBy('m.id')
            ->get([
                'm.id',
                'm.tipo_movimiento',
                'm.cantidad',
                'm.stock_anterior',
                'm.stock_nuevo',
                'm.referencia',
                'm.fecha_movimiento',
                'p.codigo as producto_codigo',
                'p.nombre as producto_nombre',
                'l.codigo_lote',
            ]);

        return $venta;
    }

    public function listarPagos(array $filtros, ?int $usuarioId = null)
    {
        $query = DB::table('ventas.pagos')
            ->join('ventas.ventas', 'ventas.pagos.venta_id', '=', 'ventas.ventas.id')
            ->leftJoin('ventas.cajas as caja_venta', 'ventas.ventas.caja_id', '=', 'caja_venta.id')
            ->leftJoin('gimnasio.membresias as membresia_sede', 'ventas.ventas.membresia_id', '=', 'membresia_sede.id')
            ->leftJoin('ventas.cajas', 'ventas.pagos.caja_id', '=', 'ventas.cajas.id')
            ->leftJoin('configuracion.estados_catalogo as estado_cfg', 'ventas.pagos.estado_id', '=', 'estado_cfg.id')
            ->select(
                'ventas.pagos.*',
                'ventas.ventas.numero as venta_numero',
                'ventas.ventas.concepto as venta_concepto',
                'ventas.cajas.nombre as caja_nombre',
                DB::raw('COALESCE(caja_venta.sede_id, membresia_sede.sede_id) as sede_id'),
                'estado_cfg.codigo as estado_codigo',
                'estado_cfg.valor_interno as estado_valor',
                'estado_cfg.nombre as estado_nombre',
                'estado_cfg.color as estado_color'
            );

        $this->aplicarAlcanceVenta($query, $usuarioId, 'caja_venta.sede_id');
        $this->buscar($query, $filtros['busqueda'] ?? null, ['ventas.pagos.numero_comprobante', 'ventas.ventas.numero', 'ventas.pagos.metodo_pago', 'ventas.pagos.referencia', 'estado_cfg.nombre']);
        $this->filtrarTexto($query, 'ventas.pagos.numero_comprobante', $filtros['comprobante'] ?? null);
        $this->filtrarTexto($query, 'ventas.pagos.metodo_pago', $filtros['metodo'] ?? null);
        $this->filtrarTexto($query, 'ventas.pagos.estado', $filtros['estado'] ?? null);

        return $query->orderByDesc('ventas.pagos.fecha_pago')->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function guardarPago(array $datos, ?int $usuarioId = null): object
    {
        return DB::transaction(function () use ($datos, $usuarioId): object {
            $venta = DB::table('ventas.ventas')->where('id', (int) $datos['venta_id'])->lockForUpdate()->first();
            if (! $venta) {
                throw ValidationException::withMessages(['venta_id' => 'La venta no existe.']);
            }

            if (($datos['estado'] ?? null) === 'CONFIRMADO') {
                $pagadoActual = (float) DB::table('ventas.pagos')
                    ->where('venta_id', $venta->id)
                    ->where('estado', 'CONFIRMADO')
                    ->sum('monto');
                $saldo = round((float) $venta->total - $pagadoActual, 2);
                if ((float) $datos['monto'] > $saldo + 0.00001) {
                    throw ValidationException::withMessages([
                        'monto' => 'El pago supera el saldo pendiente de la venta.',
                    ]);
                }
            }

            $sedeVenta = $this->alcance->sedeDeVenta((int) $datos['venta_id']);
            $this->alcance->validarSede($usuarioId, $sedeVenta, 'maneja_caja');

            $sedeCajaPago = $this->alcance->sedeDeCaja(! empty($datos['caja_id']) ? (int) $datos['caja_id'] : null);
            if ($sedeCajaPago && $sedeVenta && $sedeCajaPago !== $sedeVenta) {
                throw ValidationException::withMessages([
                    'caja_id' => 'El pago debe registrarse en una caja de la misma sede de la venta.',
                ]);
            }
            if ($sedeCajaPago) {
                $this->alcance->validarSede($usuarioId, $sedeCajaPago, 'maneja_caja');
            }

            $datos = $this->estados->aplicar($datos, 'PAGO');
            $datos['usuario_id'] = $usuarioId;
            $datos['numero_comprobante'] = $datos['numero_comprobante'] ?? $this->secuencia('PAGO');
            $pagoId = $this->guardarRetornandoId('ventas.pagos', $datos, null);
            $this->actualizarEstadoVenta((int) $datos['venta_id'], $usuarioId);
            $this->auditar('ventas', 'CREAR', 'ventas.pagos', $pagoId, null, DB::table('ventas.pagos')->where('id', $pagoId)->first(), 'Pago registrado sobre la venta #' . $datos['venta_id'] . '.');

            DB::table('ventas.comprobantes')->where('venta_id', $datos['venta_id'])->update([
                'pago_id' => $pagoId,
                'estado' => 'EMITIDO',
                'emitido_at' => now(),
                'updated_at' => now(),
            ]);

            return $this->obtenerPago($pagoId);
        });
    }

    public function listarComprobantes(array $filtros, ?int $usuarioId = null)
    {
        $query = DB::table('ventas.comprobantes')
            ->join('ventas.ventas', 'ventas.comprobantes.venta_id', '=', 'ventas.ventas.id')
            ->leftJoin('ventas.cajas as caja_venta', 'ventas.ventas.caja_id', '=', 'caja_venta.id')
            ->leftJoin('gimnasio.membresias as membresia_sede', 'ventas.ventas.membresia_id', '=', 'membresia_sede.id')
            ->leftJoin('gimnasio.deportistas', 'ventas.ventas.cliente_id', '=', 'gimnasio.deportistas.id')
            ->leftJoin('seguridad.users as cliente_user', 'gimnasio.deportistas.usuario_id', '=', 'cliente_user.id')
            ->select(
                'ventas.comprobantes.*',
                'ventas.ventas.numero as venta_numero',
                'ventas.ventas.concepto',
                'cliente_user.name as cliente_nombre',
                DB::raw('COALESCE(caja_venta.sede_id, membresia_sede.sede_id) as sede_id')
            );

        $this->aplicarAlcanceVenta($query, $usuarioId, 'caja_venta.sede_id');
        $this->buscar($query, $filtros['busqueda'] ?? null, ['ventas.comprobantes.numero', 'ventas.ventas.numero', 'ventas.ventas.concepto', 'cliente_user.name']);
        $this->filtrarTexto($query, 'ventas.comprobantes.numero', $filtros['numero'] ?? null);
        $this->filtrarTexto($query, 'ventas.comprobantes.estado', $filtros['estado'] ?? null);

        return $query->orderByDesc('ventas.comprobantes.created_at')->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function catalogos(?int $usuarioId = null): array
    {
        $sedes = $this->alcance->sedesPermitidas($usuarioId, 'maneja_caja');

        return [
            'cajas' => DB::table('ventas.cajas')->where('activa', true)->whereIn('sede_id', $sedes)->orderBy('nombre')->get(['id', 'nombre', 'codigo', 'sede_id']),
            'clientes' => DB::table('gimnasio.deportistas')
                ->leftJoin('seguridad.users', 'gimnasio.deportistas.usuario_id', '=', 'seguridad.users.id')
                ->orderBy('seguridad.users.name')
                ->get(['gimnasio.deportistas.id', 'gimnasio.deportistas.codigo_deportista', 'seguridad.users.name as nombre']),
            'membresias' => DB::table('gimnasio.membresias')->whereIn('sede_id', $sedes)->orderByDesc('created_at')->limit(100)->get(['id', 'codigo_contrato', 'estado', 'estado_id', 'precio_aplicado', 'sede_id']),
            'productos' => DB::table('inventario.productos')->where('activo', true)->orderBy('nombre')->get(['id', 'codigo', 'nombre', 'precio_venta']),
            'sedes' => DB::table('institucional.sedes')->where('activo', true)->whereIn('id_sede', $sedes)->orderBy('nombre')->get(['id_sede as id', 'nombre']),
            'ventas_pendientes' => $this->ventasPendientesCatalogo($sedes),
            'estados_venta' => $this->estadosEntidad('VENTA'),
            'estados_pago' => $this->estadosEntidad('PAGO'),
        ];
    }

    public function opcionesFiltro(?int $usuarioId = null): array
    {
        $sedes = $this->alcance->sedesPermitidas($usuarioId, 'maneja_caja');
        $cajas = DB::table('ventas.cajas')->whereIn('sede_id', $sedes);
        $ventas = DB::table('ventas.ventas as v')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
            ->leftJoin('gimnasio.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->whereIn(DB::raw('COALESCE(c.sede_id, m.sede_id)'), $sedes);
        $pagos = DB::table('ventas.pagos as p')
            ->join('ventas.ventas as v', 'v.id', '=', 'p.venta_id')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
            ->leftJoin('gimnasio.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->whereIn(DB::raw('COALESCE(c.sede_id, m.sede_id)'), $sedes);

        return [
            'caja' => $cajas->distinct()->orderBy('nombre')->pluck('nombre')->values(),
            'cliente' => DB::table('seguridad.users')->distinct()->orderBy('name')->pluck('name')->values(),
            'numero' => $ventas->distinct()->orderBy('numero')->pluck('numero')->values(),
            'tipo' => (clone $ventas)->distinct()->orderBy('tipo_venta')->pluck('tipo_venta')->values(),
            'metodo' => $pagos->distinct()->orderBy('metodo_pago')->pluck('metodo_pago')->values(),
            'estado_venta' => $this->estadosEntidad('VENTA')->pluck('valor_interno')->values(),
            'estado_pago' => $this->estadosEntidad('PAGO')->pluck('valor_interno')->values(),
        ];
    }

    private function ventasPendientesCatalogo(array $sedes)
    {
        return DB::table('ventas.ventas as v')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
            ->leftJoin('gimnasio.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->whereIn('v.estado', ['PENDIENTE', 'PARCIAL'])
            ->whereIn(DB::raw('COALESCE(c.sede_id, m.sede_id)'), $sedes)
            ->orderByDesc('v.fecha_venta')
            ->get(['v.id', 'v.numero', 'v.concepto', 'v.total']);
    }

    private function aplicarAlcanceVenta($query, ?int $usuarioId, string $columnaCaja = 'ventas.cajas.sede_id'): void
    {
        $sedes = $this->alcance->sedesPermitidas($usuarioId, 'maneja_caja');
        if (empty($sedes)) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->whereIn(DB::raw("COALESCE({$columnaCaja}, membresia_sede.sede_id)"), $sedes);
    }

    private function obtenerVenta(int $id): object
    {
        return DB::table('ventas.ventas')
            ->leftJoin('configuracion.estados_catalogo as estado_cfg', 'ventas.ventas.estado_id', '=', 'estado_cfg.id')
            ->select('ventas.ventas.*', 'estado_cfg.codigo as estado_codigo', 'estado_cfg.nombre as estado_nombre', 'estado_cfg.color as estado_color')
            ->where('ventas.ventas.id', $id)
            ->first();
    }

    private function obtenerPago(int $id): object
    {
        return DB::table('ventas.pagos')
            ->leftJoin('configuracion.estados_catalogo as estado_cfg', 'ventas.pagos.estado_id', '=', 'estado_cfg.id')
            ->select('ventas.pagos.*', 'estado_cfg.codigo as estado_codigo', 'estado_cfg.nombre as estado_nombre', 'estado_cfg.color as estado_color')
            ->where('ventas.pagos.id', $id)
            ->first();
    }

    private function actualizarEstadoVenta(int $ventaId, ?int $usuarioId = null): void
    {
        $venta = DB::table('ventas.ventas')->where('id', $ventaId)->lockForUpdate()->first();
        if (! $venta) {
            throw ValidationException::withMessages(['venta_id' => 'La venta no existe.']);
        }

        $pagado = (float) DB::table('ventas.pagos')
            ->where('venta_id', $ventaId)
            ->where('estado', 'CONFIRMADO')
            ->sum('monto');

        $estado = $pagado <= 0 ? 'PENDIENTE' : ($pagado + 0.00001 >= (float) $venta->total ? 'PAGADA' : 'PARCIAL');
        $estadoVentaId = $this->estados->idPorValor('VENTA', $estado);

        DB::table('ventas.ventas')->where('id', $ventaId)->update([
            'estado' => $estado,
            'estado_id' => $estadoVentaId,
            'updated_at' => now(),
        ]);

        if ($estado === 'PAGADA' && ! $venta->inventario_aplicado_at) {
            $sedeId = $this->alcance->sedeDeVenta($ventaId);
            if (! $sedeId) {
                throw ValidationException::withMessages([
                    'venta_id' => 'No se pudo determinar la sede de la venta para descontar inventario.',
                ]);
            }

            $productos = DB::table('ventas.venta_detalles')
                ->where('venta_id', $ventaId)
                ->whereNotNull('producto_id')
                ->select('producto_id', DB::raw('SUM(cantidad) as cantidad'))
                ->groupBy('producto_id')
                ->get();

            foreach ($productos as $detalle) {
                $this->inventario->registrarSalidaVenta(
                    (int) $detalle->producto_id,
                    (int) $sedeId,
                    (float) $detalle->cantidad,
                    $ventaId,
                    $usuarioId,
                );
            }

            DB::table('ventas.ventas')->where('id', $ventaId)->update([
                'inventario_aplicado_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($estado === 'PAGADA' && $venta->membresia_id) {
            $estadoMembresiaId = $this->estados->idPorValor('MEMBRESIA', 'ACTIVA');
            DB::table('gimnasio.membresias')
                ->where('id', $venta->membresia_id)
                ->where('estado', 'PENDIENTE_PAGO')
                ->update([
                    'estado' => 'ACTIVA',
                    'estado_id' => $estadoMembresiaId,
                    'updated_at' => now(),
                ]);
        }
    }

    private function guardar(string $tabla, array $datos, ?int $id): object
    {
        return DB::table($tabla)->where('id', $this->guardarRetornandoId($tabla, $datos, $id))->first();
    }

    private function guardarRetornandoId(string $tabla, array $datos, ?int $id): int
    {
        $antes = $id ? DB::table($tabla)->where('id', $id)->first() : null;
        $datos['updated_at'] = now();
        if ($id) {
            DB::table($tabla)->where('id', $id)->update($datos);
            $this->auditar('ventas', 'ACTUALIZAR', $tabla, $id, $antes, DB::table($tabla)->where('id', $id)->first());
            return $id;
        }
        $datos['created_at'] = now();
        $nuevoId = DB::table($tabla)->insertGetId($datos);
        $this->auditar('ventas', 'CREAR', $tabla, $nuevoId, null, DB::table($tabla)->where('id', $nuevoId)->first());
        return $nuevoId;
    }

    private function estadosEntidad(string $entidad)
    {
        return DB::table('configuracion.estados_catalogo')
            ->where('entidad', $entidad)
            ->where('activo', true)
            ->orderBy('orden')
            ->get(['id', 'codigo', 'valor_interno', 'nombre', 'color', 'es_inicial', 'es_final']);
    }

    private function secuencia(string $prefijo): string
    {
        return $prefijo . '-' . now()->format('YmdHis') . '-' . random_int(100, 999);
    }

    private function numero(mixed $valor): float
    {
        return round((float) ($valor ?? 0), 2);
    }

    private function buscar($query, ?string $busqueda, array $columnas): void
    {
        if (empty($busqueda)) return;
        $texto = mb_strtolower($busqueda);
        $query->where(function ($q) use ($columnas, $texto): void {
            foreach ($columnas as $columna) $q->orWhereRaw("LOWER({$columna}) LIKE ?", ["%{$texto}%"]);
        });
    }

    private function filtrarTexto($query, string $columna, mixed $valor): void
    {
        if (empty($valor)) return;
        is_array($valor) ? $query->whereIn($columna, array_filter($valor)) : $query->whereRaw("LOWER({$columna}) LIKE ?", ['%' . mb_strtolower($valor) . '%']);
    }

    private function filtrarBooleano($query, string $columna, mixed $valor): void
    {
        if ($valor === null || $valor === '') return;
        $booleanos = collect(is_array($valor) ? $valor : [$valor])->map(fn ($item) => filter_var($item, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE))->filter(fn ($item) => $item !== null)->values();
        if ($booleanos->isNotEmpty()) $query->whereIn($columna, $booleanos->all());
    }
}
