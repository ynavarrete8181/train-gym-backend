<?php

namespace App\Http\Controllers\Inventarios;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BajasInventarioController extends Controller
{
    protected $inventariosController;

    public function __construct()
    {
        $this->middleware('auth:api');
        $this->inventariosController = new CpuInventariosController();
    }

    private function inventariosExistsRule(string $table, string $column, string $label, bool $required = true): array
    {
        $rules = [];

        if ($required) {
            $rules[] = 'required';
        }

        $rules[] = 'integer';
        $rules[] = function ($attribute, $value, $fail) use ($table, $column, $label) {
            if (!DB::table("inventarios.{$table}")->where($column, $value)->exists()) {
                $fail("El {$label} seleccionado no existe.");
            }
        };

        return $rules;
    }

    public function getBajasInventario(Request $request)
    {
        $perPage = max((int) $request->get('per_page', 10), 1);

        $q = trim((string) $request->get('q', ''));
        $estadoId = $request->get('estado_id');
        $motivo = trim((string) $request->get('motivo', ''));
        $bodegaId = $request->get('bodega_id');

        $query = DB::table('inventarios.bajas as b')
            ->leftJoin('inventarios.bodegas as bo', 'bo.bod_id', '=', 'b.bodega_id')
            ->leftJoin('cpu_estados as e', 'e.id', '=', 'b.estado_id')
            ->leftJoin('users as u', 'u.id', '=', 'b.user_id')
            ->select(
                'b.id',
                'b.numero_baja',
                'b.fecha',
                'b.motivo',
                'b.observacion',
                'b.documento_referencia',
                'b.total_items',
                'b.total_cantidad',
                'b.created_at',
                'b.updated_at',
                'bo.bod_nombre as bodega_nombre',
                'e.estado as estado_nombre',
                'u.name as usuario_nombre'
            );

        if ($q !== '') {
            $like = "%{$q}%";
            $query->where(function ($sub) use ($like) {
                $sub->where('b.numero_baja', 'ILIKE', $like)
                    ->orWhere('b.motivo', 'ILIKE', $like)
                    ->orWhere('b.observacion', 'ILIKE', $like)
                    ->orWhere('b.documento_referencia', 'ILIKE', $like)
                    ->orWhere('bo.bod_nombre', 'ILIKE', $like)
                    ->orWhere('u.name', 'ILIKE', $like)
                    ->orWhere('e.estado', 'ILIKE', $like);
            });
        }

        if (!empty($estadoId) && $estadoId !== '0') {
            $query->where('b.estado_id', $estadoId);
        }

        if ($motivo !== '' && $motivo !== '0') {
            $query->where('b.motivo', $motivo);
        }

        if (!empty($bodegaId) && $bodegaId !== '0') {
            $query->where('b.bodega_id', $bodegaId);
        }

        $rows = $query
            ->orderByDesc('b.id')
            ->paginate($perPage);

        return response()->json($rows);
    }

