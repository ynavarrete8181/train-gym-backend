<?php

namespace App\Services\Ventas;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class VentaPosServicio
{
    public function __construct(
        private readonly TurnoCajaServicio $turnos,
        private readonly VentaServicio $ventas,
    ) {
    }

    public function contexto(int $usuarioId): array
    {
        $turno = $this->turnos->turnoAbiertoUsuario($usuarioId);
        if (! $turno) {
            return $this->contextoVacio();
        }

        $sedeId = (int) $turno->sede_id;

        $clientes = DB::table('gimnasio.deportistas as d')
            ->join('seguridad.users as u', 'u.id', '=', 'd.usuario_id')
            ->where('d.estado', 'ACTIVO')
            ->orderBy('u.name')
            ->get([
                'd.id',
                'd.codigo_deportista as codigo',
                'd.telefono',
                'd.sede_principal_id',
                'u.name as nombre',
                'u.email',
            ]);

        $serviciosQuery = DB::table('gimnasio.servicios as s')
            ->join('gimnasio.horarios_servicio as h', function ($join) use ($sedeId): void {
                $join->on('h.servicio_id', '=', 's.id')
                    ->where('h.sede_id', '=', $sedeId)
                    ->where('h.activo', '=', true);
            })
            ->leftJoin('gimnasio.categorias_servicio as c', 'c.id', '=', 's.categoria_id')
            ->where('s.activo', true);

        $columnasServicios = [
            's.id',
            's.nombre',
            's.descripcion',
            's.duracion_minutos',
            's.requiere_reserva',
            'c.nombre as categoria',
        ];

        if (Schema::connection('pgsql')->hasTable('gimnasio.servicio_precios_sede')) {
            $serviciosQuery->leftJoin('gimnasio.servicio_precios_sede as ps', function ($join) use ($sedeId): void {
                $join->on('ps.servicio_id', '=', 's.id')
                    ->where('ps.sede_id', '=', $sedeId)
                    ->where('ps.activo', '=', true);
            });
            $columnasServicios[] = 'ps.precio';
        } else {
            $columnasServicios[] = DB::raw('NULL::numeric as precio');
        }

        $servicios = $serviciosQuery
            ->select($columnasServicios)
            ->distinct()
            ->orderBy('s.nombre')
            ->get();

        $planes = $this->planesPorSede($sedeId);

        $productos = collect();
        if (Schema::connection('pgsql')->hasTable('inventario.productos')) {
            $productosQuery = DB::table('inventario.productos as p')
                ->leftJoin('inventario.categorias_producto as cp', 'cp.id', '=', 'p.categoria_id')
                ->where('p.activo', true);

            $columnasProductos = [
                'p.id',
                'p.codigo',
                'p.nombre',
                'p.descripcion',
                'p.categoria_id',
                'cp.nombre as categoria',
                'p.precio_venta as precio_base',
                'p.controla_stock',
            ];

            $columnasProductos[] = Schema::connection('pgsql')->hasColumn('inventario.productos', 'imagen_url')
                ? 'p.imagen_url'
                : DB::raw('NULL::text as imagen_url');

            $columnasProductos[] = Schema::connection('pgsql')->hasColumn('inventario.productos', 'maneja_lotes')
                ? 'p.maneja_lotes'
                : DB::raw('false as maneja_lotes');

            if (Schema::connection('pgsql')->hasTable('inventario.producto_precios_sede')) {
                $productosQuery->leftJoin('inventario.producto_precios_sede as pps', function ($join) use ($sedeId): void {
                    $join->on('pps.producto_id', '=', 'p.id')
                        ->where('pps.sede_id', '=', $sedeId)
                        ->where('pps.activo', '=', true);
                });
                $columnasProductos[] = DB::raw('COALESCE(pps.precio, p.precio_venta) as precio');
            } else {
                $columnasProductos[] = 'p.precio_venta as precio';
            }

            if (Schema::connection('pgsql')->hasTable('inventario.producto_stock_sede')) {
                $productosQuery->leftJoin('inventario.producto_stock_sede as pss', function ($join) use ($sedeId): void {
                    $join->on('pss.producto_id', '=', 'p.id')
                        ->where('pss.sede_id', '=', $sedeId);
                });
                $columnasProductos[] = DB::raw('COALESCE(pss.stock_actual, 0) as stock_actual');
                $columnasProductos[] = DB::raw('COALESCE(pss.stock_minimo, 0) as stock_minimo');
            } else {
                $columnasProductos[] = 'p.stock_actual';
                $columnasProductos[] = 'p.stock_minimo';
            }

            $productos = $productosQuery
                ->select($columnasProductos)
                ->orderBy('p.nombre')
                ->get();
        }

        return [
            'turno' => $turno,
            'clientes' => $clientes,
            'servicios' => $servicios,
            'planes' => $planes,
            'productos' => $productos,
        ];
    }

