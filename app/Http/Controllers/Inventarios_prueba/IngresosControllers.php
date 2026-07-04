<?php

namespace App\Http\Controllers\Inventarios;

use App\Http\Controllers\AuditoriaControllers;
use App\Http\Controllers\Controller;
use App\Http\Controllers\LogController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IngresosControllers extends Controller
{
    protected $auditoriaController;
    protected $inventariosController;
    protected $logController;

    public function __construct()
    {
        $this->middleware('auth:api');
        $this->auditoriaController = new AuditoriaControllers();
        $this->inventariosController = new CpuInventariosController();
        $this->logController = new LogController();
    }

    public function consultarIngresos(Request $request)
    {
        try {
            $page = max((int) $request->query('page', 1), 1);
            $exportAll = filter_var($request->query('export_all', false), FILTER_VALIDATE_BOOLEAN);

            $perPage = (int) $request->query('per_page', 10);
            $perPage = $perPage <= 0 ? 10 : min($perPage, 100);

            $q = trim((string) $request->query('q', ''));
            $tipo = trim((string) $request->query('tipo', ''));
            $sede = trim((string) $request->query('sede', ''));
            $bodega = trim((string) $request->query('bodega', ''));
            $comprobante = trim((string) $request->query('comprobante', 'all'));
            $fechaDesde = trim((string) $request->query('fecha_desde', ''));
            $fechaHasta = trim((string) $request->query('fecha_hasta', ''));

            if ($fechaDesde !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaDesde)) {
                return response()->json([
                    'error' => 'La fecha_desde no tiene un formato válido. Use YYYY-MM-DD.'
                ], 422);
            }

            if ($fechaHasta !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaHasta)) {
                return response()->json([
                    'error' => 'La fecha_hasta no tiene un formato válido. Use YYYY-MM-DD.'
                ], 422);
            }

            if ($fechaDesde !== '' && $fechaHasta !== '' && $fechaHasta < $fechaDesde) {
                return response()->json([
                    'error' => 'La fecha_hasta no puede ser menor a la fecha_desde.'
                ], 422);
            }

            if ($tipo !== '' && !in_array((int) $tipo, [1, 2, 3], true)) {
                return response()->json([
                    'error' => 'El tipo de adquisición enviado no es válido.'
                ], 422);
            }

            if ($comprobante !== '' && !in_array($comprobante, ['all', 'si', 'no'], true)) {
                return response()->json([
                    'error' => 'El filtro comprobante no es válido.'
                ], 422);
            }

            $query = DB::table('inventarios.ingresos as ei')
                ->leftJoin('inventarios.proveedores as p', 'ei.ei_id_proveedor', '=', 'p.prov_id')
                ->leftJoin('inventarios.bodegas as b', 'ei.ei_id_bodega', '=', 'b.bod_id')
                ->leftJoin('cpu_sede as s', 'b.bod_id_sede', '=', 's.id')
                ->leftJoin('cpu_facultad as f', 'b.bod_id_facultad', '=', 'f.id')
                ->leftJoin('users as u', 'ei.ei_id_user', '=', 'u.id')
                ->leftJoin('cpu_estados as e', 'ei.ei_id_estado', '=', 'e.id')
                ->where('ei.ei_id_estado', 8)
                ->select(
                    'ei.ei_id',
                    'ei.ei_numero_comprobante',
                    'ei.ei_numero_ingreso',
                    'ei.ei_tipo_adquisicion',
                    DB::raw("
                        CASE ei.ei_tipo_adquisicion
                            WHEN 1 THEN 'Factura'
                            WHEN 2 THEN 'Donación'
                            WHEN 3 THEN 'Donación Laboratorios'
                            ELSE '—'
                        END AS tipo_adquisicion_nombre
                    "),
                    'ei.ei_id_proveedor',
                    'p.prov_nombre as proveedor_nombre',
                    'p.prov_ruc',
                    'ei.ei_fecha_emision',
                    'ei.ei_fecha_vencimiento',
                    'ei.ei_created_at',
                    'ei.ei_updated_at',
                    'ei.ei_id_user',
                    'u.name as usuario_nombre',
                    'u.email as usuario_email',
                    'ei.ei_detalle_producto',
                    'ei.ei_id_estado',
                    'e.estado as estado_nombre',
                    'ei.ei_ruta_comprobante',
                    DB::raw("CASE WHEN ei.ei_ruta_comprobante IS NULL OR ei.ei_ruta_comprobante = '' THEN false ELSE true END as comprobante_disponible"),
                    'ei.ei_id_bodega',
                    'b.bod_id as bodega_id',
                    'b.bod_nombre as bodega_nombre',
                    'b.bod_id_sede',
                    's.nombre_sede',
                    'b.bod_id_facultad',
                    'f.fac_nombre as facultad_nombre'
                );

            if ($tipo !== '') {
                $query->where('ei.ei_tipo_adquisicion', (int) $tipo);
            }

            if ($sede !== '') {
                $query->whereRaw("COALESCE(s.nombre_sede, '') = ?", [$sede]);
            }

            if ($bodega !== '') {
                $query->whereRaw("COALESCE(b.bod_nombre, '') = ?", [$bodega]);
            }

            if ($comprobante === 'si') {
                $query->whereNotNull('ei.ei_ruta_comprobante')
                    ->where('ei.ei_ruta_comprobante', '<>', '');
            } elseif ($comprobante === 'no') {
                $query->where(function ($sub) {
                    $sub->whereNull('ei.ei_ruta_comprobante')
                        ->orWhere('ei.ei_ruta_comprobante', '');
                });
            }

            if ($fechaDesde !== '') {
                $query->whereDate('ei.ei_fecha_emision', '>=', $fechaDesde);
            }

            if ($fechaHasta !== '') {
                $query->whereDate('ei.ei_fecha_emision', '<=', $fechaHasta);
            }

            if ($q !== '') {
                $like = "%{$q}%";

                $query->where(function ($sub) use ($like) {
                    $sub->whereRaw('CAST(ei.ei_id AS TEXT) ILIKE ?', [$like])
                        ->orWhere('ei.ei_numero_ingreso', 'ILIKE', $like)
                        ->orWhere('ei.ei_numero_comprobante', 'ILIKE', $like)
                        ->orWhere('p.prov_nombre', 'ILIKE', $like)
                        ->orWhere('p.prov_ruc', 'ILIKE', $like)
                        ->orWhere('b.bod_nombre', 'ILIKE', $like)
                        ->orWhere('s.nombre_sede', 'ILIKE', $like)
                        ->orWhere('f.fac_nombre', 'ILIKE', $like)
                        ->orWhere('u.name', 'ILIKE', $like)
                        ->orWhere('e.estado', 'ILIKE', $like)
                        ->orWhereRaw("
                            CASE ei.ei_tipo_adquisicion
                                WHEN 1 THEN 'Factura'
                                WHEN 2 THEN 'Donación'
                                WHEN 3 THEN 'Donación Laboratorios'
                                ELSE '—'
                            END ILIKE ?
                        ", [$like]);
                });
            }

            $total = (clone $query)->count();

            $query->orderByRaw('COALESCE(ei.ei_fecha_emision, DATE(ei.ei_created_at)) DESC')
                ->orderByDesc('ei.ei_id');

            if (!$exportAll) {
                $rows = $query->forPage($page, $perPage)->get();
            } else {
                $rows = $query->get();
            }

            return response()->json([
                'data' => $rows,
                'total' => $total,
                'page' => $exportAll ? 1 : $page,
                'per_page' => $exportAll ? $total : $perPage,
                'total_pages' => $exportAll ? 1 : (int) ceil($total / max($perPage, 1)),
                'export_all' => $exportAll,
            ], 200);
        } catch (\Exception $e) {
            $this->logController->saveLog(
                'Nombre de Controlador: IngresosControllers, Nombre de Funcion: consultarIngresos(Request $request)',
                'Error al consultar ingresos: ' . $e->getMessage()
            );

            Log::error('Error al consultar ingresos: ' . $e->getMessage());

            return response()->json([
                'error' => 'Error al consultar ingresos: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getConsultarIngresosId($id)
    {
        try {
            $data = DB::table('inventarios.ingresos as ei')
                ->select(
                    'ei.ei_id',
                    'ei.ei_numero_comprobante',
                    'ei.ei_numero_ingreso',
                    'ei.ei_tipo_adquisicion',
                    DB::raw("
                        CASE ei.ei_tipo_adquisicion
                            WHEN 1 THEN 'Factura'
                            WHEN 2 THEN 'Donación'
                            WHEN 3 THEN 'Donación Laboratorios'
                            ELSE '—'
                        END AS tipo_adquisicion_nombre
                    "),
                    'ei.ei_id_proveedor',
                    'p.prov_id as proveedor_id',
                    'p.prov_nombre as proveedor_nombre',
                    'p.prov_ruc',
                    'ei.ei_fecha_emision',
                    'ei.ei_fecha_vencimiento',
                    'ei.ei_created_at',
                    'ei.ei_updated_at',
                    'ei.ei_id_user',
                    'uu.name as nombre_usuario',
                    'uu.email as email_usuario',
                    'ei.ei_detalle_producto',
                    'ei.ei_id_estado',
                    'e.estado as estado_nombre',
                    'ei.ei_ruta_comprobante',
                    'ei.ei_id_bodega',
                    'b.bod_id as bodega_id',
                    'b.bod_nombre as bodega_nombre',
                    'b.bod_id_sede as bodega_id_sede',
                    'b.bod_id_facultad as bodega_id_facultad',
                    'b.bod_id_carrera as bodega_id_carrera',
                    'b.bod_estado as bodega_estado',
                    's.id as sede_id',
                    's.nombre_sede as sede_nombre',
                    'f.id as facultad_id',
                    'f.fac_nombre as facultad_nombre'
                )
                ->leftJoin('inventarios.proveedores as p', 'ei.ei_id_proveedor', '=', 'p.prov_id')
                ->leftJoin('inventarios.bodegas as b', 'ei.ei_id_bodega', '=', 'b.bod_id')
                ->leftJoin('cpu_sede as s', 'b.bod_id_sede', '=', 's.id')
                ->leftJoin('cpu_facultad as f', 'b.bod_id_facultad', '=', 'f.id')
                ->leftJoin('users as uu', 'ei.ei_id_user', '=', 'uu.id')
                ->leftJoin('cpu_estados as e', 'ei.ei_id_estado', '=', 'e.id')
                ->where('ei.ei_id', '=', $id)
                ->first();

            if (!$data) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró el ingreso solicitado.'
                ], 404);
            }

            return response()->json($data, 200);
        } catch (\Exception $e) {
            $this->logController->saveLog(
                'Nombre de Controlador: IngresosControllers, Nombre de Funcion: getConsultarIngresosId($id)',
                'Error al consultar ingresos: ' . $e->getMessage()
            );

            Log::error('Error al consultar ingresos: ' . $e->getMessage());

            return response()->json([
                'error' => 'Error al consultar ingresos: ' . $e->getMessage()
            ], 500);
        }
    }

    public function guardarIngresos(Request $request)
    {
        DB::beginTransaction();
        $descripcionAuditoria = [];

        try {
            $encabezadoReq = json_decode($request->input('encabezado'), true);
            $detalleProductos = json_decode($request->input('detalleProductos'), true);

            if (!is_array($encabezadoReq)) {
                throw new \Exception("Encabezado inválido.");
            }

            if (!is_array($detalleProductos) || count($detalleProductos) === 0) {
                throw new \Exception("Detalle de productos inválido o vacío.");
            }

            if (!$request->user() || !$request->user()->id) {
                throw new \Exception("No se pudo identificar el usuario autenticado.");
            }

            $tipoAdq = (int) ($encabezadoReq['tipo_adquisicion'] ?? 0);

            if (!in_array($tipoAdq, [1, 2, 3], true)) {
                throw new \Exception("Tipo de adquisición inválido: {$tipoAdq}");
            }

            if (empty($encabezadoReq['select_bodega'])) {
                throw new \Exception("Debe seleccionar una bodega.");
            }

            $idBodega = (int) $encabezadoReq['select_bodega'];

            $bodega = DB::table('inventarios.bodegas')
                ->where('bod_id', $idBodega)
                ->first();

            if (!$bodega) {
                throw new \Exception("La bodega seleccionada no existe.");
            }

            if ($tipoAdq === 3) {
                $encabezadoReq['n_comprobante'] = null;
                $encabezadoReq['fecha_emision'] = null;
                $encabezadoReq['fecha_vencimiento'] = null;
            }

            if ($tipoAdq !== 3) {
                if (empty($encabezadoReq['n_ingreso'])) {
                    throw new \Exception("El número de ingreso es obligatorio.");
                }

                if (empty($encabezadoReq['fecha_emision'])) {
                    throw new \Exception("La fecha de emisión es obligatoria.");
                }

                if (
                    !empty($encabezadoReq['fecha_vencimiento']) &&
                    $encabezadoReq['fecha_vencimiento'] < $encabezadoReq['fecha_emision']
                ) {
                    throw new \Exception("La fecha de vencimiento no puede ser menor a la fecha de emisión.");
                }
            }

            foreach ($detalleProductos as $index => &$item) {
                $fila = $index + 1;
                $idInsumo = (int) ($item['idInsumo'] ?? $item['id'] ?? 0);

                if ($idInsumo <= 0) {
                    throw new \Exception("El insumo de la fila {$fila} es inválido.");
                }

                $insumo = DB::table('inventarios.productos')
                    ->where('id', $idInsumo)
                    ->first();

                if (!$insumo) {
                    throw new \Exception("No existe el insumo de la fila {$fila}.");
                }

                $item['idInsumo'] = $idInsumo;

                $usaLotes = (bool) ($insumo->requiere_lote || $insumo->requiere_vencimiento);

                if ($usaLotes) {
                    $lotes = $item['lotes'] ?? $item['desglose_lotes'] ?? [];

                    if (!is_array($lotes) || count($lotes) === 0) {
                        throw new \Exception("El insumo {$insumo->ins_descripcion} requiere lotes.");
                    }

                    $sumaLotes = 0;
                    $codigos = [];

                    foreach ($lotes as $lotIndex => $lot) {
                        $filaLote = $lotIndex + 1;
                        $codigoLote = trim((string) ($lot['codigo_lote'] ?? $lot['codigo'] ?? $lot['lote'] ?? ''));
                        $fechaElaboracion = $lot['fecha_elaboracion'] ?? null;
                        $fechaVencimiento = $lot['fecha_vencimiento'] ?? null;
                        $cantidadLote = (float) ($lot['cantidad'] ?? $lot['cantidad_inicial'] ?? 0);

                        if ($codigoLote === '') {
                            throw new \Exception("El código del lote #{$filaLote} del insumo {$insumo->ins_descripcion} es obligatorio.");
                        }

                        $codigoNormalizado = mb_strtolower($codigoLote);

                        if (in_array($codigoNormalizado, $codigos, true)) {
                            throw new \Exception("El código de lote \"{$codigoLote}\" está repetido para el insumo {$insumo->ins_descripcion}.");
                        }

                        $codigos[] = $codigoNormalizado;

                        if (empty($fechaElaboracion)) {
                            throw new \Exception("La fecha de elaboración del lote \"{$codigoLote}\" es obligatoria.");
                        }

                        if ((bool) $insumo->requiere_vencimiento && empty($fechaVencimiento)) {
                            throw new \Exception("La fecha de vencimiento del lote \"{$codigoLote}\" es obligatoria.");
                        }

                        if (
                            !empty($fechaElaboracion) &&
                            !empty($fechaVencimiento) &&
                            strtotime($fechaElaboracion) > strtotime($fechaVencimiento)
                        ) {
                            throw new \Exception("La fecha de elaboración no puede ser mayor a la fecha de vencimiento del lote \"{$codigoLote}\".");
                        }

                        if ($cantidadLote <= 0) {
                            throw new \Exception("La cantidad del lote \"{$codigoLote}\" debe ser mayor a 0.");
                        }

                        $sumaLotes += $cantidadLote;
                    }

                    if ($sumaLotes <= 0) {
                        throw new \Exception("La suma de lotes del insumo {$insumo->ins_descripcion} debe ser mayor a 0.");
                    }

                    $item['cantidad'] = $sumaLotes;
                } else {
                    $cantidad = (float) ($item['cantidad'] ?? 0);

                    if ($cantidad <= 0) {
                        throw new \Exception("La cantidad del insumo {$insumo->ins_descripcion} debe ser mayor a 0.");
                    }
                }
            }

            unset($item);

            $descripcionTipo = match ($tipoAdq) {
                1 => "Tipo de adquisición: Factura",
                2 => "Tipo de adquisición: Donación",
                3 => "Tipo de adquisición: Donación Laboratorios",
            };

            $estadoMovimiento = match ($tipoAdq) {
                1 => 25,
                2 => 26,
                3 => 35,
            };

            $encabezado = [
                'ei_numero_comprobante' => $encabezadoReq['n_comprobante'] ?? null,
                'ei_numero_ingreso'     => $encabezadoReq['n_ingreso'] ?? null,
                'ei_tipo_adquisicion'   => $tipoAdq,
                'ei_id_proveedor'       => !empty($encabezadoReq['id_proveedor']) ? $encabezadoReq['id_proveedor'] : null,
                'ei_fecha_emision'      => $encabezadoReq['fecha_emision'] ?? null,
                'ei_fecha_vencimiento'  => $encabezadoReq['fecha_vencimiento'] ?? null,
                'ei_created_at'         => now(),
                'ei_updated_at'         => now(),
                'ei_id_bodega'          => $idBodega,
                'ei_id_user'            => (int) $request->user()->id,
                'ei_id_estado'          => 8,
                'ei_detalle_producto'   => json_encode($detalleProductos, JSON_UNESCAPED_UNICODE),
            ];

            $idEncabezado = DB::table('inventarios.ingresos')->insertGetId($encabezado, 'ei_id');

            $descripcionAuditoria[] = "Se creó encabezado de ingreso con ID: {$idEncabezado}";
            $descripcionAuditoria[] = $descripcionTipo;

            $observacion = match ($tipoAdq) {
                1 => 'Ingreso por Factura',
                2 => 'Ingreso por Donación',
                3 => 'Ingreso por Donación Laboratorios',
            };

            $this->inventariosController->guardarMovimientoInventario(
                $detalleProductos,
                $idBodega,
                'INGRESO',
                $estadoMovimiento,
                (int) $request->user()->id,
                $idEncabezado,
                $observacion
            );

            if ($tipoAdq !== 3 && $request->hasFile('archivo_comprobante')) {
                $archivo = $request->file('archivo_comprobante');

                if ($archivo->getClientMimeType() !== 'application/pdf') {
                    throw new \Exception("El archivo debe ser PDF.");
                }

                $nombreArchivo = time() . '_' . $archivo->getClientOriginalName();
                $ruta = public_path('Files/comprobantes_ingresos');

                if (!file_exists($ruta)) {
                    mkdir($ruta, 0755, true);
                }

                $archivo->move($ruta, $nombreArchivo);

                DB::table('inventarios.ingresos')
                    ->where('ei_id', $idEncabezado)
                    ->update([
                        'ei_ruta_comprobante' => $nombreArchivo,
                        'ei_updated_at'       => now(),
                    ]);

                $descripcionAuditoria[] = "Se subió archivo para ingreso ID {$idEncabezado}: {$nombreArchivo}";
            }

            DB::commit();

            $this->auditoriaController->auditar(
                'inventarios.ingresos',
                'guardarIngresos(Request $request)',
                json_encode([]),
                json_encode($request->all()),
                'INSERT',
                implode(' | ', $descripcionAuditoria)
            );

            return response()->json([
                'success' => true,
                'message' => 'Ingreso guardado correctamente',
                'id'      => $idEncabezado,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            $this->auditoriaController->auditar(
                'inventarios.ingresos',
                'guardarIngresos(Request $request)',
                json_encode([]),
                json_encode($request->all()),
                'ERROR',
                'Error al guardar ingreso: ' . $e->getMessage()
            );

            $this->logController->saveLog(
                'Controlador: IngresosControllers, Función: guardarIngresos(Request $request)',
                'Error al guardar: ' . $e->getMessage()
            );

            return response()->json([
                'error' => 'Error al guardar: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getIdNumeroIngreso()
    {
        $ultimoIngreso = DB::table('inventarios.ingresos')->max('ei_id');
        $siguiente = ((int) $ultimoIngreso) + 1;

        return 'ULEAM-DBU-I-' . str_pad($siguiente, 6, '0', STR_PAD_LEFT);
    }

    public function getKardexMovimiento()
    {
        try {
            $data = DB::select("
                WITH stock_cfg AS (
                    SELECT
                        sb.sb_id_insumo,
                        sb.sb_id_bodega,
                        MAX(COALESCE(sb.sb_stock_minimo, 0)) AS stock_minimo
                    FROM inventarios.stock_bodegas sb
                    GROUP BY sb.sb_id_insumo, sb.sb_id_bodega
                )
                SELECT
                    m.mi_id,
                    COALESCE(m.mi_fecha, m.mi_created_at) AS fecha_movimiento,
                    m.mi_fecha,
                    m.mi_created_at,
                    m.mi_updated_at,
                    m.mi_tipo_transaccion,
                    CASE
                        WHEN m.mi_tipo_transaccion = 1 THEN 'Ingreso'
                        WHEN m.mi_tipo_transaccion = 2 THEN 'Egreso'
                        ELSE 'Otro'
                    END AS tipo_movimiento,
                    m.mi_cantidad,
                    m.mi_stock_anterior,
                    m.mi_stock_actual,
                    m.mi_observacion,
                    m.mi_id_insumo,
                    ins.codigo AS codigo_insumo,
                    ins.ins_descripcion AS nombre_insumo,
                    ins.unidad_medida,
                    ins.marca,
                    ins.modelo,
                    ins.serie,
                    ins.requiere_lote,
                    ins.requiere_vencimiento,
                    m.mi_id_bodega,
                    b.bod_nombre AS nombre_bodega,
                    b.bod_id_sede,
                    s.nombre_sede,
                    b.bod_id_facultad,
                    f.fac_nombre AS nombre_facultad,
                    COALESCE(sc.stock_minimo, 0) AS stock_minimo,
                    CASE
                        WHEN COALESCE(m.mi_stock_actual, 0) <= 0 THEN 'SIN STOCK'
                        WHEN COALESCE(sc.stock_minimo, 0) > 0
                            AND COALESCE(m.mi_stock_actual, 0) <= sc.stock_minimo THEN 'BAJO MINIMO'
                        ELSE 'OK'
                    END AS estado_stock,
                    m.mi_id_estado,
                    est.estado AS estado_movimiento,
                    m.mi_id_lote,
                    lot.codigo_lote,
                    lot.fecha_elaboracion,
                    lot.fecha_vencimiento,
                    lot.cantidad_inicial AS lote_cantidad_inicial,
                    lot.cantidad_actual AS lote_cantidad_actual,
                    CASE
                        WHEN m.mi_id_lote IS NULL THEN 'SIN LOTE'
                        WHEN lot.fecha_vencimiento IS NULL THEN 'SIN VENCIMIENTO'
                        WHEN lot.fecha_vencimiento <= CURRENT_DATE THEN 'VENCIDO'
                        WHEN lot.fecha_vencimiento <= CURRENT_DATE + INTERVAL '30 days' THEN 'POR VENCER'
                        ELSE 'VIGENTE'
                    END AS estado_lote,
                    u_mov.id AS id_usuario_movimiento,
                    u_mov.name AS nombre_usuario_movimiento,
                    u_mov.email AS email_usuario_movimiento,
                    m.mi_id_encabezado,
                    CASE
                        WHEN m.mi_id_estado IN (54, 62) THEN 'ENCABEZADO_BAJA'
                        WHEN m.mi_tipo_transaccion = 1 THEN 'ENCABEZADO_INGRESO'
                        WHEN m.mi_tipo_transaccion = 2 THEN 'ENCABEZADO_EGRESO'
                        ELSE 'SIN_ENCABEZADO'
                    END AS origen_documento,
                    i.ei_id AS id_ingreso,
                    i.ei_numero_ingreso,
                    i.ei_numero_comprobante,
                    i.ei_fecha_emision,
                    i.ei_fecha_vencimiento,
                    i.ei_detalle_producto,
                    i.ei_id_funcionario AS ingreso_id_funcionario,
                    i.ei_id_proveedor,
                    i.ei_id_user AS id_usuario_registra_ingreso,
                    u_ing.name AS nombre_usuario_registra_ingreso,
                    u_ing.email AS email_usuario_registra_ingreso,
                    u_func_ing.id AS id_funcionario_ingreso,
                    u_func_ing.name AS nombre_funcionario_ingreso,
                    u_func_ing.email AS email_funcionario_ingreso,
                    ba.id AS id_baja,
                    ba.numero_baja,
                    ba.fecha AS fecha_baja,
                    ba.motivo AS motivo_baja,
                    ba.observacion AS observacion_baja,
                    ba.documento_referencia AS documento_referencia_baja,
                    u_baja.id AS id_usuario_baja,
                    u_baja.name AS nombre_usuario_baja,
                    u_baja.email AS email_usuario_baja,
                    e.ee_id AS id_egreso,
                    e.ee_numero_egreso,
                    e.ee_id_funcionario,
                    e.ee_cedula_funcionario,
                    e.ee_id_paciente,
                    e.ee_cedula_paciente,
                    e.ee_id_user,
                    e.ee_observacion AS observacion_egreso,
                    u_pres.id AS id_usuario_prescribe,
                    u_pres.name AS nombre_usuario_prescribe,
                    u_pres.email AS email_usuario_prescribe,
                    u_ent.id AS id_usuario_entrega,
                    u_ent.name AS nombre_usuario_entrega,
                    u_ent.email AS email_usuario_entrega,
                    CASE
                        WHEN m.mi_id_estado IN (54, 62) THEN COALESCE(ba.numero_baja, 'N/A')
                        WHEN m.mi_tipo_transaccion = 1 THEN COALESCE(i.ei_numero_ingreso, 'N/A')
                        WHEN m.mi_tipo_transaccion = 2 THEN COALESCE(e.ee_numero_egreso, 'N/A')
                        ELSE 'N/A'
                    END AS numero_movimiento,
                    CASE
                        WHEN m.mi_id_estado IN (54, 62) THEN COALESCE(ba.documento_referencia, ba.numero_baja, 'N/A')
                        WHEN m.mi_tipo_transaccion = 1 THEN COALESCE(i.ei_numero_comprobante, i.ei_numero_ingreso, 'N/A')
                        WHEN m.mi_tipo_transaccion = 2 THEN COALESCE(e.ee_numero_egreso, 'N/A')
                        ELSE 'N/A'
                    END AS numero_comprobante,
                    CASE
                        WHEN m.mi_id_estado IN (54, 62) THEN
                            'Baja ' || COALESCE(ba.numero_baja, CAST(ba.id AS text), CAST(m.mi_id AS text))
                        WHEN m.mi_tipo_transaccion = 1 THEN
                            'Ingreso ' || COALESCE(i.ei_numero_ingreso, CAST(i.ei_id AS text), CAST(m.mi_id AS text))
                        WHEN m.mi_tipo_transaccion = 2 THEN
                            'Egreso ' || COALESCE(e.ee_numero_egreso, CAST(e.ee_id AS text), CAST(m.mi_id AS text))
                        ELSE
                            'Movimiento ' || CAST(m.mi_id AS text)
                    END AS referencia_documental,
                    CASE
                        WHEN m.mi_id_estado IN (54, 62) THEN u_baja.name
                        WHEN m.mi_tipo_transaccion = 1 THEN u_ing.name
                        WHEN m.mi_tipo_transaccion = 2 THEN u_ent.name
                        ELSE u_mov.name
                    END AS nombre_usuario_documento,
                    CASE
                        WHEN m.mi_id_estado IN (54, 62) THEN u_baja.email
                        WHEN m.mi_tipo_transaccion = 1 THEN u_ing.email
                        WHEN m.mi_tipo_transaccion = 2 THEN u_ent.email
                        ELSE u_mov.email
                    END AS email_usuario_documento
                FROM inventarios.movimientos m
                LEFT JOIN inventarios.productos ins
                    ON ins.id = m.mi_id_insumo
                LEFT JOIN inventarios.productos_lotes lot
                    ON lot.id = m.mi_id_lote
                LEFT JOIN cpu_estados est
                    ON est.id = m.mi_id_estado
                LEFT JOIN inventarios.bodegas b
                    ON b.bod_id = m.mi_id_bodega
                LEFT JOIN cpu_facultad f
                    ON f.id = b.bod_id_facultad
                LEFT JOIN cpu_sede s
                    ON s.id = b.bod_id_sede
                LEFT JOIN stock_cfg sc
                    ON sc.sb_id_insumo = m.mi_id_insumo
                    AND sc.sb_id_bodega = m.mi_id_bodega
                LEFT JOIN inventarios.ingresos i
                    ON i.ei_id = m.mi_id_encabezado
                    AND m.mi_tipo_transaccion = 1
                    AND m.mi_id_estado <> 62
                LEFT JOIN inventarios.bajas ba
                    ON ba.id = m.mi_id_encabezado
                    AND m.mi_id_estado IN (54, 62)
                LEFT JOIN inventarios.egresos e
                    ON e.ee_id = m.mi_id_encabezado
                    AND m.mi_tipo_transaccion = 2
                    AND m.mi_id_estado <> 54
                LEFT JOIN users u_mov
                    ON u_mov.id = m.mi_user_id
                LEFT JOIN users u_ing
                    ON u_ing.id = i.ei_id_user
                LEFT JOIN users u_func_ing
                    ON u_func_ing.id = i.ei_id_funcionario
                LEFT JOIN users u_baja
                    ON u_baja.id = ba.user_id
                LEFT JOIN users u_pres
                    ON u_pres.id = e.ee_id_funcionario
                LEFT JOIN users u_ent
                    ON u_ent.id = e.ee_id_user
                ORDER BY COALESCE(m.mi_fecha, m.mi_created_at) DESC, m.mi_id DESC
            ");

            return response()->json($data);
        } catch (\Exception $e) {
            $this->logController->saveLog(
                'Nombre de Controlador: IngresosControllers, Nombre de Funcion: getKardexMovimiento()',
                'Error al obtener movimientos: ' . $e->getMessage()
            );

            return response()->json([
                'error' => 'Error al obtener movimientos: ' . $e->getMessage()
            ], 500);
        }
    }
}
