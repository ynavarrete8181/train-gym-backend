<?php

namespace App\Services\Ventas;

use App\Services\Concerns\RegistraAuditoria;
use App\Services\Configuracion\EstadoCatalogoServicio;
use App\Services\CuentasCobrar\CarteraServicio;
use App\Services\Inventario\InventarioServicio;
use App\Services\Seguridad\AlcanceOperativoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class VentaServicio
{
    use RegistraAuditoria;

    public function __construct(
        private readonly EstadoCatalogoServicio $estados,
        private readonly AlcanceOperativoService $alcance,
        private readonly InventarioServicio $inventario,
        private readonly CarteraServicio $cartera,
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
            ->leftJoin('clientes.deportistas', 'ventas.ventas.cliente_id', '=', 'clientes.deportistas.id')
            ->leftJoin('personas.personas as persona_cliente', 'clientes.deportistas.persona_id', '=', 'persona_cliente.id')
            ->leftJoin('seguridad.users as cliente_user', 'clientes.deportistas.usuario_id', '=', 'cliente_user.id')
            ->leftJoin('ventas.cajas', 'ventas.ventas.caja_id', '=', 'ventas.cajas.id')
            ->leftJoin('membresias.membresias as membresia_sede', 'ventas.ventas.membresia_id', '=', 'membresia_sede.id')
            ->leftJoin('membresias.planes as plan_venta', 'plan_venta.id', '=', 'membresia_sede.plan_id')
            ->leftJoin('institucional.sedes as sede_operacion', DB::raw('COALESCE(ventas.cajas.sede_id, membresia_sede.sede_id)'), '=', 'sede_operacion.id_sede')
            ->leftJoin('configuracion.estados_catalogo as estado_cfg', 'ventas.ventas.estado_id', '=', 'estado_cfg.id')
            ->leftJoin('seguridad.users as generado_por', 'ventas.ventas.usuario_id', '=', 'generado_por.id')
            ->leftJoin('seguridad.cpu_userrole as rol_generador', 'generado_por.usr_tipo', '=', 'rol_generador.id_userrole')
            ->leftJoin('seguridad.users as responsable_comercial', 'ventas.ventas.responsable_comercial_id', '=', 'responsable_comercial.id')
            ->select(
                'ventas.ventas.*',
                'generado_por.name as generado_por_nombre',
                'rol_generador.role as generado_por_rol',
                'responsable_comercial.name as responsable_comercial_nombre',
                'cliente_user.name as cliente_nombre',
                'clientes.deportistas.codigo_deportista',
                'ventas.cajas.nombre as caja_nombre',
                DB::raw('COALESCE(ventas.cajas.sede_id, membresia_sede.sede_id) as sede_id'),
                'sede_operacion.nombre as sede_nombre',
                'estado_cfg.codigo as estado_codigo',
                'estado_cfg.valor_interno as estado_valor',
                'estado_cfg.nombre as estado_nombre',
                'estado_cfg.color as estado_color',
                'membresia_sede.codigo_contrato as membresia_codigo',
                'plan_venta.nombre as plan_nombre',
                'plan_venta.tipo_producto as plan_tipo_producto',
                DB::raw("(ventas.ventas.total - COALESCE((SELECT SUM(p.monto) FROM ventas.pagos p WHERE p.venta_id = ventas.ventas.id AND p.estado = 'CONFIRMADO'), 0)) as saldo_pendiente")
            );

        $this->aplicarAlcanceVenta($query, $usuarioId);
        $this->aplicarVisibilidadComercialVenta($query, $usuarioId, 'ventas.ventas');
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
                $this->validarSedeCobroMembresia(
                    (int) $membresiaId,
                    (int) $sedeCaja
                );
            }

            $sedeOperacion = $sedeCaja ?: $sedeMembresia;
            $this->alcance->validarSede($usuarioId, $sedeOperacion, 'maneja_caja');

            $datos = $this->estados->aplicar($datos, 'VENTA');
            $datos['usuario_id'] = array_key_exists('usuario_id', $datos)
                ? $datos['usuario_id']
                : ($existente?->usuario_id ?? $usuarioId);
            $datos['generado_por_tipo'] = $datos['generado_por_tipo']
                ?? $existente?->generado_por_tipo
                ?? ($datos['usuario_id'] ? 'USUARIO' : 'SISTEMA');
            $datos['origen_tipo'] = $datos['origen_tipo']
                ?? $existente?->origen_tipo
                ?? (! empty($membresiaId) ? 'MEMBRESIA' : 'POS');
            $datos['origen_id'] = array_key_exists('origen_id', $datos)
                ? $datos['origen_id']
                : ($existente?->origen_id ?? (! empty($membresiaId) ? (int) $membresiaId : null));
            $datos['responsable_comercial_id'] = array_key_exists('responsable_comercial_id', $datos)
                ? $datos['responsable_comercial_id']
                : $existente?->responsable_comercial_id;
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
                'tipo_item' => $detalle['tipo_item'] ?? null,
                'referencia_id' => $detalle['referencia_id'] ?? null,
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

            $this->cartera->sincronizarVenta($ventaId);

            return $this->obtenerVenta($ventaId);
        });
    }

    public function detalleVenta(int $ventaId, ?int $usuarioId = null): object
    {
        $sedeId = $this->alcance->sedeDeVenta($ventaId);
        $cuenta = DB::table('ventas.ventas')
            ->where('id', $ventaId)
            ->first(['membresia_id', 'estado']);

        // El detalle de una cuenta pendiente puede atenderse en el turno
        // abierto de cualquier sede autorizada de la membresía.
        if ($cuenta?->membresia_id
            && in_array(strtoupper((string) $cuenta->estado), ['PENDIENTE', 'PARCIAL'], true)) {
            $turno = DB::table('ventas.turnos_caja')
                ->where('usuario_id', $usuarioId)
                ->where('estado', 'ABIERTA')
                ->first(['sede_id']);
            if ($turno) {
                $sedeTurno = (int) $turno->sede_id;
                $autorizada = DB::table('membresias.membresia_sedes')
                    ->where('membresia_id', (int) $cuenta->membresia_id)
                    ->where('sede_id', $sedeTurno)
                    ->where('activo', true)
                    ->exists();
                if ($autorizada) {
                    $sedeId = $sedeTurno;
                }
            }
        }

        $this->alcance->validarSede($usuarioId, $sedeId, 'maneja_caja');
        $this->validarVisibilidadDetalleVenta($ventaId, $usuarioId, $sedeId);

        $venta = DB::table('ventas.ventas as v')
            ->leftJoin('clientes.deportistas as d', 'd.id', '=', 'v.cliente_id')
            ->leftJoin('seguridad.users as cu', 'cu.id', '=', 'd.usuario_id')
            ->leftJoin('personas.personas as persona', 'persona.id', '=', 'd.persona_id')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->leftJoin('membresias.planes as pm', 'pm.id', '=', 'm.plan_id')
            ->leftJoin('membresias.plan_modalidades as modalidad', 'modalidad.id', '=', 'm.modalidad_id')
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', DB::raw('COALESCE(c.sede_id, m.sede_id)'))
            ->leftJoin('configuracion.estados_catalogo as e', 'e.id', '=', 'v.estado_id')
            ->leftJoin('seguridad.users as generado_por', 'generado_por.id', '=', 'v.usuario_id')
            ->leftJoin('seguridad.users as responsable_comercial', 'responsable_comercial.id', '=', 'v.responsable_comercial_id')
            ->where('v.id', $ventaId)
            ->select(
                'v.*',
                DB::raw("COALESCE(NULLIF(TRIM(persona.nombre_completo), ''), NULLIF(TRIM(cu.name), ''), 'Consumidor final') as cliente_nombre"),
                DB::raw("COALESCE(NULLIF(TRIM(persona.email), ''), NULLIF(TRIM(cu.email), '')) as cliente_email"),
                DB::raw("COALESCE(NULLIF(TRIM(persona.identificacion), ''), NULLIF(TRIM(cu.cedula), '')) as cliente_identificacion"),
                'd.codigo_deportista',
                'c.nombre as caja_nombre',
                'c.codigo as caja_codigo',
                's.nombre as sede_nombre',
                'm.codigo_contrato as membresia_codigo',
                'm.plan_id as membresia_plan_id',
                'pm.nombre as membresia_plan_nombre',
                'pm.tipo_producto as membresia_tipo_producto',
                'm.estado as membresia_estado',
                'modalidad.nombre as membresia_modalidad_nombre',
                'm.fecha_inicio as membresia_fecha_inicio',
                'm.fecha_fin as membresia_fecha_fin',
                'm.precio_aplicado as membresia_precio_aplicado',
                'e.nombre as estado_nombre',
                'e.color as estado_color',
                'generado_por.name as generado_por_nombre',
                'responsable_comercial.name as responsable_comercial_nombre'
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
            ->leftJoin('seguridad.users as cobrador', 'cobrador.id', '=', 'p.usuario_id')
            ->where('p.venta_id', $ventaId)
            ->orderBy('p.id')
            ->get([
                'p.*',
                'e.nombre as estado_nombre',
                'e.color as estado_color',
                'cobrador.name as cobrado_por_nombre',
            ]);

        $venta->comprobante = DB::table('ventas.comprobantes')
            ->where('venta_id', $ventaId)
            ->first();

        // Período que cobra esta venta: su tarifa, descuento y fechas son los del
        // comprobante (la membresía guarda solo el precio con el que se contrató).
        $venta->membresia_periodo = $venta->membresia_id
            ? DB::table('membresias.membresia_periodos')->where('venta_id', $ventaId)->orderByDesc('numero_periodo')
                ->first(['numero_periodo', 'fecha_inicio', 'fecha_fin', 'precio', 'descuento_importe', 'descuento_id'])
            : null;
        $venta->descuento_detalle = ! empty($venta->membresia_periodo?->descuento_id)
            ? DB::table('membresias.descuentos_deportistas as dd')
                ->leftJoin('membresias.planes as pl', 'pl.id', '=', 'dd.plan_id')
                ->where('dd.id', $venta->membresia_periodo->descuento_id)
                ->first(['dd.tipo', 'dd.valor', 'dd.motivo', 'dd.fecha_fin', 'pl.nombre as plan_nombre'])
            : null;

        $venta->sedes_membresia = collect();
        $venta->entrenadores_membresia = collect();

        if ($venta->membresia_id) {
            $venta->sedes_membresia = DB::table('membresias.membresia_sedes as ms')
                ->join('institucional.sedes as s', 's.id_sede', '=', 'ms.sede_id')
                ->where('ms.membresia_id', $venta->membresia_id)
                ->where('ms.activo', true)
                ->orderByDesc('ms.es_principal')
                ->orderBy('s.nombre')
                ->get(['ms.sede_id', 'ms.es_principal', 's.nombre as sede_nombre']);

            $venta->entrenadores_membresia = DB::table('entrenamiento.asignaciones_entrenador_cliente as a')
                ->join('entrenamiento.entrenadores as e', 'e.id', '=', 'a.entrenador_id')
                ->join('seguridad.users as u', 'u.id', '=', 'e.usuario_id')
                ->leftJoin('institucional.sedes as s', 's.id_sede', '=', 'a.sede_id')
                ->leftJoin('agenda.horario_bloques as hb', 'hb.id', '=', 'a.horario_bloque_id')
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
        // Compatibilidad entre bases locales que aún no han aplicado la
        // migración del desglose de efectivo y las que ya la incluyen.
        $esquemaPagos = Schema::connection('pgsql');
        $tieneEfectivoRecibido = $esquemaPagos->hasColumn('ventas.pagos', 'efectivo_recibido');
        $tieneCambioEntregado = $esquemaPagos->hasColumn('ventas.pagos', 'cambio_entregado');

        $operacionExpr = "COALESCE(p.operacion_cobro_id, 'PAGO-' || p.id::text)";

        $query = DB::table('ventas.pagos as p')
            ->join('ventas.ventas as v', 'p.venta_id', '=', 'v.id')
            ->leftJoin('ventas.cajas as caja_venta', 'v.caja_id', '=', 'caja_venta.id')
            ->leftJoin('membresias.membresias as membresia_sede', 'v.membresia_id', '=', 'membresia_sede.id')
            ->leftJoin('ventas.cajas as caja_pago', 'p.caja_id', '=', 'caja_pago.id')
            ->leftJoin('ventas.turnos_caja as turno', 'p.turno_caja_id', '=', 'turno.id')
            ->leftJoin('clientes.deportistas as d', 'v.cliente_id', '=', 'd.id')
            ->leftJoin('personas.personas as persona', 'd.persona_id', '=', 'persona.id')
            ->leftJoin('seguridad.users as cliente_user', 'd.usuario_id', '=', 'cliente_user.id')
            ->leftJoin('seguridad.users as cobrador', 'p.usuario_id', '=', 'cobrador.id')
            ->leftJoin('seguridad.users as creador_venta', 'v.usuario_id', '=', 'creador_venta.id')
            ->leftJoin('seguridad.cpu_userrole as rol_creador', 'creador_venta.usr_tipo', '=', 'rol_creador.id_userrole')
            ->leftJoin('seguridad.cpu_userrole as rol_cobrador', 'cobrador.usr_tipo', '=', 'rol_cobrador.id_userrole')
            ->selectRaw("MAX(p.id) as id")
            ->selectRaw("{$operacionExpr} as operacion_cobro_id")
            ->selectRaw('MAX(p.codigo_cobro) as codigo_cobro')
            ->selectRaw('MAX(p.fecha_pago) as fecha_pago')
            ->selectRaw('MAX(v.numero) as venta_numero')
            ->selectRaw('(SELECT c.numero FROM ventas.comprobantes c WHERE c.venta_id = MAX(v.id) ORDER BY c.id DESC LIMIT 1) as comprobante_numero')
            ->selectRaw('MAX(v.concepto) as venta_concepto')
            ->selectRaw("COALESCE(MAX(NULLIF(TRIM(persona.nombre_completo), '')), MAX(NULLIF(TRIM(cliente_user.name), '')), 'Consumidor final') as cliente_nombre")
            ->selectRaw("COALESCE(MAX(NULLIF(TRIM(persona.identificacion), '')), MAX(NULLIF(TRIM(cliente_user.cedula), ''))) as cliente_identificacion")
            ->selectRaw("COALESCE(MAX(NULLIF(TRIM(cobrador.name), '')), 'Sin usuario') as cobrado_por_nombre")
            ->selectRaw('MAX(rol_cobrador.role) as cobrado_por_rol')
            ->selectRaw("CASE WHEN MAX(v.generado_por_tipo) = 'SISTEMA' THEN 'Sistema' ELSE COALESCE(MAX(NULLIF(TRIM(creador_venta.name), '')), 'Sin usuario') END as generado_por_nombre")
            ->selectRaw('MAX(rol_creador.role) as generado_por_rol')
            ->selectRaw('MAX(caja_pago.nombre) as caja_nombre')
            ->selectRaw('MAX(caja_pago.codigo) as caja_codigo')
            ->selectRaw('MAX(p.turno_caja_id) as turno_caja_id')
            ->selectRaw('SUM(CASE WHEN p.estado = \'CONFIRMADO\' AND p.metodo_pago = \'EFECTIVO\' THEN p.monto ELSE 0 END) as efectivo')
            ->selectRaw($tieneEfectivoRecibido
                ? "SUM(CASE WHEN p.estado = 'CONFIRMADO' AND p.metodo_pago = 'EFECTIVO' THEN COALESCE(p.efectivo_recibido, p.monto) ELSE 0 END) as efectivo_recibido"
                : "SUM(CASE WHEN p.estado = 'CONFIRMADO' AND p.metodo_pago = 'EFECTIVO' THEN p.monto ELSE 0 END) as efectivo_recibido")
            ->selectRaw($tieneCambioEntregado
                ? "SUM(CASE WHEN p.estado = 'CONFIRMADO' AND p.metodo_pago = 'EFECTIVO' THEN COALESCE(p.cambio_entregado, 0) ELSE 0 END) as cambio_entregado"
                : "0::numeric as cambio_entregado")
            ->selectRaw('SUM(CASE WHEN p.estado = \'CONFIRMADO\' AND p.metodo_pago = \'TRANSFERENCIA\' THEN p.monto ELSE 0 END) as transferencia')
            ->selectRaw('SUM(CASE WHEN p.estado = \'CONFIRMADO\' AND p.metodo_pago = \'TARJETA\' THEN p.monto ELSE 0 END) as tarjeta')
            ->selectRaw('SUM(CASE WHEN p.estado = \'CONFIRMADO\' AND p.metodo_pago = \'DEPOSITO\' THEN p.monto ELSE 0 END) as deposito')
            ->selectRaw("SUM(CASE WHEN p.estado = 'CONFIRMADO' AND p.metodo_pago NOT IN ('EFECTIVO','TRANSFERENCIA','TARJETA','DEPOSITO') THEN p.monto ELSE 0 END) as otros")
            ->selectRaw('SUM(CASE WHEN p.estado = \'CONFIRMADO\' THEN p.monto ELSE 0 END) as total_cobrado')
            ->selectRaw("CASE WHEN BOOL_AND(p.estado = 'CONFIRMADO') THEN 'CONFIRMADO' ELSE MAX(p.estado) END as estado")
            ->selectRaw('COALESCE(MAX(caja_venta.sede_id), MAX(membresia_sede.sede_id)) as sede_id')
            ->groupByRaw($operacionExpr);

        $this->aplicarAlcanceVenta($query, $usuarioId, 'caja_venta.sede_id');

        if ($this->alcance->esCajero($usuarioId)) {
            $query->where('p.usuario_id', $usuarioId);
        }

        if (! empty($filtros['busqueda'])) {
            $texto = '%' . mb_strtolower((string) $filtros['busqueda']) . '%';
            $query->where(function ($q) use ($texto): void {
                $q->whereRaw('LOWER(v.numero) LIKE ?', [$texto])
                    ->orWhereRaw('LOWER(COALESCE(persona.nombre_completo, cliente_user.name, \'\')) LIKE ?', [$texto])
                    ->orWhereRaw('LOWER(COALESCE(persona.identificacion, cliente_user.cedula, \'\')) LIKE ?', [$texto])
                    ->orWhereRaw('LOWER(COALESCE(cobrador.name, \'\')) LIKE ?', [$texto])
                    ->orWhereRaw('LOWER(COALESCE(creador_venta.name, \'\')) LIKE ?', [$texto])
                    ->orWhereRaw('LOWER(COALESCE(p.codigo_cobro, \'\')) LIKE ?', [$texto])
                    ->orWhereRaw('LOWER(COALESCE(p.operacion_cobro_id, \'\')) LIKE ?', [$texto])
                    ->orWhereExists(fn ($c) => $c->from('ventas.comprobantes as cb')->whereColumn('cb.venta_id', 'v.id')->whereRaw('LOWER(cb.numero) LIKE ?', [$texto]));
            });
        }

        $this->filtrarTexto($query, 'p.estado', $filtros['estado'] ?? null);

        // Un cobro se filtra por su fecha real, no por la fecha de creación
        // de la venta ni la vigencia contractual de la membresía.
        if (! empty($filtros['fecha_pago'])) {
            $query->whereDate('p.fecha_pago', $filtros['fecha_pago']);
        }

        return $query
            ->orderByDesc(DB::raw('MAX(p.fecha_pago)'))
            ->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    private function validarSedeCobroMembresia(int $membresiaId, int $sedeId): void
    {
        $autorizada = DB::table('membresias.membresia_sedes')
            ->where('membresia_id', $membresiaId)
            ->where('sede_id', $sedeId)
            ->where('activo', true)
            ->exists();

        if (! $autorizada) {
            throw ValidationException::withMessages([
                'caja_id' => 'Esta membresía no permite cobrar en la sede seleccionada.',
            ]);
        }
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

            // Validación de seguridad en la capa de servicio: aplica tanto
            // al endpoint de pagos como a los cobros realizados desde POS.
            $turno = DB::table('ventas.turnos_caja as t')
                ->join('ventas.cajas as c', 'c.id', '=', 't.caja_id')
                ->where('t.usuario_id', $usuarioId)
                ->where('t.estado', 'ABIERTA')
                ->where('c.activa', true)
                ->lockForUpdate()
                ->select('t.*')
                ->first();

            if (! $turno) {
                throw ValidationException::withMessages([
                    'turno_caja_id' => 'Debes abrir una caja propia antes de registrar un cobro.',
                ]);
            }
            if ((! empty($datos['caja_id']) && (int) $datos['caja_id'] !== (int) $turno->caja_id)
                || (! empty($datos['turno_caja_id']) && (int) $datos['turno_caja_id'] !== (int) $turno->id)) {
                throw ValidationException::withMessages([
                    'caja_id' => 'Solo puedes cobrar desde tu caja actualmente abierta.',
                ]);
            }

            $sedeCobro = $this->alcance->validarSede(
                $usuarioId, (int) $turno->sede_id, 'maneja_caja'
            );
            if ($venta->membresia_id) {
                $this->validarSedeCobroMembresia((int) $venta->membresia_id, $sedeCobro);
            } else {
                $sedeVenta = $this->alcance->sedeDeVenta((int) $venta->id);
                if ($sedeVenta && $sedeVenta !== $sedeCobro) {
                    throw ValidationException::withMessages([
                        'caja_id' => 'La venta no pertenece a la sede de tu caja.',
                    ]);
                }
            }

            $datos['caja_id'] = (int) $turno->caja_id;
            $datos['turno_caja_id'] = (int) $turno->id;

            $datos = $this->estados->aplicar($datos, 'PAGO');
            $datos['usuario_id'] = $usuarioId;
            $datos['operacion_cobro_id'] = $datos['operacion_cobro_id'] ?? $this->secuencia('COBRO-OP');
            $datos['codigo_cobro'] = $datos['codigo_cobro'] ?? $this->siguienteCodigoCobro();
            $datos['numero_comprobante'] = $datos['numero_comprobante'] ?? $this->secuencia('PAGO');
            $pagoId = $this->guardarRetornandoId('ventas.pagos', $datos, null);
            $this->actualizarEstadoVenta((int) $datos['venta_id'], $usuarioId);
            $this->auditar('ventas', 'CREAR', 'ventas.pagos', $pagoId, null, DB::table('ventas.pagos')->where('id', $pagoId)->first(), 'Pago registrado sobre la venta #' . $datos['venta_id'] . '.');

            $ventaActualizada = DB::table('ventas.ventas')->where('id', $datos['venta_id'])->first();
            $pagada = strtoupper((string) ($ventaActualizada->estado ?? '')) === 'PAGADA';

            DB::table('ventas.comprobantes')->where('venta_id', $datos['venta_id'])->update([
                'pago_id' => $pagoId,
                'estado' => $pagada ? 'EMITIDO' : 'BORRADOR',
                'emitido_at' => $pagada ? now() : null,
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
            ->leftJoin('membresias.membresias as membresia_sede', 'ventas.ventas.membresia_id', '=', 'membresia_sede.id')
            ->leftJoin('clientes.deportistas', 'ventas.ventas.cliente_id', '=', 'clientes.deportistas.id')
            ->leftJoin('personas.personas as persona_cliente', 'clientes.deportistas.persona_id', '=', 'persona_cliente.id')
            ->leftJoin('seguridad.users as cliente_user', 'clientes.deportistas.usuario_id', '=', 'cliente_user.id')
            ->select(
                'ventas.comprobantes.*',
                'ventas.ventas.numero as venta_numero',
                'ventas.ventas.concepto',
                'ventas.ventas.estado as venta_estado',
                'ventas.ventas.total as venta_total',
                DB::raw("COALESCE(NULLIF(TRIM(persona_cliente.nombre_completo), ''), NULLIF(TRIM(cliente_user.name), ''), 'Consumidor final') as cliente_nombre"),
                DB::raw("COALESCE(NULLIF(TRIM(persona_cliente.identificacion), ''), NULLIF(TRIM(cliente_user.cedula), '')) as cliente_identificacion"),
                DB::raw("COALESCE(NULLIF(TRIM(persona_cliente.email), ''), NULLIF(TRIM(cliente_user.email), '')) as cliente_email"),
                DB::raw('COALESCE(caja_venta.sede_id, membresia_sede.sede_id) as sede_id'),
                DB::raw("(ventas.ventas.estado = 'PAGADA' AND NOT EXISTS (SELECT 1 FROM membresias.membresia_cuotas mc WHERE mc.venta_id = ventas.ventas.id) AND (ventas.ventas.membresia_id IS NULL OR EXISTS (SELECT 1 FROM membresias.membresias mm JOIN membresias.planes mp ON mp.id = mm.plan_id WHERE mm.id = ventas.ventas.membresia_id AND mp.tipo_producto = 'PASE_DIARIO'))) as anulable")
            );

        $this->aplicarAlcanceVenta($query, $usuarioId, 'caja_venta.sede_id');
        $this->aplicarVisibilidadComercialVenta($query, $usuarioId, 'ventas.ventas');
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
            'clientes' => DB::table('clientes.deportistas')
                ->leftJoin('seguridad.users', 'clientes.deportistas.usuario_id', '=', 'seguridad.users.id')
                ->orderBy('seguridad.users.name')
                ->get(['clientes.deportistas.id', 'clientes.deportistas.codigo_deportista', 'seguridad.users.name as nombre']),
            'membresias' => DB::table('membresias.membresias')->whereIn('sede_id', $sedes)->orderByDesc('created_at')->limit(100)->get(['id', 'codigo_contrato', 'estado', 'estado_id', 'precio_aplicado', 'sede_id']),
            'productos' => DB::table('inventario.productos')->where('activo', true)->orderBy('nombre')->get(['id', 'codigo', 'nombre', 'precio_venta']),
            'sedes' => DB::table('institucional.sedes')->where('activo', true)->whereIn('id_sede', $sedes)->orderBy('nombre')->get(['id_sede as id', 'nombre']),
            'ventas_pendientes' => $this->ventasPendientesCatalogo($sedes),
            'estados_venta' => $this->estadosEntidad('VENTA'),
            'estados_pago' => $this->estadosEntidad('PAGO'),
            'puede_anular_pagadas' => $this->alcance->puedeGestionarCartera($usuarioId),
        ];
    }

    public function opcionesFiltro(?int $usuarioId = null): array
    {
        $sedes = $this->alcance->sedesPermitidas($usuarioId, 'maneja_caja');
        $cajas = DB::table('ventas.cajas')->whereIn('sede_id', $sedes);
        $ventas = DB::table('ventas.ventas as v')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->whereIn(DB::raw('COALESCE(c.sede_id, m.sede_id)'), $sedes);
        $pagos = DB::table('ventas.pagos as p')
            ->join('ventas.ventas as v', 'v.id', '=', 'p.venta_id')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
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
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->whereIn('v.estado', ['PENDIENTE', 'PARCIAL'])
            ->whereIn(DB::raw('COALESCE(c.sede_id, m.sede_id)'), $sedes)
            ->orderByDesc('v.fecha_venta')
            ->get(['v.id', 'v.numero', 'v.concepto', 'v.total']);
    }

    private function aplicarVisibilidadComercialVenta($query, ?int $usuarioId, string $ventaAlias): void
    {
        if (! $this->alcance->esCajero($usuarioId)) {
            return;
        }

        $query->where(function ($q) use ($usuarioId, $ventaAlias): void {
            $q->where("{$ventaAlias}.usuario_id", $usuarioId)
                ->orWhereExists(function ($pagos) use ($usuarioId, $ventaAlias): void {
                    $pagos->selectRaw('1')
                        ->from('ventas.pagos as pago_visibilidad')
                        ->whereColumn('pago_visibilidad.venta_id', "{$ventaAlias}.id")
                        ->where('pago_visibilidad.usuario_id', $usuarioId);
                });
        });
    }

    private function validarVisibilidadDetalleVenta(int $ventaId, ?int $usuarioId, ?int $sedeId): void
    {
        if (! $this->alcance->esCajero($usuarioId)) {
            return;
        }

        $venta = DB::table('ventas.ventas')->where('id', $ventaId)->first(['id', 'usuario_id', 'estado']);
        if (! $venta) {
            throw ValidationException::withMessages(['venta_id' => 'La venta no existe.']);
        }

        if ((int) $venta->usuario_id === (int) $usuarioId) {
            return;
        }

        $cobradaPorUsuario = DB::table('ventas.pagos')
            ->where('venta_id', $ventaId)
            ->where('usuario_id', $usuarioId)
            ->exists();

        if ($cobradaPorUsuario) {
            return;
        }

        $turnoActivoMismaSede = DB::table('ventas.turnos_caja')
            ->where('usuario_id', $usuarioId)
            ->where('estado', 'ABIERTA')
            ->where('sede_id', $sedeId)
            ->exists();

        if ($turnoActivoMismaSede && in_array(strtoupper((string) $venta->estado), ['PENDIENTE', 'PARCIAL'], true)) {
            return;
        }

        throw ValidationException::withMessages([
            'venta_id' => 'No tienes acceso al detalle de esta venta fuera de tu operación de caja.',
        ]);
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

        if ($estado === 'PAGADA') {
            // Las ventas guardadas antes de reservar stock se descuentan al pagarse.
            $this->sincronizarInventarioVenta($ventaId, $usuarioId);
        }

        if ($estado === 'PAGADA' && $venta->membresia_id) {
            // Una venta anterior pagada no debe rehabilitar el acceso si existe
            // una renovación cuyo período ya comenzó y continúa sin pagar.
            // La validación mira TODAS las renovaciones iniciadas, no solo la
            // venta que acaba de recibir un pago.
            $hoy = now('America/Guayaquil')->toDateString();
            $renovacionImpaga = DB::table('membresias.membresia_periodos as p')
                ->leftJoin('ventas.ventas as v', 'v.id', '=', 'p.venta_id')
                ->where('p.membresia_id', $venta->membresia_id)
                ->where('p.renovacion_manual', true)
                ->where('p.fecha_inicio', '<=', $hoy)
                ->where(function ($q): void {
                    $q->whereNull('p.venta_id')->orWhere('v.estado', '!=', 'PAGADA');
                })
                ->exists();
            // La venta de la solicitud actual todavía podría mostrar estado
            // antiguo dentro del objeto $venta, pero la fila DB ya se
            // actualizó arriba a PAGADA antes de consultar este bloqueo.
            if (! $renovacionImpaga && $this->ventaHabilitaMembresia((int) $venta->membresia_id, $ventaId)) {
                $estadoMembresiaId = $this->estados->idPorValor('MEMBRESIA', 'ACTIVA');
                DB::table('membresias.membresias')
                    ->where('id', $venta->membresia_id)
                    ->where('estado', 'PENDIENTE_PAGO')
                    ->update([
                        'estado' => 'ACTIVA',
                        'estado_id' => $estadoMembresiaId,
                        'updated_at' => now(),
                    ]);
            }

            DB::table('membresias.membresia_periodos')
                ->where('membresia_id', $venta->membresia_id)
                ->where('venta_id', $ventaId)
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('membresias.membresias as mc')
                    ->where('mc.id', $venta->membresia_id)->where('mc.estado', '!=', 'CANCELADA'))
                ->update([
                    'estado' => 'ACTIVA',
                    'updated_at' => now(),
                ]);
        }

        $this->cartera->sincronizarVenta($ventaId);
    }

    /**
     * Pagar la deuda de un día anterior no habilita el pase: solo la venta
     * de su fecha de uso actual concede acceso.
     */
    /**
     * Anula una venta pendiente sin pagos. La venta y su historial se conservan. Un pase
     * queda libre para volver a venderse; un contrato con cuotas se corrige desde su contrato.
     */
    public function anularVentaPendiente(int $ventaId, string $motivo, int $usuarioId): object
    {
        // Aplica las mismas reglas de sede y de cajero que abrir el detalle de la venta.
        $this->detalleVenta($ventaId, $usuarioId);

        return DB::transaction(function () use ($ventaId, $motivo, $usuarioId): object {
            $venta = DB::table('ventas.ventas')->where('id', $ventaId)->lockForUpdate()->first();
            if (! $venta || strtoupper((string) $venta->estado) !== 'PENDIENTE') {
                throw ValidationException::withMessages(['venta_id' => 'Solo se pueden anular ventas pendientes.']);
            }
            if (DB::table('ventas.pagos')->where('venta_id', $ventaId)->where('estado', 'CONFIRMADO')->exists()) {
                throw ValidationException::withMessages(['venta_id' => 'La venta tiene pagos registrados: no se puede anular.']);
            }
            if (DB::table('membresias.membresia_cuotas')->where('venta_id', $ventaId)->exists()) {
                throw ValidationException::withMessages(['venta_id' => 'Esta venta es una cuota de un contrato: no se anula desde Ventas.']);
            }

            $membresia = $venta->membresia_id
                ? DB::table('membresias.membresias as m')
                    ->join('membresias.planes as p', 'p.id', '=', 'm.plan_id')
                    ->where('m.id', $venta->membresia_id)->lockForUpdate()
                    ->first(['m.id', 'm.estado', 'p.tipo_producto'])
                : null;
            $esPase = $membresia?->tipo_producto === 'PASE_DIARIO';
            // Se consulta antes de anular: compara con la venta del último período.
            $esUsoActual = $esPase && $this->ventaHabilitaMembresia((int) $membresia->id, $ventaId);

            $observacion = 'Anulada: '.trim($motivo);
            $this->sincronizarInventarioVenta($ventaId, $usuarioId, true, $motivo);
            DB::table('ventas.ventas')->where('id', $ventaId)->update($this->estados->aplicar([
                'estado' => 'ANULADA',
                'observaciones' => trim(($venta->observaciones ? $venta->observaciones.' ' : '').$observacion),
                'updated_at' => now(),
            ], 'VENTA'));
            DB::table('ventas.comprobantes')->where('venta_id', $ventaId)
                ->update(['estado' => 'ANULADO', 'updated_at' => now()]);

            if ($esPase) {
                DB::table('membresias.membresia_periodos')
                    ->where('membresia_id', $membresia->id)->where('venta_id', $ventaId)
                    ->update(['estado' => 'CANCELADA', 'updated_at' => now()]);
                if ($esUsoActual && $membresia->estado === 'PENDIENTE_PAGO') {
                    DB::table('membresias.membresias')->where('id', $membresia->id)
                        ->update($this->estados->aplicar(['estado' => 'VENCIDA', 'updated_at' => now()], 'MEMBRESIA'));
                }
            } elseif ($membresia) {
                // El período queda libre para la nueva venta del contrato.
                DB::table('membresias.membresia_periodos')
                    ->where('membresia_id', $membresia->id)->where('venta_id', $ventaId)
                    ->update(['venta_id' => null, 'updated_at' => now()]);
            }

            $this->cartera->sincronizarVenta($ventaId);
            $cuentaId = DB::table('cuentas_cobrar.cuentas')->where('venta_id', $ventaId)->value('id');
            if ($cuentaId) {
                DB::table('cuentas_cobrar.compromisos_pago')->where('cuenta_id', $cuentaId)->where('estado', 'PENDIENTE')
                    ->update(['estado' => 'CANCELADO', 'updated_at' => now()]);
            }

            $despues = DB::table('ventas.ventas')->where('id', $ventaId)->first();
            $this->auditar('ventas', 'ANULAR', 'ventas.ventas', $ventaId, $venta, $despues);

            return $despues;
        });
    }

    /**
     * Anula una venta ya pagada: devuelve el dinero desde el turno abierto de quien anula
     * (un pago negativo por cada método, que descuenta del arqueo), regresa el inventario
     * a los mismos lotes con un movimiento REVERSO_ANULACION y anula el comprobante.
     * La venta no se borra: queda ANULADA con su motivo e historial.
     */
    public function anularVentaPagada(int $ventaId, string $motivo, int $usuarioId): object
    {
        if (! $this->alcance->puedeGestionarCartera($usuarioId)) {
            throw ValidationException::withMessages(['venta_id' => 'Solo un administrador o supervisor de ventas puede anular una venta cobrada.']);
        }
        // Aplica las mismas reglas de sede que abrir el detalle de la venta.
        $this->detalleVenta($ventaId, $usuarioId);

        return DB::transaction(function () use ($ventaId, $motivo, $usuarioId): object {
            $venta = DB::table('ventas.ventas')->where('id', $ventaId)->lockForUpdate()->first();
            if (! $venta || strtoupper((string) $venta->estado) !== 'PAGADA') {
                throw ValidationException::withMessages(['venta_id' => 'Solo se pueden anular aquí ventas pagadas.']);
            }
            if (DB::table('membresias.membresia_cuotas')->where('venta_id', $ventaId)->exists()) {
                throw ValidationException::withMessages(['venta_id' => 'Esta venta es una cuota de un contrato: se gestiona desde la membresía.']);
            }

            $membresia = $venta->membresia_id
                ? DB::table('membresias.membresias as m')
                    ->join('membresias.planes as p', 'p.id', '=', 'm.plan_id')
                    ->where('m.id', $venta->membresia_id)->lockForUpdate()
                    ->first(['m.id', 'm.estado', 'p.tipo_producto'])
                : null;
            $esPase = $membresia?->tipo_producto === 'PASE_DIARIO';
            if ($membresia && ! $esPase) {
                throw ValidationException::withMessages(['venta_id' => 'Las ventas de membresías se gestionan desde el contrato (cancelación o cambio), no desde aquí.']);
            }

            $turno = DB::table('ventas.turnos_caja')->where('usuario_id', $usuarioId)->where('estado', 'ABIERTA')->lockForUpdate()->first();
            if (! $turno) {
                throw ValidationException::withMessages(['venta_id' => 'Abre tu turno de caja: la devolución del dinero sale de tu caja.']);
            }

            $pagosOriginales = DB::table('ventas.pagos')
                ->where('venta_id', $ventaId)->where('estado', 'CONFIRMADO')
                ->select('metodo_pago', DB::raw('SUM(monto) as monto'))
                ->groupBy('metodo_pago')->get()
                ->filter(fn ($pago) => round((float) $pago->monto, 2) > 0);

            $efectivoDevolver = round((float) ($pagosOriginales->firstWhere('metodo_pago', 'EFECTIVO')->monto ?? 0), 2);
            if ($efectivoDevolver > 0) {
                $efectivoEnCaja = round((float) $turno->saldo_inicial + (float) DB::table('ventas.pagos')
                    ->where('turno_caja_id', $turno->id)->where('estado', 'CONFIRMADO')->where('metodo_pago', 'EFECTIVO')->sum('monto'), 2);
                if ($efectivoEnCaja + 0.00001 < $efectivoDevolver) {
                    throw ValidationException::withMessages(['venta_id' => sprintf(
                        'Tu caja tiene $%s en efectivo y la devolución es de $%s.',
                        number_format($efectivoEnCaja, 2, '.', ''),
                        number_format($efectivoDevolver, 2, '.', ''),
                    )]);
                }
            }

            $esUsoActual = $esPase && $this->ventaHabilitaMembresia((int) $membresia->id, $ventaId);
            $observacion = 'Anulada (cobrada): '.trim($motivo);
            $operacion = 'DEVOLUCION-OP-'.now()->format('YmdHis').'-'.random_int(1000, 9999);
            $codigo = $this->siguienteCodigoCobro();
            $estadoPagoId = $this->estados->idPorValor('PAGO', 'CONFIRMADO');

            foreach ($pagosOriginales as $pago) {
                DB::table('ventas.pagos')->insert([
                    'venta_id' => $ventaId,
                    'caja_id' => (int) $turno->caja_id,
                    'turno_caja_id' => (int) $turno->id,
                    'usuario_id' => $usuarioId,
                    'numero_comprobante' => $this->secuencia('PAGO'),
                    'operacion_cobro_id' => $operacion,
                    'codigo_cobro' => $codigo,
                    'metodo_pago' => $pago->metodo_pago,
                    'monto' => -round((float) $pago->monto, 2),
                    'estado' => 'CONFIRMADO',
                    'estado_id' => $estadoPagoId,
                    'fecha_pago' => now(),
                    'referencia' => 'DEVOLUCION-VENTA-'.$ventaId,
                    'observaciones' => "Devolución por anulación de la venta {$venta->numero}: ".trim($motivo),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->sincronizarInventarioVenta($ventaId, $usuarioId, true, $motivo);

            DB::table('ventas.ventas')->where('id', $ventaId)->update($this->estados->aplicar([
                'estado' => 'ANULADA',
                'observaciones' => trim(($venta->observaciones ? $venta->observaciones.' ' : '').$observacion),
                'updated_at' => now(),
            ], 'VENTA'));
            DB::table('ventas.comprobantes')->where('venta_id', $ventaId)
                ->update(['estado' => 'ANULADO', 'updated_at' => now()]);

            if ($esPase) {
                DB::table('membresias.membresia_periodos')
                    ->where('membresia_id', $membresia->id)->where('venta_id', $ventaId)
                    ->update(['estado' => 'CANCELADA', 'updated_at' => now()]);
                if ($esUsoActual && in_array($membresia->estado, ['ACTIVA', 'PENDIENTE_PAGO'], true)) {
                    DB::table('membresias.membresias')->where('id', $membresia->id)
                        ->update($this->estados->aplicar(['estado' => 'VENCIDA', 'updated_at' => now()], 'MEMBRESIA'));
                }
            }

            $this->cartera->sincronizarVenta($ventaId);

            $despues = DB::table('ventas.ventas')->where('id', $ventaId)->first();
            $despues->devolucion = [
                'codigo_cobro' => $codigo,
                'turno_caja_id' => (int) $turno->id,
                'pagos' => $pagosOriginales->map(fn ($p) => ['metodo_pago' => $p->metodo_pago, 'monto' => round((float) $p->monto, 2)])->values(),
                'inventario_revertido' => (bool) $venta->inventario_aplicado_at,
            ];
            $this->auditar('ventas', 'ANULAR_PAGADA', 'ventas.ventas', $ventaId, $venta, $despues);

            return $despues;
        });
    }

    /**
     * Deja el stock descontado igual al detalle de la venta. Se usa al guardar una cuenta
     * (pendiente o cobrada), al editarla y al pagarla. Con $anular devuelve todo.
     */
    public function sincronizarInventarioVenta(int $ventaId, ?int $usuarioId, bool $anular = false, string $motivo = ''): void
    {
        $venta = DB::table('ventas.ventas')->where('id', $ventaId)->first();
        if (! $venta) {
            return;
        }

        $deseado = $anular ? collect() : DB::table('ventas.venta_detalles')
            ->where('venta_id', $ventaId)->whereNotNull('producto_id')
            ->select('producto_id', DB::raw('SUM(cantidad) as cantidad'))->groupBy('producto_id')
            ->pluck('cantidad', 'producto_id')->map(fn ($c) => (float) $c);

        $productos = collect($this->inventario->productosDescontadosVenta($ventaId))
            ->merge($deseado->keys()->map(fn ($id) => (int) $id))->unique();
        if ($productos->isEmpty()) {
            return;
        }

        $sedeId = $this->alcance->sedeDeVenta($ventaId);
        if (! $sedeId && $deseado->sum() > 0) {
            throw ValidationException::withMessages(['venta_id' => 'No se pudo determinar la sede de la venta para descontar inventario.']);
        }

        $tipoReverso = $anular ? 'REVERSO_ANULACION' : 'REVERSO_VENTA';
        $detalle = $anular
            ? "Reverso por anulación de la venta {$venta->numero}: ".trim($motivo)
            : "Venta {$venta->numero} (".strtolower((string) $venta->estado).').';

        foreach ($productos as $productoId) {
            try {
                $this->inventario->ajustarVenta($ventaId, (int) $productoId, $sedeId, (float) ($deseado[$productoId] ?? 0), $usuarioId, $tipoReverso, $detalle);
            } catch (\InvalidArgumentException $e) {
                $producto = DB::table('inventario.productos')->where('id', $productoId)->value('nombre');
                $disponible = (float) DB::table('inventario.producto_stock_sede')->where('producto_id', $productoId)->where('sede_id', $sedeId)->value('stock_actual');
                throw ValidationException::withMessages(['detalles' => sprintf(
                    'No hay stock suficiente de %s en esta sede: quedan %s.',
                    $producto ?: 'el producto',
                    rtrim(rtrim(number_format($disponible, 2, '.', ''), '0'), '.'),
                )]);
            }
        }

        $descontado = collect($this->inventario->productosDescontadosVenta($ventaId))
            ->contains(fn ($id) => $this->inventario->netoVenta($ventaId, $id)['total'] > 0);
        DB::table('ventas.ventas')->where('id', $ventaId)->update([
            'inventario_aplicado_at' => $descontado ? ($venta->inventario_aplicado_at ?? now()) : null,
            'updated_at' => now(),
        ]);
    }

    public function ventaHabilitaMembresia(int $membresiaId, int $ventaId): bool
    {
        $esPase = DB::table('membresias.membresias as m')
            ->join('membresias.planes as p', 'p.id', '=', 'm.plan_id')
            ->where('m.id', $membresiaId)
            ->where('p.tipo_producto', 'PASE_DIARIO')
            ->exists();

        return ! $esPase || (int) DB::table('membresias.membresia_periodos')
            ->where('membresia_id', $membresiaId)
            ->orderByDesc('numero_periodo')
            ->value('venta_id') === $ventaId;
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

    public function siguienteCodigoCobro(): string
    {
        $prefijo = 'COBRO-' . now()->format('Ymd') . '-';

        $ultimo = DB::table('ventas.pagos')
            ->whereNotNull('codigo_cobro')
            ->where('codigo_cobro', 'like', $prefijo . '%')
            ->orderByDesc('codigo_cobro')
            ->value('codigo_cobro');

        $consecutivo = $ultimo
            ? max(1, ((int) substr((string) $ultimo, -4)) + 1)
            : 1;

        return $prefijo . str_pad((string) $consecutivo, 4, '0', STR_PAD_LEFT);
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
