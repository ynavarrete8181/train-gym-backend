<?php

namespace App\Services\Ventas;

use App\Services\Concerns\RegistraAuditoria;
use App\Services\Configuracion\EstadoCatalogoServicio;
use Illuminate\Support\Facades\DB;

class VentaServicio
{
    use RegistraAuditoria;

    public function __construct(private readonly EstadoCatalogoServicio $estados)
    {
    }

    public function listarCajas(array $filtros)
    {
        $query = DB::table('ventas.cajas')
            ->leftJoin('institucional.sedes', 'ventas.cajas.sede_id', '=', 'institucional.sedes.id_sede')
            ->select('ventas.cajas.*', 'institucional.sedes.nombre as sede_nombre');

        $this->buscar($query, $filtros['busqueda'] ?? null, ['ventas.cajas.codigo', 'ventas.cajas.nombre', 'institucional.sedes.nombre']);
        $this->filtrarTexto($query, 'ventas.cajas.nombre', $filtros['nombre'] ?? null);
        $this->filtrarBooleano($query, 'ventas.cajas.activa', $filtros['estado'] ?? null);

        return $query->orderBy('ventas.cajas.nombre')->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function guardarCaja(array $datos, ?int $id = null): object
    {
        return $this->guardar('ventas.cajas', $datos, $id);
    }

    public function listarVentas(array $filtros)
    {
        $query = DB::table('ventas.ventas')
            ->leftJoin('gimnasio.deportistas', 'ventas.ventas.cliente_id', '=', 'gimnasio.deportistas.id')
            ->leftJoin('seguridad.users as cliente_user', 'gimnasio.deportistas.usuario_id', '=', 'cliente_user.id')
            ->leftJoin('ventas.cajas', 'ventas.ventas.caja_id', '=', 'ventas.cajas.id')
            ->leftJoin('configuracion.estados_catalogo as estado_cfg', 'ventas.ventas.estado_id', '=', 'estado_cfg.id')
            ->select(
                'ventas.ventas.*',
                'cliente_user.name as cliente_nombre',
                'gimnasio.deportistas.codigo_deportista',
                'ventas.cajas.nombre as caja_nombre',
                'estado_cfg.codigo as estado_codigo',
                'estado_cfg.valor_interno as estado_valor',
                'estado_cfg.nombre as estado_nombre',
                'estado_cfg.color as estado_color'
            );

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

    public function listarPagos(array $filtros)
    {
        $query = DB::table('ventas.pagos')
            ->join('ventas.ventas', 'ventas.pagos.venta_id', '=', 'ventas.ventas.id')
            ->leftJoin('ventas.cajas', 'ventas.pagos.caja_id', '=', 'ventas.cajas.id')
            ->leftJoin('configuracion.estados_catalogo as estado_cfg', 'ventas.pagos.estado_id', '=', 'estado_cfg.id')
            ->select(
                'ventas.pagos.*',
                'ventas.ventas.numero as venta_numero',
                'ventas.ventas.concepto as venta_concepto',
                'ventas.cajas.nombre as caja_nombre',
                'estado_cfg.codigo as estado_codigo',
                'estado_cfg.valor_interno as estado_valor',
                'estado_cfg.nombre as estado_nombre',
                'estado_cfg.color as estado_color'
            );

        $this->buscar($query, $filtros['busqueda'] ?? null, ['ventas.pagos.numero_comprobante', 'ventas.ventas.numero', 'ventas.pagos.metodo_pago', 'ventas.pagos.referencia', 'estado_cfg.nombre']);
        $this->filtrarTexto($query, 'ventas.pagos.numero_comprobante', $filtros['comprobante'] ?? null);
        $this->filtrarTexto($query, 'ventas.pagos.metodo_pago', $filtros['metodo'] ?? null);
        $this->filtrarTexto($query, 'ventas.pagos.estado', $filtros['estado'] ?? null);

        return $query->orderByDesc('ventas.pagos.fecha_pago')->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function guardarPago(array $datos, ?int $usuarioId = null): object
    {
        return DB::transaction(function () use ($datos, $usuarioId): object {
            $datos = $this->estados->aplicar($datos, 'PAGO');
            $datos['usuario_id'] = $usuarioId;
            $datos['numero_comprobante'] = $datos['numero_comprobante'] ?? $this->secuencia('PAGO');
            $pagoId = $this->guardarRetornandoId('ventas.pagos', $datos, null);
            $this->actualizarEstadoVenta((int) $datos['venta_id']);
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

    public function listarComprobantes(array $filtros)
    {
        $query = DB::table('ventas.comprobantes')
            ->join('ventas.ventas', 'ventas.comprobantes.venta_id', '=', 'ventas.ventas.id')
            ->leftJoin('gimnasio.deportistas', 'ventas.ventas.cliente_id', '=', 'gimnasio.deportistas.id')
            ->leftJoin('seguridad.users as cliente_user', 'gimnasio.deportistas.usuario_id', '=', 'cliente_user.id')
            ->select('ventas.comprobantes.*', 'ventas.ventas.numero as venta_numero', 'ventas.ventas.concepto', 'cliente_user.name as cliente_nombre');

        $this->buscar($query, $filtros['busqueda'] ?? null, ['ventas.comprobantes.numero', 'ventas.ventas.numero', 'ventas.ventas.concepto', 'cliente_user.name']);
        $this->filtrarTexto($query, 'ventas.comprobantes.numero', $filtros['numero'] ?? null);
        $this->filtrarTexto($query, 'ventas.comprobantes.estado', $filtros['estado'] ?? null);

        return $query->orderByDesc('ventas.comprobantes.created_at')->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function catalogos(): array
    {
        return [
            'cajas' => DB::table('ventas.cajas')->where('activa', true)->orderBy('nombre')->get(['id', 'nombre', 'codigo']),
            'clientes' => DB::table('gimnasio.deportistas')
                ->leftJoin('seguridad.users', 'gimnasio.deportistas.usuario_id', '=', 'seguridad.users.id')
                ->orderBy('seguridad.users.name')
                ->get(['gimnasio.deportistas.id', 'gimnasio.deportistas.codigo_deportista', 'seguridad.users.name as nombre']),
            'membresias' => DB::table('gimnasio.membresias')->orderByDesc('created_at')->limit(100)->get(['id', 'codigo_contrato', 'estado', 'estado_id', 'precio_aplicado']),
            'productos' => DB::table('inventario.productos')->where('activo', true)->orderBy('nombre')->get(['id', 'codigo', 'nombre', 'precio_venta']),
            'sedes' => DB::table('institucional.sedes')->where('activo', true)->orderBy('nombre')->get(['id_sede as id', 'nombre']),
            'ventas_pendientes' => DB::table('ventas.ventas')->whereIn('estado', ['PENDIENTE', 'PARCIAL'])->orderByDesc('fecha_venta')->get(['id', 'numero', 'concepto', 'total']),
            'estados_venta' => $this->estadosEntidad('VENTA'),
            'estados_pago' => $this->estadosEntidad('PAGO'),
        ];
    }

    public function opcionesFiltro(): array
    {
        return [
            'caja' => DB::table('ventas.cajas')->distinct()->orderBy('nombre')->pluck('nombre')->values(),
            'cliente' => DB::table('seguridad.users')->distinct()->orderBy('name')->pluck('name')->values(),
            'numero' => DB::table('ventas.ventas')->distinct()->orderBy('numero')->pluck('numero')->values(),
            'tipo' => DB::table('ventas.ventas')->distinct()->orderBy('tipo_venta')->pluck('tipo_venta')->values(),
            'metodo' => DB::table('ventas.pagos')->distinct()->orderBy('metodo_pago')->pluck('metodo_pago')->values(),
            'estado_venta' => $this->estadosEntidad('VENTA')->pluck('valor_interno')->values(),
            'estado_pago' => $this->estadosEntidad('PAGO')->pluck('valor_interno')->values(),
        ];
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

    private function actualizarEstadoVenta(int $ventaId): void
    {
        $venta = DB::table('ventas.ventas')->where('id', $ventaId)->first();
        $pagado = (float) DB::table('ventas.pagos')->where('venta_id', $ventaId)->where('estado', 'CONFIRMADO')->sum('monto');
        $estado = $pagado <= 0 ? 'PENDIENTE' : ($pagado >= (float) $venta->total ? 'PAGADA' : 'PARCIAL');
        $estadoVentaId = $this->estados->idPorValor('VENTA', $estado);

        DB::table('ventas.ventas')->where('id', $ventaId)->update([
            'estado' => $estado,
            'estado_id' => $estadoVentaId,
            'updated_at' => now(),
        ]);

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