    public function getBajaInventarioDetalle($id)
    {
        $encabezado = DB::table('inventarios.bajas as b')
            ->leftJoin('inventarios.bodegas as bo', 'bo.bod_id', '=', 'b.bodega_id')
            ->leftJoin('cpu_estados as e', 'e.id', '=', 'b.estado_id')
            ->leftJoin('users as u', 'u.id', '=', 'b.user_id')
            ->select(
                'b.id',
                'b.numero_baja',
                'b.fecha',
                'b.motivo',
                'b.observacion',
                'b.documento_referencia',
                'b.total_items',
                'b.total_cantidad',
                'b.created_at',
                'b.updated_at',
                'bo.bod_nombre as bodega_nombre',
                'e.estado as estado_nombre',
                'u.name as usuario_nombre'
            )
            ->where('b.id', $id)
            ->first();

        if (!$encabezado) {
            return response()->json([
                'status' => 'error',
                'message' => 'La baja de inventario no existe.',
            ], 404);
        }

        $detalles = DB::table('inventarios.bajas_detalles as d')
            ->join('inventarios.productos as i', 'i.id', '=', 'd.insumo_id')
            ->leftJoin('inventarios.categorias_activos as c', 'c.ca_id', '=', 'i.id_tipo_insumo')
            ->select(
                'd.id',
                'd.encabezado_baja_id',
                'd.insumo_id',
                'd.cantidad',
                'd.observacion',
                'd.created_at',
                'i.codigo',
                'i.ins_descripcion as insumo_descripcion',
                'c.ca_descripcion as categoria_nombre'
            )
            ->where('d.encabezado_baja_id', $id)
            ->orderBy('d.id')
            ->get();

        $detalleIds = $detalles->pluck('id')->all();
        $lotesPorDetalle = [];

        if (!empty($detalleIds)) {
            $lotes = DB::table('inventarios.bajas_detalles_lotes as dl')
                ->select(
                    'dl.id',
                    'dl.detalle_baja_id',
                    'dl.lote_id',
                    'dl.codigo_lote',
                    'dl.fecha_elaboracion',
                    'dl.fecha_vencimiento',
                    'dl.cantidad',
                    'dl.stock_anterior',
                    'dl.stock_posterior',
                    'dl.created_at'
                )
                ->whereIn('dl.detalle_baja_id', $detalleIds)
                ->orderBy('dl.id')
                ->get();

            foreach ($lotes as $lote) {
                $lotesPorDetalle[$lote->detalle_baja_id][] = $lote;
            }
        }

        $detalles = $detalles->map(function ($item) use ($lotesPorDetalle) {
            $item->lotes = $lotesPorDetalle[$item->id] ?? [];
            return $item;
        });

        return response()->json([
            'status' => 'success',
            'data' => [
                'encabezado' => $encabezado,
                'detalles' => $detalles,
            ],
        ]);
    }

    public function getInsumosBajaDisponibles(Request $request)
    {
        $request->validate([
            'bodega_id' => $this->inventariosExistsRule('bodegas', 'bod_id', 'bodega'),
            'q' => 'nullable|string',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $bodegaId = (int) $request->bodega_id;
        $perPage = (int) $request->get('per_page', 20);
        $q = trim((string) $request->get('q', ''));

        $query = DB::table('inventarios.stock_bodegas as sb')
            ->join('inventarios.productos as i', 'i.id', '=', 'sb.sb_id_insumo')
            ->leftJoin('inventarios.categorias_activos as c', 'c.ca_id', '=', 'i.id_tipo_insumo')
            ->select(
                'i.id',
                'i.codigo',
                'i.ins_descripcion',
                'i.id_tipo_insumo',
                'i.requiere_lote',
                'i.requiere_vencimiento',
                'i.id_estado',
                'sb.sb_id as stock_bodega_id',
                'sb.sb_cantidad as stock_disponible',
                'sb.sb_stock_minimo',
                DB::raw("COALESCE(c.ca_descripcion, '') as categoria_nombre"),
            )
            ->where('sb.sb_id_bodega', $bodegaId)
            ->where('sb.sb_cantidad', '>', 0);

        if ($q !== '') {
            $like = "%{$q}%";
            $query->where(function ($sub) use ($like) {
                $sub->where('i.codigo', 'ILIKE', $like)
                    ->orWhere('i.ins_descripcion', 'ILIKE', $like)
                    ->orWhere('c.ca_descripcion', 'ILIKE', $like);
            });
        }

        $rows = $query
            ->orderBy('i.ins_descripcion')
            ->paginate($perPage);

        return response()->json($rows);
    }

    public function getInsumoLotesDisponibles(Request $request)
    {
        $request->validate([
            'bodega_id' => $this->inventariosExistsRule('bodegas', 'bod_id', 'bodega'),
            'insumo_id' => $this->inventariosExistsRule('productos', 'id', 'producto'),
        ]);

        $bodegaId = (int) $request->bodega_id;
        $insumoId = (int) $request->insumo_id;

        $rows = DB::table('inventarios.productos_lotes')
            ->select(
                'id',
                'id_insumo',
                'id_bodega',
                'codigo_lote',
                'fecha_elaboracion',
                'fecha_vencimiento',
                'cantidad_inicial',
                'cantidad_actual',
                'id_estado',
                'created_at',
                'updated_at'
            )
            ->where('id_bodega', $bodegaId)
            ->where('id_insumo', $insumoId)
            ->where('cantidad_actual', '>', 0)
            ->orderByRaw('fecha_vencimiento asc nulls last')
            ->orderByRaw('fecha_elaboracion asc nulls last')
            ->orderBy('codigo_lote')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $rows,
        ]);
    }