    public function cuentasAbiertas(int $usuarioId): array
    {
        $turno = $this->turnos->turnoAbiertoUsuario($usuarioId);
        if (! $turno) {
            return [];
        }

        $hoy = now()->toDateString();
        $sedeId = (int) $turno->sede_id;

        return DB::table('ventas.ventas as v')
            ->leftJoin('gimnasio.deportistas as d', 'd.id', '=', 'v.cliente_id')
            ->leftJoin('seguridad.users as u', 'u.id', '=', 'd.usuario_id')
            ->leftJoin('gimnasio.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->leftJoin('gimnasio.planes as p', 'p.id', '=', 'm.plan_id')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', DB::raw('COALESCE(c.sede_id, m.sede_id)'))
            ->whereIn('v.estado', ['PENDIENTE', 'PARCIAL'])
            ->whereRaw('COALESCE(c.sede_id, m.sede_id) = ?', [$sedeId])
            ->where(function ($query) use ($turno, $hoy): void {
                $query
                    ->where('v.turno_caja_id', $turno->id)
                    ->orWhere(function ($q) use ($hoy): void {
                        $q->whereNull('v.turno_caja_id')
                            ->whereDate('v.fecha_venta', $hoy);
                    });
            })
            ->orderBy('v.fecha_venta')
            ->orderBy('v.id')
            ->get([
                'v.*',
                'u.name as cliente_nombre',
                'd.codigo_deportista',
                's.nombre as sede_nombre',
                'p.nombre as plan_nombre',
                'p.tipo_producto as plan_tipo_producto',
                DB::raw('(v.total - COALESCE((SELECT SUM(pg.monto) FROM ventas.pagos pg WHERE pg.venta_id = v.id AND pg.estado = \'CONFIRMADO\'), 0)) as saldo_pendiente'),
            ])
            ->map(fn ($venta) => (array) $venta)
            ->all();
    }

