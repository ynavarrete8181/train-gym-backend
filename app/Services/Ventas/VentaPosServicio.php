<?php

namespace App\Services\Ventas;

use App\Services\Configuracion\EstadoCatalogoServicio;
use App\Services\Gimnasio\MembresiaServicio;
use App\Services\Gimnasio\DescuentoDeportistaServicio;
use App\Services\Seguridad\AlcanceOperativoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class VentaPosServicio
{
    public function __construct(
        private readonly TurnoCajaServicio $turnos,
        private readonly VentaServicio $ventas,
        private readonly AlcanceOperativoService $alcance,
        private readonly EstadoCatalogoServicio $estados,
        private readonly MembresiaServicio $membresias,
        private readonly DescuentoDeportistaServicio $beneficios,
    ) {
    }

    public function contexto(int $usuarioId): array
    {
        $turno = $this->turnos->turnoAbiertoUsuario($usuarioId);
        if (! $turno) {
            return $this->contextoVacio();
        }

        $sedeId = (int) $turno->sede_id;

        $clientes = DB::table('clientes.deportistas as d')
            ->leftJoin('personas.personas as p', 'p.id', '=', 'd.persona_id')
            ->leftJoin('seguridad.users as u', 'u.id', '=', 'd.usuario_id')
            ->where('d.estado', 'ACTIVO')
            ->orderByRaw('COALESCE(p.nombre_completo, u.name, d.codigo_deportista)')
            ->get([
                'd.id',
                'd.codigo_deportista as codigo',
                'd.telefono',
                'd.sede_principal_id',
                DB::raw('COALESCE(p.nombre_completo, u.name) as nombre'),
                DB::raw('COALESCE(p.email, u.email) as email'),
                DB::raw('COALESCE(p.identificacion, u.cedula) as identificacion'),
            ]);

        $serviciosQuery = DB::table('servicios.servicios as s')
            ->join('agenda.horarios_servicio as h', function ($join) use ($sedeId): void {
                $join->on('h.servicio_id', '=', 's.id')
                    ->where('h.sede_id', '=', $sedeId)
                    ->where('h.activo', '=', true);
            })
            ->leftJoin('servicios.categorias_servicio as c', 'c.id', '=', 's.categoria_id')
            ->where('s.activo', true);

        $columnasServicios = [
            's.id',
            's.nombre',
            's.descripcion',
            's.duracion_minutos',
            's.requiere_reserva',
            'c.nombre as categoria',
        ];

        if (Schema::connection('pgsql')->hasTable('servicios.servicio_precios_sede')) {
            $serviciosQuery->leftJoin('servicios.servicio_precios_sede as ps', function ($join) use ($sedeId): void {
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

        $membresias = DB::table('membresias.membresias as m')
            ->join('membresias.planes as p', 'p.id', '=', 'm.plan_id')
            ->whereExists(function ($query) use ($sedeId): void {
                $query->selectRaw('1')->from('membresias.membresia_sedes as ms')
                    ->whereColumn('ms.membresia_id', 'm.id')
                    ->where('ms.sede_id', $sedeId)->where('ms.activo', true);
            })
            ->whereNotIn('m.estado', ['CANCELADA', 'VENCIDA'])
            ->orderBy('p.nombre')
            ->get([
                'm.id',
                'm.deportista_id as cliente_id',
                'm.plan_id',
                'm.modalidad_id',
                'm.codigo_contrato as codigo',
                'm.fecha_inicio',
                'm.fecha_fin',
                'm.estado',
                'm.precio_aplicado as precio',
                'p.nombre',
                'p.tipo_producto',
            ]);

        // Mostrar también los pases diarios que tienen una cuenta abierta.
        // El frontend puede consultar la membresía, pero no generar un cobro duplicado.
        $cuentasMembresia = collect();
        if ($membresias->isNotEmpty()) {
            $cuentasMembresia = DB::table('membresias.membresia_periodos as mp')
                ->join('ventas.ventas as v', 'v.id', '=', 'mp.venta_id')
                ->whereIn('mp.membresia_id', $membresias->pluck('id')->all())
                ->where('v.estado', '!=', 'ANULADA')
                ->orderByDesc('mp.numero_periodo')
                ->orderByDesc('mp.id')
                ->get(['mp.membresia_id', 'v.id as venta_id', 'v.estado as venta_estado'])
                ->unique('membresia_id')
                ->keyBy('membresia_id');
        }

        // La tarifa visible en el POS corresponde a la sede del turno, no
        // al precio histórico de contratación de la membresía.
        $tarifasSede = $this->planesPorSede($sedeId)->keyBy('id');
        $membresias = $membresias->map(function ($m) use ($tarifasSede, $sedeId, $cuentasMembresia) {
            $cuenta = $cuentasMembresia->get($m->id);
            $m->venta_id = $cuenta?->venta_id;
            $m->venta_estado = $cuenta?->venta_estado;
            $m->cobro_disponible = $cuenta === null;
            $m->precio = $m->modalidad_id
                ? $this->precioModalidadSede((int) $m->modalidad_id, $sedeId)
                : ($tarifasSede->get($m->plan_id)?->precio ?? $m->precio);
            return $m;
        });

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
            'membresias' => $membresias,
            'productos' => $productos,
        ];
    }

    public function cotizarBeneficio(int $usuarioId, int $clienteId, int $planId, ?int $membresiaId = null): array
    {
        $turno = $this->turnos->turnoAbiertoUsuario($usuarioId);
        if (! $turno) {
            throw ValidationException::withMessages(['turno_caja_id' => 'Abre un turno de caja para cotizar.']);
        }
        if (! DB::table('clientes.deportistas')->where('id', $clienteId)->where('estado', 'ACTIVO')->exists()) {
            throw ValidationException::withMessages(['cliente_id' => 'Selecciona un deportista activo.']);
        }
        $plan = DB::table('membresias.planes')->where('id', $planId)->where('activo', true)->first();
        if (! $plan) {
            throw ValidationException::withMessages(['plan_id' => 'El plan no está activo.']);
        }
        $modalidadId = null;
        $fechaCotizacion = now('America/Guayaquil')->toDateString();
        if ($membresiaId) {
            $m = DB::table('membresias.membresias')->where('id', $membresiaId)
                ->where('deportista_id', $clienteId)->where('plan_id', $planId)
                ->whereExists(function ($query) use ($turno): void {
                    $query->selectRaw('1')->from('membresias.membresia_sedes as ms')
                        ->whereColumn('ms.membresia_id', 'membresias.membresias.id')
                        ->where('ms.sede_id', $turno->sede_id)->where('ms.activo', true);
                })->first();
            if (! $m) throw ValidationException::withMessages(['membresia_id' => 'Membresía no válida para esta sede.']);
            $modalidadId = $m->modalidad_id;
            if ($plan->tipo_producto === 'PASE_DIARIO') {
                $fechaCotizacion = substr((string) $m->fecha_inicio, 0, 10);
                if ($fechaCotizacion < now('America/Guayaquil')->toDateString()) {
                    throw ValidationException::withMessages([
                        'membresia_id' => 'El Pase Diario corresponde a una fecha pasada. Revisa la vigencia.',
                    ]);
                }
            }
            $precio = $modalidadId
                ? $this->precioModalidadSede((int) $modalidadId, (int) $turno->sede_id)
                : (float) ($this->planesPorSede((int) $turno->sede_id)
                    ->firstWhere('id', $planId)?->precio ?? 0);
        } else {
            if ($plan->tipo_producto !== 'PASE_DIARIO') {
                throw ValidationException::withMessages(['plan_id' => 'La cotización directa solo aplica al Pase Diario.']);
            }
            if ($plan->requiere_entrenador) {
                throw ValidationException::withMessages(['plan_id' => 'El Pase Diario Dirigido requiere asignación de entrenador y horario.']);
            }
            $precio = (float) DB::table('membresias.plan_precios_sede')
                ->where('plan_id', $planId)->where('sede_id', $turno->sede_id)
                ->where('activo', true)->value('precio');
        }
        if ($precio <= 0) throw ValidationException::withMessages(['plan_id' => 'Configura una tarifa positiva para esta sede.']);
        $cotizacion = $this->beneficios->resolver($clienteId, $planId, $modalidadId, $fechaCotizacion, $precio);
        $cotizacion['fecha_uso_pase'] = $plan->tipo_producto === 'PASE_DIARIO' ? $fechaCotizacion : null;
        return $cotizacion;
    }

    public function cuentasAbiertas(int $usuarioId): array
    {
        $turno = $this->turnos->turnoAbiertoUsuario($usuarioId);
        $puedeGestionarCartera = $this->alcance->puedeGestionarCartera($usuarioId);

        $query = DB::table('ventas.ventas as v')
            ->leftJoin('clientes.deportistas as d', 'd.id', '=', 'v.cliente_id')
            ->leftJoin('personas.personas as persona', 'persona.id', '=', 'd.persona_id')
            ->leftJoin('seguridad.users as u', 'u.id', '=', 'd.usuario_id')
            ->leftJoin('seguridad.users as generado_por', 'generado_por.id', '=', 'v.usuario_id')
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->leftJoin('membresias.planes as p', 'p.id', '=', 'm.plan_id')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', DB::raw('COALESCE(c.sede_id, m.sede_id)'))
            ->whereIn('v.estado', ['PENDIENTE', 'PARCIAL']);

        if ($puedeGestionarCartera) {
            $sedes = $this->alcance->sedesPermitidas($usuarioId, 'maneja_caja');

            if (empty($sedes)) {
                return [];
            }

            $query->whereIn(DB::raw('COALESCE(c.sede_id, m.sede_id)'), $sedes);
        } else {
            if (! $turno) {
                return [];
            }

            $sedeId = (int) $turno->sede_id;

            // El cajero con turno propio activo puede cobrar cualquier cuenta
            // pendiente/parcial de su sede, sin importar quién la generó.
            // La responsabilidad de caja queda en el pago/turno, no en el origen de la venta.
            $query->whereRaw('COALESCE(c.sede_id, m.sede_id) = ?', [$sedeId]);
        }

        return $query
            ->orderBy('v.fecha_venta')
            ->orderBy('v.id')
            ->get([
                'v.*',
                DB::raw("COALESCE(NULLIF(TRIM(persona.nombre_completo), ''), NULLIF(TRIM(u.name), ''), 'Consumidor final') as cliente_nombre"),
                DB::raw("COALESCE(NULLIF(TRIM(persona.identificacion), ''), NULLIF(TRIM(u.cedula), '')) as cliente_identificacion"),
                DB::raw("CASE WHEN v.generado_por_tipo = 'SISTEMA' THEN 'Sistema' ELSE COALESCE(NULLIF(TRIM(generado_por.name), ''), 'Sistema') END as generado_por_nombre"),
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
        return DB::transaction(function () use ($datos, $usuarioId, $ventaId): object {
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

        $pases = $detallesEntrada->filter(fn ($item) => strtoupper((string) ($item['tipo'] ?? '')) === 'PASE_DIARIO');
        if ($pases->isNotEmpty()) {
            if ($ventaId || $detallesEntrada->count() !== 1 || $pases->count() !== 1 || (float) ($pases->first()['cantidad'] ?? 0) !== 1.0) {
                throw ValidationException::withMessages(['detalles' => 'El Pase Diario se registra como una venta individual de una unidad.']);
            }
            $clienteId = (int) ($datos['cliente_id'] ?? 0);
            $planId = (int) ($pases->first()['referencia_id'] ?? 0);
            $cotizacion = $this->cotizarBeneficio($usuarioId, $clienteId, $planId);
            $turnoSede = $this->turnos->turnoAbiertoUsuario($usuarioId);
            $hoy = now('America/Guayaquil')->toDateString();
            // Impide duplicar un acceso del mismo plan/sede/fecha, aun con varias cajas.
            DB::table('clientes.deportistas')->where('id', $clienteId)->lockForUpdate()->first();
            $yaExiste = DB::table('membresias.membresias')
                ->where('deportista_id', $clienteId)->where('plan_id', $planId)
                ->where('sede_id', $turnoSede->sede_id)->whereDate('fecha_inicio', $hoy)
                ->whereNotIn('estado', ['CANCELADA', 'VENCIDA'])->exists();
            if ($yaExiste) {
                throw ValidationException::withMessages(['plan_id' => 'Este deportista ya tiene un pase del mismo tipo para hoy en esta sede.']);
            }
            $nueva = $this->membresias->crear([
                'deportista_id' => $clienteId, 'plan_id' => $planId,
                'sede_id' => (int) $turnoSede->sede_id,
                'sedes_habilitadas' => [(int) $turnoSede->sede_id],
                'fecha_inicio' => $hoy, 'renovacion_automatica' => false,
            ]);
            $datos['membresia_id'] = (int) $nueva->id;
            $detallesEntrada = collect([[
                'tipo' => 'MEMBRESIA', 'membresia_id' => (int) $nueva->id,
                'cantidad' => 1, 'referencia_id' => $planId,
            ]]);
        }

        $membresiaId = $datos['membresia_id']
            ?? $detallesEntrada->pluck('membresia_id')->filter()->first();

        if (! $membresiaId && $ventaId) {
            $membresiaId = DB::table('ventas.ventas')
                ->where('id', $ventaId)
                ->value('membresia_id');

            if ($membresiaId) {
                $datos['membresia_id'] = (int) $membresiaId;
                $detallesEntrada = $detallesEntrada->map(function (array $item) use ($membresiaId): array {
                    if (strtoupper((string) ($item['tipo'] ?? '')) === 'MEMBRESIA' && empty($item['membresia_id'])) {
                        $item['membresia_id'] = (int) $membresiaId;
                    }

                    return $item;
                })->values();
            }
        }

        if ($membresiaId) {
            $membresia = DB::table('membresias.membresias')
                ->where('id', $membresiaId)
                ->first();

            if (! $membresia) {
                throw ValidationException::withMessages(['membresia_id' => 'La membresía seleccionada no existe.']);
            }

            if (! empty($datos['cliente_id']) && (int) $membresia->deportista_id !== (int) $datos['cliente_id']) {
                throw ValidationException::withMessages(['membresia_id' => 'La membresía no pertenece al cliente seleccionado.']);
            }

            $accesoSede = DB::table('membresias.membresia_sedes')
                ->where('membresia_id', $membresiaId)
                ->where('sede_id', $turno->sede_id)->where('activo', true)->exists();
            if (! $accesoSede) {
                throw ValidationException::withMessages(['membresia_id' => 'La membresía no habilita el acceso a esta sede.']);
            }

            if (in_array(strtoupper((string) $membresia->estado), ['CANCELADA', 'VENCIDA'], true)) {
                throw ValidationException::withMessages(['membresia_id' => 'La membresía ya no está vigente.']);
            }

            $ventaPendiente = DB::table('ventas.ventas')
                ->where('membresia_id', $membresiaId)
                ->whereIn('estado', ['PENDIENTE', 'PARCIAL'])
                ->exists();

            if ($ventaPendiente && ! $ventaId) {
                throw ValidationException::withMessages([
                    'membresia_id' => 'Esta membresía ya tiene una venta pendiente. Abre la cuenta existente para cobrarla.',
                ]);
            }

            $datos['membresia_id'] = (int) $membresiaId;
        }

        $detalles = $this->resolverDetalles($detallesEntrada, (int) $turno->sede_id, $ventaId);
        $subtotal = round((float) $detalles->sum('total_linea'), 2);
        $descuento = round((float) ($datos['descuento'] ?? 0), 2);
        $beneficioAplicado = null;
        if ($ventaId) {
            // Una cuenta ya creada mantiene su descuento original, incluso si el beneficio cambia.
            $descuento = (float) DB::table('ventas.ventas')->where('id', $ventaId)->value('descuento');
        } elseif ($membresiaId) {
            $m = DB::table('membresias.membresias')->where('id', $membresiaId)->first();
            $beneficioAplicado = $this->beneficios->resolver(
                (int) $m->deportista_id, (int) $m->plan_id,
                $m->modalidad_id ? (int) $m->modalidad_id : null,
                DB::table('membresias.planes')->where('id', $m->plan_id)->value('tipo_producto') === 'PASE_DIARIO'
                    ? substr((string) $m->fecha_inicio, 0, 10)
                    : now('America/Guayaquil')->toDateString(),
                $subtotal
            );
            // El descuento contractual procede únicamente del beneficio autorizado.
            $descuento = (float) $beneficioAplicado['importe_descuento'];
        }
        $impuesto = round((float) ($datos['impuesto'] ?? 0), 2);

        if ($descuento > $subtotal) {
            throw ValidationException::withMessages(['descuento' => 'El descuento no puede superar el subtotal de la venta.']);
        }

        $total = round($subtotal - $descuento + $impuesto, 2);
        if ($total <= 0) {
            throw ValidationException::withMessages(['total' => 'El total de la venta debe ser mayor que cero.']);
        }

        return DB::transaction(function () use ($datos, $detalles, $subtotal, $descuento, $impuesto, $total, $usuarioId, $turno, $ventaId, $membresiaId, $beneficioAplicado, $pases): object {
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
                'membresia_id' => $datos['membresia_id'] ?? $ventaExistente?->membresia_id,
                'caja_id' => (int) $turno->caja_id,
                'turno_caja_id' => (int) $turno->id,
                'tipo_venta' => $ventaExistente?->tipo_venta ?? (
                    $pases->isNotEmpty() || ($membresiaId && DB::table('membresias.membresias as m')
                        ->join('membresias.planes as pl', 'pl.id', '=', 'm.plan_id')
                        ->where('m.id', $membresiaId)->where('pl.tipo_producto', 'PASE_DIARIO')->exists())
                    ? 'SERVICIO' : $this->tipoVentaGeneral($detalles->all())
                ),
                'origen_tipo' => $ventaExistente?->origen_tipo ?? ($membresiaId ? 'MEMBRESIA_CAJA' : 'POS'),
                'origen_id' => $ventaExistente?->origen_id ?? ($membresiaId ? (int) $membresiaId : null),
                'generado_por_tipo' => $ventaExistente?->generado_por_tipo ?? 'USUARIO',
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

            if (! empty($venta->membresia_id)) {
                $periodoId = DB::table('membresias.membresia_periodos')
                    ->where('membresia_id', $venta->membresia_id)
                    ->orderByDesc('numero_periodo')
                    ->value('id');

                if ($periodoId) {
                    DB::table('membresias.membresia_periodos')
                        ->where('id', $periodoId)
                        ->whereNull('venta_id')
                        ->update([
                            'venta_id' => $venta->id,
                            'updated_at' => now(),
                        ]);
                }
            }

            if ($beneficioAplicado && $venta->membresia_id) {
                DB::table('membresias.membresia_periodos')
                    ->where('membresia_id', $venta->membresia_id)
                    ->where('venta_id', $venta->id)
                    ->update([
                        'descuento_id' => $beneficioAplicado['descuento_id'],
                        'descuento_importe' => $beneficioAplicado['importe_descuento'],
                        'updated_at' => now(),
                    ]);
            }

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

            $pagosEntrada = collect($datos['pagos'] ?? [])->filter(fn ($pago) => (float) ($pago['monto'] ?? 0) > 0)->values();

            if ($pagosEntrada->isEmpty()) {
                $pagosEntrada = collect([[
                    'metodo_pago' => $datos['metodo_pago'] ?? 'EFECTIVO',
                    'monto' => $saldo,
                    'referencia' => $datos['referencia_pago'] ?? null,
                    'observaciones' => $datos['observaciones_pago'] ?? 'Cobro registrado desde POS.',
                ]]);
            }

            $montoCobro = round((float) $pagosEntrada->sum(fn ($pago) => (float) ($pago['monto'] ?? 0)), 2);
            if ($montoCobro <= 0) {
                throw ValidationException::withMessages(['pagos' => 'Registra al menos un pago mayor que cero.']);
            }
            if ($montoCobro > $saldo + 0.00001) {
                throw ValidationException::withMessages(['pagos' => 'La suma de los pagos supera el saldo pendiente de la venta.']);
            }

            $operacionCobroId = 'COBRO-OP-' . now()->format('YmdHis') . '-' . random_int(1000, 9999);
            $codigoCobro = $this->ventas->siguienteCodigoCobro();

            $pagos = collect();
            foreach ($pagosEntrada as $pagoEntrada) {
                $metodo = strtoupper((string) $pagoEntrada['metodo_pago']);
                $monto = round((float) $pagoEntrada['monto'], 2);
                $esEfectivo = $metodo === 'EFECTIVO';

                // El monto es el ingreso neto de la venta. El dinero entregado y
                // el cambio son datos de trazabilidad, no ingresos adicionales.
                if ($esEfectivo) {
                    $recibido = round((float) ($pagoEntrada['efectivo_recibido'] ?? $monto), 2);
                    if ($recibido < $monto) {
                        throw ValidationException::withMessages([
                            'pagos' => 'El efectivo recibido no puede ser menor al importe cobrado.',
                        ]);
                    }
                    $cambio = round($recibido - $monto, 2);
                    if (array_key_exists('cambio_entregado', $pagoEntrada)
                        && $pagoEntrada['cambio_entregado'] !== null
                        && abs(round((float) $pagoEntrada['cambio_entregado'], 2) - $cambio) > 0.001) {
                        throw ValidationException::withMessages([
                            'pagos' => 'El cambio enviado no coincide con el efectivo recibido y el importe cobrado.',
                        ]);
                    }
                } else {
                    if ((! empty($pagoEntrada['efectivo_recibido'])) || (! empty($pagoEntrada['cambio_entregado']))) {
                        throw ValidationException::withMessages([
                            'pagos' => 'El efectivo recibido y el cambio solo corresponden a pagos en efectivo.',
                        ]);
                    }
                    $recibido = null;
                    $cambio = null;
                }

                $pagos->push($this->ventas->guardarPago([
                    'venta_id' => (int) $venta->id,
                    'caja_id' => (int) $turno->caja_id,
                    'turno_caja_id' => (int) $turno->id,
                    'operacion_cobro_id' => $operacionCobroId,
                    'codigo_cobro' => $codigoCobro,
                    'metodo_pago' => $metodo,
                    'monto' => $monto,
                    'efectivo_recibido' => $recibido,
                    'cambio_entregado' => $cambio,
                    'estado' => 'CONFIRMADO',
                    'referencia' => $pagoEntrada['referencia'] ?? null,
                    'observaciones' => $pagoEntrada['observaciones'] ?? 'Cobro registrado desde POS.',
                ], $usuarioId));
            }

            $ventaActualizada = DB::table('ventas.ventas')->where('id', $venta->id)->first();
            $ventaActualizada->detalles = DB::table('ventas.venta_detalles')->where('venta_id', $venta->id)->orderBy('id')->get();
            $ventaActualizada->pagos = DB::table('ventas.pagos')
                ->where('venta_id', $venta->id)
                ->where('estado', 'CONFIRMADO')
                ->orderBy('id')
                ->get();
            $ventaActualizada->pago = $pagos->last();
            $ventaActualizada->saldo_pendiente = max(
                0,
                round((float) $ventaActualizada->total - (float) $ventaActualizada->pagos->sum('monto'), 2)
            );

            if ($ventaActualizada->saldo_pendiente <= 0 && ! empty($ventaActualizada->membresia_id)) {
                $estadoActivoId = $this->estados->idPorValor('MEMBRESIA', 'ACTIVA');
                DB::table('membresias.membresias')
                    ->where('id', $ventaActualizada->membresia_id)
                    ->where('estado', 'PENDIENTE_PAGO')
                    ->update([
                        'estado' => 'ACTIVA',
                        'estado_id' => $estadoActivoId,
                        'updated_at' => now(),
                    ]);

                DB::table('membresias.membresia_periodos')
                    ->where('membresia_id', $ventaActualizada->membresia_id)
                    ->where('venta_id', $ventaActualizada->id)
                    ->update([
                        'estado' => 'ACTIVA',
                        'updated_at' => now(),
                    ]);
            }

            $ventaActualizada->comprobante = DB::table('ventas.comprobantes')->where('venta_id', $venta->id)->first();

            return $ventaActualizada;
        });
    }

    private function resolverDetalles($detalles, int $sedeId, ?int $ventaId = null)
    {
        return $detalles->map(function ($item) use ($sedeId, $ventaId): array {
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
                $servicio = DB::table('servicios.servicios as s')
                    ->join('agenda.horarios_servicio as h', function ($join) use ($sedeId): void {
                        $join->on('h.servicio_id', '=', 's.id')->where('h.sede_id', '=', $sedeId)->where('h.activo', '=', true);
                    })
                    ->join('servicios.servicio_precios_sede as ps', function ($join) use ($sedeId): void {
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
                $membresiaId = (int) ($item['membresia_id'] ?? 0);

                $membresia = DB::table('membresias.membresias as m')
                    ->join('membresias.planes as p', 'p.id', '=', 'm.plan_id')
                    ->leftJoin('membresias.plan_modalidades as pm', 'pm.id', '=', 'm.modalidad_id')
                    ->where('m.id', $membresiaId)
                    ->whereExists(function ($q) use ($sedeId): void {
                        $q->selectRaw('1')->from('membresias.membresia_sedes as ms')
                            ->whereColumn('ms.membresia_id', 'm.id')
                            ->where('ms.sede_id', $sedeId)->where('ms.activo', true);
                    })
                    ->whereNotIn('m.estado', ['CANCELADA', 'VENCIDA'])
                    ->first([
                        'm.id',
                        'm.plan_id',
                        'm.modalidad_id',
                        'm.codigo_contrato',
                        'm.precio_aplicado',
                        'm.estado',
                        'p.nombre as plan_nombre',
                        'pm.nombre as modalidad_nombre',
                    ]);

                if (! $membresia) {
                    throw ValidationException::withMessages([
                        'detalles' => 'La membresía seleccionada no está disponible para esta sede.',
                    ]);
                }

                $periodoFacturado = DB::table('membresias.membresia_periodos as mp')
                    ->join('ventas.ventas as v', 'v.id', '=', 'mp.venta_id')
                    ->where('mp.membresia_id', $membresia->id)
                    ->whereRaw('mp.numero_periodo = (SELECT MAX(mp2.numero_periodo) FROM membresias.membresia_periodos mp2 WHERE mp2.membresia_id = mp.membresia_id)')
                    ->where('v.estado', '!=', 'ANULADA')
                    ->when($ventaId, fn ($q) => $q->where('v.id', '!=', $ventaId))
                    ->exists();
                if ($periodoFacturado) {
                    throw ValidationException::withMessages([
                        'detalles' => 'El período actual ya fue facturado. No se puede volver a cobrar el mismo pase.',
                    ]);
                }

                $ventaPendiente = DB::table('ventas.ventas')
                    ->where('membresia_id', $membresia->id)
                    ->whereIn('estado', ['PENDIENTE', 'PARCIAL'])
                    ->when($ventaId, fn ($query) => $query->where('id', '!=', $ventaId))
                    ->exists();

                if ($ventaPendiente) {
                    throw ValidationException::withMessages([
                        'detalles' => 'Esta membresía ya tiene otra cuenta pendiente. Ábrela desde Cuentas abiertas.',
                    ]);
                }

                $precio = $membresia->modalidad_id
                    ? $this->precioModalidadSede((int) $membresia->modalidad_id, $sedeId)
                    : (float) ($this->planesPorSede($sedeId)->firstWhere('id', $membresia->plan_id)?->precio ?? 0);
                $precio = round($precio, 2);
                if ($precio <= 0) {
                    throw ValidationException::withMessages([
                        'detalles' => 'La membresía seleccionada no tiene un precio aplicado válido.',
                    ]);
                }

                if (strtoupper((string) $membresia->estado) === 'PENDIENTE_PAGO'
                    && DB::table('membresias.planes')->where('id', $membresia->plan_id)
                        ->where('tipo_producto', 'PASE_DIARIO')->exists()) {
                    $hoy = now('America/Guayaquil')->toDateString();
                    $fechaInicio = substr((string) DB::table('membresias.membresias')
                        ->where('id', $membresia->id)->value('fecha_inicio'), 0, 10);
                    if ($fechaInicio < $hoy) {
                        throw ValidationException::withMessages([
                            'membresia_id' => 'El Pase Diario corresponde a una fecha anterior. Verifica su vigencia.',
                        ]);
                    }
                    // El cobro puede ser anticipado; la fecha de acceso no cambia.
                }

                $descripcion = $membresia->plan_nombre
                    . ($membresia->modalidad_nombre ? ' · ' . $membresia->modalidad_nombre : '')
                    . ' · ' . $membresia->codigo_contrato;

                return [
                    'tipo' => 'MEMBRESIA',
                    'referencia_id' => (int) $membresia->plan_id,
                    'membresia_id' => (int) $membresia->id,
                    'producto_id' => null,
                    'descripcion' => $descripcion,
                    'cantidad' => 1,
                    'precio_unitario' => $precio,
                    'total_linea' => $precio,
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

    private function precioModalidadSede(int $modalidadId, int $sedeId): float
    {
        $sede = DB::table('membresias.plan_modalidad_precios_sede')
            ->where('modalidad_id', $modalidadId)->where('sede_id', $sedeId)
            ->where('activo', true)->value('precio');
        return (float) ($sede ?? DB::table('membresias.plan_modalidades')
            ->where('id', $modalidadId)->value('precio_base'));
    }

    private function planesPorSede(int $sedeId)
    {
        if (! Schema::connection('pgsql')->hasTable('membresias.planes')) {
            return collect();
        }

        $query = DB::table('membresias.planes as p')->where('p.activo', true);
        $precio = 'p.precio_base as precio';

        if (Schema::connection('pgsql')->hasTable('membresias.plan_precios_sede')) {
            $query->leftJoin('membresias.plan_precios_sede as pp', function ($join) use ($sedeId): void {
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
            'p.requiere_entrenador',
            $precio,
            'p.tarifa_inscripcion',
        ];

        $columnas[] = Schema::connection('pgsql')->hasColumn('membresias.planes', 'tipo_producto')
            ? 'p.tipo_producto'
            : DB::raw("'MEMBRESIA'::varchar as tipo_producto");

        $columnas[] = Schema::connection('pgsql')->hasColumn('membresias.planes', 'tipo_cobro')
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
            'membresias' => [],
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