    public function getInsumosVencidosBodega(Request $request)
    {
        $request->validate([
            'bodega_id' => $this->inventariosExistsRule('bodegas', 'bod_id', 'bodega'),
            'q' => 'nullable|string',
        ]);

        $bodegaId = (int) $request->bodega_id;
        $q = trim((string) $request->get('q', ''));
        $estadoDadoDeBajaId = $this->getEstadoIdByNombre('Dado de baja') ?: 54;

        $query = DB::table('inventarios.productos_lotes as l')
            ->join('inventarios.productos as i', 'i.id', '=', 'l.id_insumo')
            ->join('inventarios.bodegas as b', 'b.bod_id', '=', 'l.id_bodega')
            ->leftJoin('inventarios.categorias_activos as c', 'c.ca_id', '=', 'i.id_tipo_insumo')
            ->select(
                'i.id as insumo_id',
                'i.codigo',
                'i.ins_descripcion',
                'i.id_tipo_insumo',
                'c.ca_descripcion as categoria_nombre',
                'b.bod_nombre as bodega_nombre',
                DB::raw('COUNT(l.id) as total_lotes_vencidos'),
                DB::raw('COALESCE(SUM(l.cantidad_actual), 0) as stock_vencido')
            )
            ->where('l.id_bodega', $bodegaId)
            ->whereNotNull('l.fecha_vencimiento')
            ->where('l.fecha_vencimiento', '<=', DB::raw('CURRENT_DATE'))
            ->where('l.cantidad_actual', '>', 0)
            ->where(function ($sub) use ($estadoDadoDeBajaId) {
                $sub->whereNull('l.id_estado')
                    ->orWhere('l.id_estado', '<>', $estadoDadoDeBajaId);
            });

        if ($q !== '') {
            $like = "%{$q}%";
            $query->where(function ($sub) use ($like) {
                $sub->where('i.codigo', 'ILIKE', $like)
                    ->orWhere('i.ins_descripcion', 'ILIKE', $like)
                    ->orWhere('c.ca_descripcion', 'ILIKE', $like);
            });
        }

        $rows = $query
            ->groupBy(
                'i.id',
                'i.codigo',
                'i.ins_descripcion',
                'i.id_tipo_insumo',
                'c.ca_descripcion',
                'b.bod_nombre'
            )
            ->orderBy('i.ins_descripcion')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $rows,
        ]);
    }

    public function getLotesVencidosBodega(Request $request)
    {
        $request->validate([
            'bodega_id' => $this->inventariosExistsRule('bodegas', 'bod_id', 'bodega'),
            'insumo_id' => $this->inventariosExistsRule('productos', 'id', 'producto'),
        ]);

        $bodegaId = (int) $request->bodega_id;
        $insumoId = (int) $request->insumo_id;
        $estadoDadoDeBajaId = $this->getEstadoIdByNombre('Dado de baja') ?: 54;

        $rows = DB::table('inventarios.productos_lotes as l')
            ->select(
                'l.id as lote_id',
                'l.id_insumo',
                'l.id_bodega',
                'l.codigo_lote',
                'l.fecha_elaboracion',
                'l.fecha_vencimiento',
                'l.cantidad_inicial',
                'l.cantidad_actual',
                'l.id_estado',
                DB::raw('(CURRENT_DATE - l.fecha_vencimiento) as dias_vencido')
            )
            ->where('l.id_bodega', $bodegaId)
            ->where('l.id_insumo', $insumoId)
            ->whereNotNull('l.fecha_vencimiento')
            ->where('l.fecha_vencimiento', '<=', DB::raw('CURRENT_DATE'))
            ->where('l.cantidad_actual', '>', 0)
            ->where(function ($sub) use ($estadoDadoDeBajaId) {
                $sub->whereNull('l.id_estado')
                    ->orWhere('l.id_estado', '<>', $estadoDadoDeBajaId);
            })
            ->orderBy('l.fecha_vencimiento', 'asc')
            ->orderBy('l.codigo_lote', 'asc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $rows,
        ]);
    }

    public function storeBajaInventario(Request $request)
    {
        $validated = $request->validate([
            'bodega_id' => $this->inventariosExistsRule('bodegas', 'bod_id', 'bodega'),
            'motivo' => 'required|string|max:255',
            'observacion' => 'nullable|string',
            'documento_referencia' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.insumo_id' => $this->inventariosExistsRule('productos', 'id', 'producto'),
            'items.*.cantidad' => 'required|numeric|gt:0',
            'items.*.observacion' => 'nullable|string',
            'items.*.lotes' => 'nullable|array',
            'items.*.lotes.*.lote_id' => [
                'required_with:items.*.lotes',
                'integer',
                function ($attribute, $value, $fail) {
                    if (!DB::table('inventarios.productos_lotes')->where('id', $value)->exists()) {
                        $fail('El lote seleccionado no existe.');
                    }
                },
            ],
            'items.*.lotes.*.cantidad' => 'required_with:items.*.lotes|numeric|gt:0',
        ]);

        try {
            $result = DB::transaction(function () use ($validated, $request) {
                $numeroBaja = $this->generarNumeroBaja();
                $estadoRegistradoId = $this->getEstadoIdByNombre('REGISTRADO') ?: 36;
                $userId = optional($request->user())->id;

                if (!$userId) {
                    throw ValidationException::withMessages([
                        'user' => ['No se pudo identificar el usuario autenticado.'],
                    ]);
                }

                $encabezadoId = DB::table('inventarios.bajas')->insertGetId([
                    'numero_baja' => $numeroBaja,
                    'fecha' => now(),
                    'bodega_id' => $validated['bodega_id'],
                    'estado_id' => $estadoRegistradoId,
                    'motivo' => trim($validated['motivo']),
                    'observacion' => $validated['observacion'] ?? null,
                    'documento_referencia' => $validated['documento_referencia'] ?? null,
                    'detalle' => null,
                    'total_items' => 0,
                    'total_cantidad' => 0,
                    'user_id' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $listaInsumos = [];
                $metadatos = [];

                foreach ($validated['items'] as $index => $itemData) {
                    $insumo = DB::table('inventarios.productos')
                        ->where('id', $itemData['insumo_id'])
                        ->lockForUpdate()
                        ->first();

                    if (!$insumo) {
                        throw ValidationException::withMessages([
                            'items' => ["El insumo con ID {$itemData['insumo_id']} no existe."],
                        ]);
                    }

                    $stockBodega = DB::table('inventarios.stock_bodegas')
                        ->where('sb_id_bodega', $validated['bodega_id'])
                        ->where('sb_id_insumo', $insumo->id)
                        ->lockForUpdate()
                        ->first();

                    if (!$stockBodega) {
                        throw ValidationException::withMessages([
                            'items' => ["No existe stock en bodega para el insumo {$insumo->ins_descripcion}."],
                        ]);
                    }

                    $requiereLote = (bool) ($insumo->requiere_lote || $insumo->requiere_vencimiento);
                    $cantidadSolicitada = (float) $itemData['cantidad'];

                    if ($cantidadSolicitada <= 0) {
                        throw ValidationException::withMessages([
                            'items' => ["La cantidad para {$insumo->ins_descripcion} debe ser mayor a 0."],
                        ]);
                    }

                    $itemMovimiento = [
                        'idInsumo' => (int) $insumo->id,
                    ];

                    $cantidadDetalle = $cantidadSolicitada;

                    if ($requiereLote) {
                        if (empty($itemData['lotes']) || !is_array($itemData['lotes'])) {
                            throw ValidationException::withMessages([
                                'items' => ["El insumo {$insumo->ins_descripcion} requiere desglose por lotes."],
                            ]);
                        }

                        $lotesMovimiento = [];
                        $cantidadDetalle = 0;

                        foreach ($itemData['lotes'] as $loteData) {
                            $cantidad = (float) $loteData['cantidad'];

                            if ($cantidad <= 0) {
                                continue;
                            }

                            $lote = DB::table('inventarios.productos_lotes')
                                ->where('id', $loteData['lote_id'])
                                ->where('id_insumo', $insumo->id)
                                ->where('id_bodega', $validated['bodega_id'])
                                ->lockForUpdate()
                                ->first();

                            if (!$lote) {
                                throw ValidationException::withMessages([
                                    'items' => ["El lote {$loteData['lote_id']} no pertenece al insumo o a la bodega seleccionada."],
                                ]);
                            }

                            // TOMAR EL STOCK ANTES DE ACTUALIZAR
                            $stockAnterior = (float) $lote->cantidad_actual;

                            if ($cantidad > $stockAnterior) {
                                throw ValidationException::withMessages([
                                    'items' => ["La cantidad para el lote {$lote->codigo_lote} supera el stock disponible."],
                                ]);
                            }

                            $lotesMovimiento[] = [
                                'id_lote' => (int) $lote->id,
                                'cantidad' => $cantidad,
                            ];

                            $cantidadDetalle += $cantidad;
                        }

                        if ($cantidadDetalle <= 0) {
                            throw ValidationException::withMessages([
                                'items' => ["Debes ingresar cantidades válidas por lote para {$insumo->ins_descripcion}."],
                            ]);
                        }

                        if (abs($cantidadDetalle - $cantidadSolicitada) > 0.0001) {
                            throw ValidationException::withMessages([
                                'items' => ["La suma de lotes para {$insumo->ins_descripcion} debe coincidir con la cantidad solicitada."],
                            ]);
                        }

                        $itemMovimiento['lotes'] = $lotesMovimiento;
                    } else {
                        $stockAnterior = (float) ($stockBodega->sb_cantidad ?? 0);

                        if ($cantidadSolicitada > $stockAnterior) {
                            throw ValidationException::withMessages([
                                'items' => ["La cantidad para {$insumo->ins_descripcion} supera el stock disponible en bodega."],
                            ]);
                        }
                    }

                    if ($cantidadDetalle > (float) ($stockBodega->sb_cantidad ?? 0)) {
                        throw ValidationException::withMessages([
                            'items' => ["La cantidad para {$insumo->ins_descripcion} supera el stock disponible en bodega."],
                        ]);
                    }

                    $itemMovimiento['cantidad'] = $cantidadDetalle;
                    $listaInsumos[] = $itemMovimiento;
                    $metadatos[$index] = [
                        'insumo_id' => (int) $insumo->id,
                        'codigo' => $insumo->codigo,
                        'descripcion' => $insumo->ins_descripcion,
                        'observacion' => $itemData['observacion'] ?? null,
                    ];
                }

                $resultadoMovimiento = $this->inventariosController->guardarMovimientoInventario(
                    $listaInsumos,
                    (int) $validated['bodega_id'],
                    'EGRESO',
                    54,
                    (int) $userId,
                    $encabezadoId,
                    'Baja de inventario por motivo: ' . trim($validated['motivo'])
                );

                $detalleProcesado = $resultadoMovimiento['detalle_procesado'] ?? [];

                if (count($detalleProcesado) !== count($listaInsumos)) {
                    throw new \RuntimeException('No se pudo consolidar correctamente el detalle procesado de la baja.');
                }

                $totalItems = 0;
                $totalCantidad = 0;
                $resumen = [];

                foreach ($detalleProcesado as $index => $detalleMovimiento) {
                    $meta = $metadatos[$index] ?? null;

                    if (!$meta) {
                        throw new \RuntimeException('No se encontró metadata para uno de los insumos procesados en la baja.');
                    }

                    $cantidadDetalle = (float) ($detalleMovimiento['cantidad'] ?? 0);

                    $detalleId = DB::table('inventarios.bajas_detalles')->insertGetId([
                        'encabezado_baja_id' => $encabezadoId,
                        'insumo_id' => $meta['insumo_id'],
                        'cantidad' => $cantidadDetalle,
                        'observacion' => $meta['observacion'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $resumenLotes = [];

                    foreach (($detalleMovimiento['desglose_lotes'] ?? []) as $lote) {
                        DB::table('inventarios.bajas_detalles_lotes')->insert([
                            'detalle_baja_id' => $detalleId,
                            'lote_id' => (int) ($lote['id_lote'] ?? 0),
                            'codigo_lote' => $lote['codigo_lote'] ?? null,
                            'fecha_elaboracion' => $lote['fecha_elaboracion'] ?? null,
                            'fecha_vencimiento' => $lote['fecha_vencimiento'] ?? null,
                            'cantidad' => (float) ($lote['cantidad'] ?? 0),
                            'stock_anterior' => isset($lote['stock_anterior']) ? (float) $lote['stock_anterior'] : null,
                            'stock_posterior' => isset($lote['stock_posterior']) ? (float) $lote['stock_posterior'] : null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        $resumenLotes[] = [
                            'lote_id' => (int) ($lote['id_lote'] ?? 0),
                            'codigo_lote' => $lote['codigo_lote'] ?? null,
                            'cantidad' => (float) ($lote['cantidad'] ?? 0),
                            'stock_anterior' => isset($lote['stock_anterior']) ? (float) $lote['stock_anterior'] : null,
                            'stock_posterior' => isset($lote['stock_posterior']) ? (float) $lote['stock_posterior'] : null,
                        ];
                    }

                    $resumen[] = [
                        'insumo_id' => $meta['insumo_id'],
                        'codigo' => $meta['codigo'],
                        'descripcion' => $meta['descripcion'],
                        'cantidad' => $cantidadDetalle,
                        'observacion' => $meta['observacion'],
                        'lotes' => $resumenLotes,
                    ];

                    $totalItems++;
                    $totalCantidad += $cantidadDetalle;
                }

                DB::table('inventarios.bajas')
                    ->where('id', $encabezadoId)
                    ->update([
                        'total_items' => $totalItems,
                        'total_cantidad' => $totalCantidad,
                        'detalle' => json_encode($resumen, JSON_UNESCAPED_UNICODE),
                        'updated_at' => now(),
                    ]);

                return [
                    'id' => $encabezadoId,
                    'numero_baja' => $numeroBaja,
                    'total_items' => $totalItems,
                    'total_cantidad' => $totalCantidad,
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Baja de inventario registrada correctamente.',
                'data' => $result,
            ], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo registrar la baja de inventario.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function anularBajaInventario(Request $request, $id)
    {
        try {
            $result = DB::transaction(function () use ($request, $id) {
                $estadoBajaAnuladaId = $this->getEstadoIdByNombre('Baja anulada') ?: 62;
                $userId = optional($request->user())->id;

                if (!$userId) {
                    throw ValidationException::withMessages([
                        'user' => ['No se pudo identificar el usuario autenticado.'],
                    ]);
                }

                $encabezado = DB::table('inventarios.bajas')
                    ->where('id', $id)
                    ->lockForUpdate()
                    ->first();

                if (!$encabezado) {
                    throw ValidationException::withMessages([
                        'baja' => ['La baja no existe.'],
                    ]);
                }

                if ((int) $encabezado->estado_id === (int) $estadoBajaAnuladaId) {
                    throw ValidationException::withMessages([
                        'baja' => ['La baja ya se encuentra anulada.'],
                    ]);
                }

                $detalles = DB::table('inventarios.bajas_detalles')
                    ->where('encabezado_baja_id', $id)
                    ->lockForUpdate()
                    ->get();

                if ($detalles->isEmpty()) {
                    throw ValidationException::withMessages([
                        'baja' => ['La baja no tiene detalles para anular.'],
                    ]);
                }

                $listaInsumos = [];

                foreach ($detalles as $detalle) {
                    $lotes = DB::table('inventarios.bajas_detalles_lotes')
                        ->where('detalle_baja_id', $detalle->id)
                        ->lockForUpdate()
                        ->get();

                    $listaInsumos[] = [
                        'idInsumo' => (int) $detalle->insumo_id,
                        'cantidad' => (float) $detalle->cantidad,
                        'lotes' => $lotes->map(function ($lote) {
                            return [
                                'id_lote' => (int) $lote->lote_id,
                                'cantidad' => (float) $lote->cantidad,
                                'codigo_lote' => $lote->codigo_lote,
                                'fecha_elaboracion' => $lote->fecha_elaboracion,
                                'fecha_vencimiento' => $lote->fecha_vencimiento,
                            ];
                        })->values()->all(),
                    ];
                }

                $this->inventariosController->guardarMovimientoInventario(
                    $listaInsumos,
                    (int) $encabezado->bodega_id,
                    'INGRESO',
                    62,
                    (int) $userId,
                    (int) $encabezado->id,
                    "Anulación de baja de inventario {$encabezado->numero_baja}"
                );

                DB::table('inventarios.bajas')
                    ->where('id', $encabezado->id)
                    ->update([
                        'estado_id' => $estadoBajaAnuladaId,
                        'updated_at' => now(),
                    ]);

                return [
                    'id' => $encabezado->id,
                    'numero_baja' => $encabezado->numero_baja,
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'La baja fue anulada correctamente.',
                'data' => $result,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo anular la baja de inventario.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    private function generarNumeroBaja(): string
    {
        $anio = now()->format('Y');
        $prefijo = "BAJ-{$anio}-";

        $ultimoNumero = DB::table('inventarios.bajas')
            ->where('numero_baja', 'like', "{$prefijo}%")
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('numero_baja');

        $secuencia = 1;

        if ($ultimoNumero && preg_match("/^BAJ\-{$anio}\-(\d+)$/", $ultimoNumero, $matches)) {
            $secuencia = ((int) $matches[1]) + 1;
        }

        return sprintf('BAJ-%s-%06d', $anio, $secuencia);
    }

    private function getEstadoIdByNombre(string $nombre): ?int
    {
        $id = DB::table('cpu_estados')
            ->whereRaw('UPPER(estado) = ?', [mb_strtoupper($nombre)])
            ->value('id');

        return $id ? (int) $id : null;
    }

    private function determinarEstadoLote(?string $fechaVencimiento, float $cantidadActual): int
    {
        if ($cantidadActual <= 0) {
            return 54;
        }

        if (!empty($fechaVencimiento) && $fechaVencimiento <= now()->toDateString()) {
            return 55;
        }

        return 8;
    }

    private function sincronizarEstadosPostBaja(int $insumoId): void
    {
        $lotes = DB::table('inventarios.productos_lotes')
            ->where('id_insumo', $insumoId)
            ->get();

        foreach ($lotes as $lote) {
            $estadoLote = $this->determinarEstadoLote(
                $lote->fecha_vencimiento,
                (float) ($lote->cantidad_actual ?? 0)
            );

            DB::table('inventarios.productos_lotes')
                ->where('id', $lote->id)
                ->update([
                    'id_estado' => $estadoLote,
                    'updated_at' => now(),
                ]);
        }

        $stockTotal = (float) DB::table('inventarios.stock_bodegas')
            ->where('sb_id_insumo', $insumoId)
            ->sum('sb_cantidad');

        $estadoInsumo = $stockTotal <= 0 ? 54 : 8;

        DB::table('inventarios.productos')
            ->where('id', $insumoId)
            ->update([
                'ins_cantidad' => $stockTotal,
                'id_estado' => $estadoInsumo,
                'updated_at' => now(),
            ]);
    }
}