    public function guardar(array $datos, int $usuarioId, ?int $ventaId = null): object
    {
        $turno = $this->turnos->turnoAbiertoUsuario($usuarioId);
        if (! $turno) {
            throw ValidationException::withMessages([
                'turno_caja_id' => 'Debes abrir un turno de caja antes de registrar una venta desde el POS.',
            ]);
        }

        $detallesEntrada = collect($datos['detalles'] ?? [])->values();
        if ($detallesEntrada->isEmpty()) {
            throw ValidationException::withMessages(['detalles' => 'Agrega al menos un ítem a la venta.']);
        }

        $detalles = $this->resolverDetalles($detallesEntrada, (int) $turno->sede_id);
        $subtotal = round((float) $detalles->sum('total_linea'), 2);
        $descuento = round((float) ($datos['descuento'] ?? 0), 2);
        $impuesto = round((float) ($datos['impuesto'] ?? 0), 2);

        if ($descuento > $subtotal) {
            throw ValidationException::withMessages(['descuento' => 'El descuento no puede superar el subtotal de la venta.']);
        }

        $total = round($subtotal - $descuento + $impuesto, 2);
        if ($total <= 0) {
            throw ValidationException::withMessages(['total' => 'El total de la venta debe ser mayor que cero.']);
        }

        return DB::transaction(function () use ($datos, $detalles, $subtotal, $descuento, $impuesto, $total, $usuarioId, $turno, $ventaId): object {
            $ventaExistente = $ventaId ? DB::table('ventas.ventas')->where('id', $ventaId)->lockForUpdate()->first() : null;
            if ($ventaId && ! $ventaExistente) {
                throw ValidationException::withMessages(['venta_id' => 'La cuenta pendiente no existe.']);
            }
            if ($ventaExistente && ! in_array(strtoupper((string) $ventaExistente->estado), ['PENDIENTE', 'PARCIAL'], true)) {
                throw ValidationException::withMessages(['venta_id' => 'Solo se pueden modificar cuentas pendientes o parciales.']);
            }
            if ($ventaExistente && $ventaExistente->inventario_aplicado_at) {
                throw ValidationException::withMessages(['venta_id' => 'Esta venta ya afectó inventario y no puede modificarse.']);
            }
            $primero = $detalles->first();
            $venta = $this->ventas->guardarVenta([
                'cliente_id' => $datos['cliente_id'] ?? null,
                'caja_id' => (int) $turno->caja_id,
                'turno_caja_id' => (int) $turno->id,
                'tipo_venta' => $ventaExistente?->tipo_venta ?? $this->tipoVentaGeneral($detalles->all()),
                'concepto' => $ventaExistente?->concepto ?? $this->conceptoGeneral($detalles->all()),
                'subtotal' => $subtotal,
                'descuento' => $descuento,
                'impuesto' => $impuesto,
                'total' => $total,
                'estado' => $ventaExistente?->estado ?? 'PENDIENTE',
                'observaciones' => $datos['observaciones'] ?? null,
                'detalle' => [
                    'producto_id' => $primero['producto_id'] ?? null,
                    'descripcion' => $primero['descripcion'],
                    'cantidad' => $primero['cantidad'],
                    'precio_unitario' => $primero['precio_unitario'],
                    'total_linea' => $primero['total_linea'],
                ],
            ], $ventaId, $usuarioId);

            DB::table('ventas.venta_detalles')->where('venta_id', $venta->id)->delete();
            $ahora = now();
            DB::table('ventas.venta_detalles')->insert($detalles->map(fn ($item) => [
                'venta_id' => $venta->id,
                'producto_id' => $item['producto_id'] ?? null,
                'tipo_item' => $item['tipo'] ?? null,
                'referencia_id' => $item['referencia_id'] ?? null,
                'descripcion' => $item['descripcion'],
                'cantidad' => round((float) $item['cantidad'], 2),
                'precio_unitario' => round((float) $item['precio_unitario'], 2),
                'total_linea' => round((float) $item['total_linea'], 2),
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ])->all());

            $venta->detalles = DB::table('ventas.venta_detalles')
                ->where('venta_id', $venta->id)
                ->orderBy('id')
                ->get();

            return $venta;
        });
    }

    public function cobrar(array $datos, int $usuarioId, ?int $ventaId = null): object
    {
        return DB::transaction(function () use ($datos, $usuarioId, $ventaId): object {
            $turno = $this->turnos->turnoAbiertoUsuario($usuarioId);
            if (! $turno) {
                throw ValidationException::withMessages([
                    'turno_caja_id' => 'Debes abrir un turno de caja antes de cobrar una venta.',
                ]);
            }

            $venta = $this->guardar($datos, $usuarioId, $ventaId);
            $pagadoActual = (float) DB::table('ventas.pagos')
                ->where('venta_id', $venta->id)
                ->where('estado', 'CONFIRMADO')
                ->sum('monto');
            $saldo = round((float) $venta->total - $pagadoActual, 2);
            if ($saldo <= 0) {
                throw ValidationException::withMessages(['venta_id' => 'La cuenta ya no tiene saldo pendiente.']);
            }

            $pago = $this->ventas->guardarPago([
                'venta_id' => (int) $venta->id,
                'caja_id' => (int) $turno->caja_id,
                'turno_caja_id' => (int) $turno->id,
                'metodo_pago' => $datos['metodo_pago'],
                'monto' => $saldo,
                'estado' => 'CONFIRMADO',
                'referencia' => $datos['referencia_pago'] ?? null,
                'observaciones' => $datos['observaciones_pago'] ?? 'Cobro registrado desde POS.',
            ], $usuarioId);

            $ventaActualizada = DB::table('ventas.ventas')->where('id', $venta->id)->first();
            $ventaActualizada->detalles = DB::table('ventas.venta_detalles')->where('venta_id', $venta->id)->orderBy('id')->get();
            $ventaActualizada->pago = $pago;
            $ventaActualizada->comprobante = DB::table('ventas.comprobantes')->where('venta_id', $venta->id)->first();

            return $ventaActualizada;
        });
    }

    private function resolverDetalles($detalles, int $sedeId)
    {
        return $detalles->map(function ($item) use ($sedeId): array {
            $tipo = strtoupper((string) ($item['tipo'] ?? ''));
            $cantidad = round((float) ($item['cantidad'] ?? 0), 2);
            if ($cantidad <= 0) {
                throw ValidationException::withMessages(['detalles' => 'La cantidad de cada ítem debe ser mayor que cero.']);
            }

            if ($tipo === 'PRODUCTO') {
                $productoId = (int) ($item['producto_id'] ?? $item['referencia_id'] ?? 0);
                $producto = DB::table('inventario.productos as p')
                    ->join('inventario.producto_precios_sede as ps', function ($join) use ($sedeId): void {
                        $join->on('ps.producto_id', '=', 'p.id')
                            ->where('ps.sede_id', '=', $sedeId)
                            ->where('ps.activo', '=', true);
                    })
                    ->leftJoin('inventario.producto_stock_sede as ss', function ($join) use ($sedeId): void {
                        $join->on('ss.producto_id', '=', 'p.id')->where('ss.sede_id', '=', $sedeId);
                    })
                    ->where('p.id', $productoId)
                    ->where('p.activo', true)
                    ->first(['p.id', 'p.nombre', 'p.controla_stock', 'ps.precio', DB::raw('COALESCE(ss.stock_actual, 0) as stock_actual')]);

                if (! $producto) {
                    throw ValidationException::withMessages(['detalles' => 'El producto no está disponible o no tiene precio configurado para la sede.']);
                }
                if ($producto->controla_stock && $cantidad > (float) $producto->stock_actual) {
                    throw ValidationException::withMessages(['detalles' => "Stock insuficiente para {$producto->nombre} en la sede."]);
                }

                $precio = round((float) $producto->precio, 2);
                return [
                    'tipo' => 'PRODUCTO',
                    'referencia_id' => (int) $producto->id,
                    'producto_id' => (int) $producto->id,
                    'descripcion' => $producto->nombre,
                    'cantidad' => $cantidad,
                    'precio_unitario' => $precio,
                    'total_linea' => round($precio * $cantidad, 2),
                ];
            }

            if ($tipo === 'SERVICIO') {
                $servicioId = (int) ($item['referencia_id'] ?? 0);
                $servicio = DB::table('gimnasio.servicios as s')
                    ->join('gimnasio.horarios_servicio as h', function ($join) use ($sedeId): void {
                        $join->on('h.servicio_id', '=', 's.id')->where('h.sede_id', '=', $sedeId)->where('h.activo', '=', true);
                    })
                    ->join('gimnasio.servicio_precios_sede as ps', function ($join) use ($sedeId): void {
                        $join->on('ps.servicio_id', '=', 's.id')->where('ps.sede_id', '=', $sedeId)->where('ps.activo', '=', true);
                    })
                    ->where('s.id', $servicioId)
                    ->where('s.activo', true)
                    ->first(['s.id', 's.nombre', 'ps.precio']);

                if (! $servicio) {
                    throw ValidationException::withMessages(['detalles' => 'El servicio no está disponible o no tiene precio configurado para la sede.']);
                }

                $precio = round((float) $servicio->precio, 2);
                return [
                    'tipo' => 'SERVICIO',
                    'referencia_id' => (int) $servicio->id,
                    'producto_id' => null,
                    'descripcion' => $servicio->nombre,
                    'cantidad' => $cantidad,
                    'precio_unitario' => $precio,
                    'total_linea' => round($precio * $cantidad, 2),
                ];
            }

            if ($tipo === 'MEMBRESIA') {
                $planId = (int) ($item['referencia_id'] ?? 0);
                $plan = DB::table('gimnasio.planes as p')
                    ->leftJoin('gimnasio.plan_precios_sede as ps', function ($join) use ($sedeId): void {
                        $join->on('ps.plan_id', '=', 'p.id')->where('ps.sede_id', '=', $sedeId)->where('ps.activo', '=', true);
                    })
                    ->where('p.id', $planId)
                    ->where('p.activo', true)
                    ->first(['p.id', 'p.nombre', DB::raw('COALESCE(ps.precio, p.precio_base) as precio')]);

                if (! $plan) {
                    throw ValidationException::withMessages(['detalles' => 'La membresía seleccionada no está disponible.']);
                }

                $precio = round((float) $plan->precio, 2);
                return [
                    'tipo' => 'MEMBRESIA',
                    'referencia_id' => (int) $plan->id,
                    'producto_id' => null,
                    'descripcion' => $plan->nombre,
                    'cantidad' => $cantidad,
                    'precio_unitario' => $precio,
                    'total_linea' => round($precio * $cantidad, 2),
                ];
            }

            if ($tipo === 'OTRO') {
                $precio = round((float) ($item['precio_unitario'] ?? 0), 2);
                $descripcion = trim((string) ($item['descripcion'] ?? ''));
                if ($precio <= 0 || $descripcion === '') {
                    throw ValidationException::withMessages(['detalles' => 'Completa descripción y precio para los ítems manuales.']);
                }

                return [
                    'tipo' => 'OTRO',
                    'referencia_id' => null,
                    'producto_id' => null,
                    'descripcion' => $descripcion,
                    'cantidad' => $cantidad,
                    'precio_unitario' => $precio,
                    'total_linea' => round($precio * $cantidad, 2),
                ];
            }

            throw ValidationException::withMessages(['detalles' => 'Existe un tipo de ítem no permitido en la venta.']);
        })->values();
    }

    private function planesPorSede(int $sedeId)
    {
        if (! Schema::connection('pgsql')->hasTable('gimnasio.planes')) {
            return collect();
        }

        $query = DB::table('gimnasio.planes as p')->where('p.activo', true);
        $precio = 'p.precio_base as precio';

        if (Schema::connection('pgsql')->hasTable('gimnasio.plan_precios_sede')) {
            $query->leftJoin('gimnasio.plan_precios_sede as pp', function ($join) use ($sedeId): void {
                $join->on('pp.plan_id', '=', 'p.id')
                    ->where('pp.sede_id', '=', $sedeId)
                    ->where('pp.activo', '=', true);
            });
            $precio = DB::raw('COALESCE(pp.precio, p.precio_base) as precio');
        }

        $columnas = [
            'p.id',
            'p.codigo',
            'p.nombre',
            'p.descripcion',
            'p.tipo_duracion',
            'p.duracion',
            $precio,
            'p.tarifa_inscripcion',
        ];

        $columnas[] = Schema::connection('pgsql')->hasColumn('gimnasio.planes', 'tipo_producto')
            ? 'p.tipo_producto'
            : DB::raw("'MEMBRESIA'::varchar as tipo_producto");

        $columnas[] = Schema::connection('pgsql')->hasColumn('gimnasio.planes', 'tipo_cobro')
            ? 'p.tipo_cobro'
            : DB::raw("'PAGO_UNICO'::varchar as tipo_cobro");

        return $query
            ->select($columnas)
            ->orderBy('p.nombre')
            ->get();
    }

    private function contextoVacio(): array
    {
        return [
            'turno' => null,
            'clientes' => [],
            'servicios' => [],
            'planes' => [],
            'productos' => [],
        ];
    }

    private function tipoVentaGeneral(array $detalles): string
    {
        $tipos = collect($detalles)->pluck('tipo')->filter()->unique();
        return $tipos->count() === 1 && in_array($tipos->first(), ['PRODUCTO', 'MEMBRESIA', 'SERVICIO', 'OTRO'], true)
            ? $tipos->first()
            : 'OTRO';
    }

    private function conceptoGeneral(array $detalles): string
    {
        if (count($detalles) === 1) {
            return (string) $detalles[0]['descripcion'];
        }

        return 'Venta POS - ' . count($detalles) . ' ítems';
    }
}
