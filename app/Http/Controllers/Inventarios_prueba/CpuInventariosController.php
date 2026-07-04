<?php

namespace App\Http\Controllers\Inventarios;

use App\Http\Controllers\AuditoriaControllers;
use App\Http\Controllers\Controller;
use App\Http\Controllers\LogController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Session;

class CpuInventariosController extends Controller
{
    protected $auditoriaController;
    protected $logController;

    public function __construct()
    {
        $this->middleware('auth:api');
        $this->auditoriaController = new AuditoriaControllers();
        $this->logController = new LogController();
    }

    private function resolverEstadoLote(?string $fechaVencimiento, float $cantidadActual): int
    {
        if ($cantidadActual <= 0) {
            return 54; // Dado de baja
        }

        if (!empty($fechaVencimiento) && $fechaVencimiento <= now()->toDateString()) {
            return 55; // Vencido
        }

        return 8; // Activo
    }

    private function sincronizarStockGlobalInsumo(int $idInsumo, $now): void
    {
        $lotes = DB::table('inventarios.productos_lotes')
            ->where('id_insumo', $idInsumo)
            ->get(['id', 'fecha_vencimiento', 'cantidad_actual']);

        foreach ($lotes as $lote) {
            DB::table('inventarios.productos_lotes')
                ->where('id', $lote->id)
                ->update([
                    'id_estado'  => $this->resolverEstadoLote(
                        $lote->fecha_vencimiento,
                        (float) ($lote->cantidad_actual ?? 0)
                    ),
                    'updated_at' => $now,
                ]);
        }

        $stockTotal = (float) DB::table('inventarios.stock_bodegas')
            ->where('sb_id_insumo', $idInsumo)
            ->sum('sb_cantidad');

        DB::table('inventarios.productos')
            ->where('id', $idInsumo)
            ->update([
                'ins_cantidad' => $stockTotal,
                'id_estado'    => $stockTotal <= 0 ? 54 : 8,
                'updated_at'   => $now,
            ]);
    }

    private function normalizarLotesMovimiento(array $item): array
    {
        $fuente = [];

        if (!empty($item['desglose_lotes']) && is_array($item['desglose_lotes'])) {
            $fuente = $item['desglose_lotes'];
        } elseif (!empty($item['lotes']) && is_array($item['lotes'])) {
            $fuente = $item['lotes'];
        } elseif (!empty($item['idLote']) || !empty($item['id_lote']) || !empty($item['codigo_lote'])) {
            $fuente = [[
                'id_lote'           => $item['id_lote'] ?? $item['idLote'] ?? null,
                'codigo_lote'       => $item['codigo_lote'] ?? null,
                'fecha_elaboracion' => $item['fecha_elaboracion'] ?? null,
                'fecha_vencimiento' => $item['fecha_vencimiento'] ?? null,
                'cantidad'          => $item['cantidad'] ?? $item['cantidad_inicial'] ?? 0,
            ]];
        }

        $resultado = [];

        foreach ($fuente as $lot) {
            $cantidad = (float) ($lot['cantidad'] ?? $lot['cantidad_inicial'] ?? 0);

            if ($cantidad <= 0) {
                continue;
            }

            $resultado[] = [
                'id_lote'           => (int) ($lot['id_lote'] ?? $lot['idLote'] ?? 0),
                'codigo_lote'       => trim((string) ($lot['codigo_lote'] ?? $lot['codigo'] ?? $lot['lote'] ?? '')),
                'fecha_elaboracion' => !empty($lot['fecha_elaboracion']) ? $lot['fecha_elaboracion'] : null,
                'fecha_vencimiento' => !empty($lot['fecha_vencimiento']) ? $lot['fecha_vencimiento'] : null,
                'cantidad'          => $cantidad,
            ];
        }

        return $resultado;
    }

    private function guardarOActualizarLoteIngreso(
        int $idInsumo,
        int $idBodega,
        object $insumo,
        array $segmento,
        bool $isInventarioInicial,
        $now
    ) {
        $idLote = (int) ($segmento['id_lote'] ?? 0);
        $cantidadLote = (float) ($segmento['cantidad'] ?? 0);
        $codigoLote = trim((string) ($segmento['codigo_lote'] ?? ''));
        $fechaElaboracion = !empty($segmento['fecha_elaboracion']) ? $segmento['fecha_elaboracion'] : null;
        $fechaVencimiento = !empty($segmento['fecha_vencimiento']) ? $segmento['fecha_vencimiento'] : null;

        if ($cantidadLote <= 0) {
            throw new \Exception("Cantidad de lote inválida para el insumo {$insumo->ins_descripcion}.");
        }

        if ((bool) $insumo->requiere_lote && $idLote <= 0 && $codigoLote === '') {
            throw new \Exception("El insumo {$insumo->ins_descripcion} requiere código de lote.");
        }

        if ((bool) $insumo->requiere_vencimiento && empty($fechaVencimiento)) {
            throw new \Exception("El insumo {$insumo->ins_descripcion} requiere fecha de vencimiento.");
        }

        if (!empty($fechaElaboracion) && !empty($fechaVencimiento) && strtotime($fechaElaboracion) > strtotime($fechaVencimiento)) {
            throw new \Exception("La fecha de elaboración no puede ser mayor que la fecha de vencimiento para el insumo {$insumo->ins_descripcion}.");
        }

        if ($idLote > 0) {
            $lote = DB::table('inventarios.productos_lotes')
                ->where('id', $idLote)
                ->where('id_insumo', $idInsumo)
                ->where('id_bodega', $idBodega)
                ->lockForUpdate()
                ->first();
        } else {
            $lote = DB::table('inventarios.productos_lotes')
                ->where('id_insumo', $idInsumo)
                ->where('id_bodega', $idBodega)
                ->where('codigo_lote', $codigoLote)
                ->lockForUpdate()
                ->first();
        }

        if ($lote) {
            $cantidadInicialLote = (float) ($lote->cantidad_inicial ?? 0);
            $cantidadActualLote = (float) ($lote->cantidad_actual ?? 0);
            $fechaElaboracionFinal = $fechaElaboracion ?: $lote->fecha_elaboracion;
            $fechaVencimientoFinal = $fechaVencimiento ?: $lote->fecha_vencimiento;
            $nuevaCantidadActual = $cantidadActualLote + $cantidadLote;

            $updateLote = [
                'cantidad_actual'   => $nuevaCantidadActual,
                'updated_at'        => $now,
                'id_estado'         => $this->resolverEstadoLote($fechaVencimientoFinal, $nuevaCantidadActual),
                'fecha_elaboracion' => $fechaElaboracionFinal,
                'fecha_vencimiento' => $fechaVencimientoFinal,
            ];

            if ($isInventarioInicial) {
                $updateLote['cantidad_inicial'] = $cantidadInicialLote + $cantidadLote;
            }

            DB::table('inventarios.productos_lotes')
                ->where('id', $lote->id)
                ->update($updateLote);

            $lote = DB::table('inventarios.productos_lotes')
                ->where('id', $lote->id)
                ->lockForUpdate()
                ->first();
        } else {
            $nuevoIdLote = DB::table('inventarios.productos_lotes')->insertGetId([
                'id_insumo'         => $idInsumo,
                'id_bodega'         => $idBodega,
                'codigo_lote'       => $codigoLote,
                'fecha_elaboracion' => $fechaElaboracion,
                'fecha_vencimiento' => $fechaVencimiento,
                'cantidad_inicial'  => $cantidadLote,
                'cantidad_actual'   => $cantidadLote,
                'id_estado'         => $this->resolverEstadoLote($fechaVencimiento, $cantidadLote),
                'created_at'        => $now,
                'updated_at'        => $now,
            ], 'id');

            $lote = DB::table('inventarios.productos_lotes')
                ->where('id', $nuevoIdLote)
                ->lockForUpdate()
                ->first();
        }

        return $lote;
    }

    private function construirDesgloseAutomaticoEgreso(
        int $idInsumo,
        int $idBodega,
        object $insumo,
        float $cantidadSolicitada
    ): array {
        if ($cantidadSolicitada <= 0) {
            throw new \Exception("La cantidad solicitada para el insumo {$insumo->ins_descripcion} no es válida.");
        }

        $lotesDisponibles = DB::table('inventarios.productos_lotes')
            ->where('id_insumo', $idInsumo)
            ->where('id_bodega', $idBodega)
            ->where('cantidad_actual', '>', 0)
            ->orderByRaw('CASE WHEN fecha_vencimiento IS NULL THEN 1 ELSE 0 END')
            ->orderBy('fecha_vencimiento', 'asc')
            ->orderByRaw('CASE WHEN fecha_elaboracion IS NULL THEN 1 ELSE 0 END')
            ->orderBy('fecha_elaboracion', 'asc')
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();

        if ($lotesDisponibles->isEmpty()) {
            throw new \Exception("No hay unidades disponibles para el insumo {$insumo->ins_descripcion}.");
        }

        $stockDisponibleLotes = (float) $lotesDisponibles->sum('cantidad_actual');

        if ($stockDisponibleLotes < $cantidadSolicitada) {
            throw new \Exception(
                "No existe stock suficiente para el insumo {$insumo->ins_descripcion}. Disponible: {$stockDisponibleLotes}."
            );
        }

        $cantidadRestante = $cantidadSolicitada;
        $desglose = [];

        foreach ($lotesDisponibles as $lote) {
            if ($cantidadRestante <= 0) {
                break;
            }

            $stockLote = (float) $lote->cantidad_actual;

            if ($stockLote <= 0) {
                continue;
            }

            $cantidadTomar = min($cantidadRestante, $stockLote);

            $desglose[] = [
                'id_lote'           => (int) $lote->id,
                'codigo_lote'       => (string) ($lote->codigo_lote ?? ''),
                'fecha_elaboracion' => $lote->fecha_elaboracion ?? null,
                'fecha_vencimiento' => $lote->fecha_vencimiento ?? null,
                'cantidad'          => (float) $cantidadTomar,
            ];

            $cantidadRestante -= $cantidadTomar;
        }

        if ($cantidadRestante > 0) {
            throw new \Exception("No se pudo completar la salida del insumo {$insumo->ins_descripcion} con las unidades disponibles.");
        }

        return $desglose;
    }

    private function descontarLoteEgreso(
        int $idInsumo,
        int $idBodega,
        object $insumo,
        array $segmento,
        $now
    ) {
        $idLote = (int) ($segmento['id_lote'] ?? 0);
        $cantidadLote = (float) ($segmento['cantidad'] ?? 0);
        $codigoLote = trim((string) ($segmento['codigo_lote'] ?? ''));

        if ($cantidadLote <= 0) {
            throw new \Exception("La cantidad enviada para el insumo {$insumo->ins_descripcion} no es válida.");
        }

        if ($idLote > 0) {
            $lote = DB::table('inventarios.productos_lotes')
                ->where('id', $idLote)
                ->where('id_insumo', $idInsumo)
                ->where('id_bodega', $idBodega)
                ->lockForUpdate()
                ->first();
        } else {
            $lote = DB::table('inventarios.productos_lotes')
                ->where('id_insumo', $idInsumo)
                ->where('id_bodega', $idBodega)
                ->where('codigo_lote', $codigoLote)
                ->lockForUpdate()
                ->first();
        }

        if (!$lote) {
            throw new \Exception("No se encontró un lote válido para el insumo {$insumo->ins_descripcion}.");
        }

        $stockActualLote = (float) ($lote->cantidad_actual ?? 0);

        if ($stockActualLote < $cantidadLote) {
            throw new \Exception(
                "No existe cantidad suficiente para el insumo {$insumo->ins_descripcion}. Disponible en el lote: {$stockActualLote}."
            );
        }

        $nuevoStockLote = $stockActualLote - $cantidadLote;

        DB::table('inventarios.productos_lotes')
            ->where('id', $lote->id)
            ->update([
                'cantidad_actual' => $nuevoStockLote,
                'id_estado'       => $this->resolverEstadoLote($lote->fecha_vencimiento, $nuevoStockLote),
                'updated_at'      => $now,
            ]);

        return DB::table('inventarios.productos_lotes')
            ->where('id', $lote->id)
            ->lockForUpdate()
            ->first();
    }

    public function guardarMovimientoInventario(
        $detalleProductos,
        $idBodega,
        $tipo,
        $estado_movimiento,
        $userId,
        $idEncabezado = null,
        $observacion = null
    ) {
        $usaTransaccionPropia = DB::transactionLevel() === 0;

        if ($usaTransaccionPropia) {
            DB::beginTransaction();
        }

        $descripcionAuditoria = [];
        $detalleProcesado = [];
        $now = now();
        $tipo = strtoupper(trim((string) $tipo));
        $estadoMovimiento = (int) $estado_movimiento;
        $isInventarioInicial = ($estadoMovimiento === 24);

        try {
            if (empty($idBodega) || (int) $idBodega <= 0) {
                throw new \Exception("Bodega inválida.");
            }

            if (empty($userId) || (int) $userId <= 0) {
                throw new \Exception("Usuario inválido.");
            }

            if (!in_array($tipo, ['INGRESO', 'EGRESO'], true)) {
                throw new \Exception("Tipo de movimiento inválido: {$tipo}");
            }

            $bodega = DB::table('inventarios.bodegas')
                ->where('bod_id', $idBodega)
                ->first();

            if (!$bodega) {
                throw new \Exception("No existe la bodega {$idBodega}.");
            }

            $nombreBodega = $bodega->bod_nombre ?? ('ID ' . $idBodega);

            foreach ($detalleProductos as $item) {
                $idInsumo = isset($item['idInsumo'])
                    ? (int) $item['idInsumo']
                    : (isset($item['id']) ? (int) $item['id'] : 0);

                if ($idInsumo <= 0) {
                    throw new \Exception("Insumo inválido.");
                }

                $insumo = DB::table('inventarios.productos')
                    ->where('id', $idInsumo)
                    ->first();

                if (!$insumo) {
                    throw new \Exception("No existe el insumo {$idInsumo}.");
                }

                $usaLotes = (bool) ($insumo->requiere_lote || $insumo->requiere_vencimiento);

                $stockBodega = DB::table('inventarios.stock_bodegas')
                    ->where('sb_id_bodega', $idBodega)
                    ->where('sb_id_insumo', $idInsumo)
                    ->lockForUpdate()
                    ->first();

                $stockAnteriorTotal = $stockBodega ? (float) $stockBodega->sb_cantidad : 0;
                $cantidad = isset($item['cantidad']) ? (float) $item['cantidad'] : 0;

                if ($tipo === 'INGRESO') {
                    $desgloseIngreso = $this->normalizarLotesMovimiento($item);

                    if ($usaLotes) {
                        if (empty($desgloseIngreso)) {
                            throw new \Exception("El insumo {$insumo->ins_descripcion} requiere lote y/o fecha de vencimiento.");
                        }

                        $stockCorriente = $stockAnteriorTotal;
                        $cantidadTotalIngreso = 0;
                        $desgloseLotesProcesado = [];

                        foreach ($desgloseIngreso as $segmento) {
                            $lote = $this->guardarOActualizarLoteIngreso(
                                $idInsumo,
                                $idBodega,
                                $insumo,
                                $segmento,
                                $isInventarioInicial,
                                $now
                            );

                            $cantidadLote = (float) $segmento['cantidad'];
                            $stockAntesMovimiento = $stockCorriente;
                            $stockDespuesMovimiento = $stockCorriente + $cantidadLote;

                            DB::table('inventarios.movimientos')->insert([
                                'mi_id_insumo'        => $idInsumo,
                                'mi_cantidad'         => $cantidadLote,
                                'mi_stock_anterior'   => $stockAntesMovimiento,
                                'mi_stock_actual'     => $stockDespuesMovimiento,
                                'mi_tipo_transaccion' => 1,
                                'mi_user_id'          => $userId,
                                'mi_id_encabezado'    => $idEncabezado,
                                'mi_id_bodega'        => $idBodega,
                                'mi_id_estado'        => $estadoMovimiento,
                                'mi_observacion'      => $observacion,
                                'mi_fecha'            => $now,
                                'mi_created_at'       => $now,
                                'mi_updated_at'       => $now,
                                'mi_id_lote'          => $lote->id,
                            ]);

                            $stockPosteriorLote = (float) ($lote->cantidad_actual ?? 0);
                            $stockAnteriorLote = $stockPosteriorLote + $cantidadLote;

                            $desgloseLotesProcesado[] = [
                                'id_lote'           => (int) $lote->id,
                                'codigo_lote'       => $lote->codigo_lote,
                                'fecha_elaboracion' => $lote->fecha_elaboracion,
                                'fecha_vencimiento' => $lote->fecha_vencimiento,
                                'cantidad'          => $cantidadLote,
                                'stock_anterior'    => $stockAnteriorLote,
                                'stock_posterior'   => $stockPosteriorLote,
                            ];

                            $descripcionAuditoria[] =
                                "Ingreso por lote de {$cantidadLote} del insumo {$idInsumo} ({$insumo->ins_descripcion}) al lote {$lote->codigo_lote} en bodega {$nombreBodega}.";

                            $stockCorriente = $stockDespuesMovimiento;
                            $cantidadTotalIngreso += $cantidadLote;
                        }

                        if ($stockBodega) {
                            $updateData = [
                                'sb_cantidad'   => $stockCorriente,
                                'sb_updated_at' => $now,
                                'sb_id_user'    => $userId,
                            ];

                            if ($isInventarioInicial) {
                                $updateData['sb_cantidad_inicial'] = $cantidadTotalIngreso;
                            }

                            DB::table('inventarios.stock_bodegas')
                                ->where('sb_id', $stockBodega->sb_id)
                                ->update($updateData);
                        } else {
                            DB::table('inventarios.stock_bodegas')->insert([
                                'sb_id_bodega'        => $idBodega,
                                'sb_id_insumo'        => $idInsumo,
                                'sb_cantidad'         => $stockCorriente,
                                'sb_stock_minimo'     => 0,
                                'sb_created_at'       => $now,
                                'sb_updated_at'       => $now,
                                'sb_id_user'          => $userId,
                                'sb_cantidad_inicial' => $isInventarioInicial ? $cantidadTotalIngreso : 0,
                            ]);
                        }

                        $detalleProcesado[] = [
                            'idInsumo'       => $idInsumo,
                            'id'             => $idInsumo,
                            'nombre'         => $insumo->ins_descripcion,
                            'cantidad'       => $cantidadTotalIngreso,
                            'idBodega'       => $idBodega,
                            'bod_id'         => $idBodega,
                            'bodega_nombre'  => $nombreBodega,
                            'desglose_lotes' => $desgloseLotesProcesado,
                        ];

                        $this->sincronizarStockGlobalInsumo($idInsumo, $now);

                        continue;
                    }

                    if ($cantidad <= 0) {
                        throw new \Exception("Cantidad inválida para el insumo {$idInsumo}.");
                    }

                    $stockActual = $isInventarioInicial
                        ? $cantidad
                        : ($stockAnteriorTotal + $cantidad);

                    if ($stockBodega) {
                        $updateData = [
                            'sb_cantidad'   => $stockActual,
                            'sb_updated_at' => $now,
                            'sb_id_user'    => $userId,
                        ];

                        if ($isInventarioInicial) {
                            $updateData['sb_cantidad_inicial'] = $cantidad;
                        }

                        DB::table('inventarios.stock_bodegas')
                            ->where('sb_id', $stockBodega->sb_id)
                            ->update($updateData);
                    } else {
                        DB::table('inventarios.stock_bodegas')->insert([
                            'sb_id_bodega'        => $idBodega,
                            'sb_id_insumo'        => $idInsumo,
                            'sb_cantidad'         => $stockActual,
                            'sb_stock_minimo'     => 0,
                            'sb_created_at'       => $now,
                            'sb_updated_at'       => $now,
                            'sb_id_user'          => $userId,
                            'sb_cantidad_inicial' => $isInventarioInicial ? $cantidad : 0,
                        ]);
                    }

                    DB::table('inventarios.movimientos')->insert([
                        'mi_id_insumo'        => $idInsumo,
                        'mi_cantidad'         => $cantidad,
                        'mi_stock_anterior'   => $stockAnteriorTotal,
                        'mi_stock_actual'     => $stockActual,
                        'mi_tipo_transaccion' => 1,
                        'mi_user_id'          => $userId,
                        'mi_id_encabezado'    => $idEncabezado,
                        'mi_id_bodega'        => $idBodega,
                        'mi_id_estado'        => $estadoMovimiento,
                        'mi_observacion'      => $observacion,
                        'mi_fecha'            => $now,
                        'mi_created_at'       => $now,
                        'mi_updated_at'       => $now,
                        'mi_id_lote'          => null,
                    ]);

                    $detalleProcesado[] = [
                        'idInsumo'       => $idInsumo,
                        'id'             => $idInsumo,
                        'nombre'         => $insumo->ins_descripcion,
                        'cantidad'       => $cantidad,
                        'idBodega'       => $idBodega,
                        'bod_id'         => $idBodega,
                        'bodega_nombre'  => $nombreBodega,
                        'desglose_lotes' => [],
                    ];

                    $descripcionAuditoria[] =
                        "Ingreso de {$cantidad} del insumo {$idInsumo} ({$insumo->ins_descripcion}) en bodega {$nombreBodega}.";

                    $this->sincronizarStockGlobalInsumo($idInsumo, $now);

                    continue;
                }

                if (!$stockBodega) {
                    throw new \Exception("No existe stock del insumo {$insumo->ins_descripcion} en la bodega {$nombreBodega}.");
                }

                if ($usaLotes) {
                    $desgloseSalida = $this->normalizarLotesMovimiento($item);

                    if (empty($desgloseSalida)) {
                        if ($cantidad <= 0) {
                            throw new \Exception("La cantidad solicitada para el insumo {$insumo->ins_descripcion} no es válida.");
                        }

                        $desgloseSalida = $this->construirDesgloseAutomaticoEgreso(
                            $idInsumo,
                            $idBodega,
                            $insumo,
                            $cantidad
                        );
                    }

                    $cantidadTotalSalida = (float) collect($desgloseSalida)->sum('cantidad');

                    if ($cantidadTotalSalida <= 0) {
                        throw new \Exception("La cantidad total enviada para el insumo {$insumo->ins_descripcion} no es válida.");
                    }

                    if ($cantidadTotalSalida > $stockAnteriorTotal) {
                        throw new \Exception(
                            "No existe stock suficiente para el insumo {$insumo->ins_descripcion}. Disponible: {$stockAnteriorTotal}."
                        );
                    }

                    $stockCorriente = $stockAnteriorTotal;
                    $desgloseLotesProcesado = [];

                    foreach ($desgloseSalida as $segmento) {
                        $lote = $this->descontarLoteEgreso(
                            $idInsumo,
                            $idBodega,
                            $insumo,
                            $segmento,
                            $now
                        );

                        $cantidadLote = (float) $segmento['cantidad'];
                        $stockAntesMovimiento = $stockCorriente;
                        $stockDespuesMovimiento = $stockCorriente - $cantidadLote;

                        DB::table('inventarios.movimientos')->insert([
                            'mi_id_insumo'        => $idInsumo,
                            'mi_cantidad'         => $cantidadLote,
                            'mi_stock_anterior'   => $stockAntesMovimiento,
                            'mi_stock_actual'     => $stockDespuesMovimiento,
                            'mi_tipo_transaccion' => 2,
                            'mi_user_id'          => $userId,
                            'mi_id_encabezado'    => $idEncabezado,
                            'mi_id_bodega'        => $idBodega,
                            'mi_id_estado'        => $estadoMovimiento,
                            'mi_observacion'      => $observacion,
                            'mi_fecha'            => $now,
                            'mi_created_at'       => $now,
                            'mi_updated_at'       => $now,
                            'mi_id_lote'          => $lote->id,
                        ]);

                        $desgloseLotesProcesado[] = [
                            'id_lote'           => (int) $lote->id,
                            'codigo_lote'       => $lote->codigo_lote,
                            'fecha_elaboracion' => $lote->fecha_elaboracion,
                            'fecha_vencimiento' => $lote->fecha_vencimiento,
                            'cantidad'          => $cantidadLote,
                            'stock_anterior'    => (float) ($lote->cantidad_actual ?? 0) + $cantidadLote,
                            'stock_posterior'   => (float) ($lote->cantidad_actual ?? 0),
                        ];

                        $descripcionAuditoria[] =
                            "Egreso por lote de {$cantidadLote} del insumo {$idInsumo} ({$insumo->ins_descripcion}) del lote {$lote->codigo_lote} en bodega {$nombreBodega}.";

                        $stockCorriente = $stockDespuesMovimiento;
                    }

                    DB::table('inventarios.stock_bodegas')
                        ->where('sb_id', $stockBodega->sb_id)
                        ->update([
                            'sb_cantidad'   => $stockCorriente,
                            'sb_updated_at' => $now,
                            'sb_id_user'    => $userId,
                        ]);

                    $detalleProcesado[] = [
                        'idInsumo'       => $idInsumo,
                        'id'             => $idInsumo,
                        'nombre'         => $insumo->ins_descripcion,
                        'cantidad'       => $cantidadTotalSalida,
                        'idBodega'       => $idBodega,
                        'bod_id'         => $idBodega,
                        'bodega_nombre'  => $nombreBodega,
                        'desglose_lotes' => $desgloseLotesProcesado,
                    ];

                    $this->sincronizarStockGlobalInsumo($idInsumo, $now);

                    continue;
                }

                if ($cantidad <= 0) {
                    throw new \Exception("Cantidad inválida para el insumo {$idInsumo}.");
                }

                if ($stockAnteriorTotal < $cantidad) {
                    throw new \Exception(
                        "No existe stock suficiente para el insumo {$insumo->ins_descripcion}. Disponible: {$stockAnteriorTotal}."
                    );
                }

                $stockActual = $stockAnteriorTotal - $cantidad;

                DB::table('inventarios.stock_bodegas')
                    ->where('sb_id', $stockBodega->sb_id)
                    ->update([
                        'sb_cantidad'   => $stockActual,
                        'sb_updated_at' => $now,
                        'sb_id_user'    => $userId,
                    ]);

                DB::table('inventarios.movimientos')->insert([
                    'mi_id_insumo'        => $idInsumo,
                    'mi_cantidad'         => $cantidad,
                    'mi_stock_anterior'   => $stockAnteriorTotal,
                    'mi_stock_actual'     => $stockActual,
                    'mi_tipo_transaccion' => 2,
                    'mi_user_id'          => $userId,
                    'mi_id_encabezado'    => $idEncabezado,
                    'mi_id_bodega'        => $idBodega,
                    'mi_id_estado'        => $estadoMovimiento,
                    'mi_observacion'      => $observacion,
                    'mi_fecha'            => $now,
                    'mi_created_at'       => $now,
                    'mi_updated_at'       => $now,
                    'mi_id_lote'          => null,
                ]);

                $detalleProcesado[] = [
                    'idInsumo'       => $idInsumo,
                    'nombre'         => $insumo->ins_descripcion,
                    'cantidad'       => $cantidad,
                    'desglose_lotes' => [],
                ];

                $descripcionAuditoria[] =
                    "Egreso de {$cantidad} del insumo {$idInsumo} ({$insumo->ins_descripcion}) en {$nombreBodega}.";

                $this->sincronizarStockGlobalInsumo($idInsumo, $now);
            }

            if ($usaTransaccionPropia) {
                DB::commit();
            }

            $this->auditoriaController->auditar(
                'inventarios.movimientos',
                'guardarMovimientoInventario()',
                json_encode([]),
                json_encode($detalleProcesado, JSON_UNESCAPED_UNICODE),
                $tipo,
                implode(' | ', $descripcionAuditoria)
            );

            return [
                'ok' => true,
                'detalle_procesado' => $detalleProcesado,
            ];
        } catch (\Exception $e) {
            if ($usaTransaccionPropia) {
                DB::rollBack();
            }

            $this->logController->saveLog(
                'Controlador: CpuInventariosController, Funcion: guardarMovimientoInventario()',
                'Error al guardar movimiento: ' . $e->getMessage()
            );

            throw $e;
        }
    }

    public function guardarInventarioInicial(Request $request)
    {
        Log::info('Datos recibidos en guardarInventarioInicial:', $request->all());

        $validator = Validator::make($request->all(), [
            'select_bodega'      => 'required|integer|min:1',
            'select_sede'        => 'required|integer|min:1',
            'select_facultad'    => 'required|integer|min:1',
            'id_insumo'          => 'required|integer|min:1',
            'txt-stock-inicial'  => 'nullable|numeric|min:0.01',
            'txt-stock-minimo'   => 'nullable|numeric|min:0',
            'lotes'              => 'nullable|array',
            'lotes.*.codigo_lote'       => 'nullable|string|max:255',
            'lotes.*.fecha_elaboracion' => 'nullable|date',
            'lotes.*.fecha_vencimiento' => 'nullable|date',
            'lotes.*.cantidad_inicial'  => 'nullable|numeric|min:0.01',
        ]);

        if ($validator->fails()) {
            $this->logController->saveLog(
                'Controlador: CpuInventariosController, Función: guardarInventarioInicial()',
                'Error de validación: ' . json_encode($validator->errors())
            );

            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'data' => $validator->errors()
            ], 400);
        }

        try {
            $resultado = DB::transaction(function () use ($request) {
                $idBodega = (int) $request->input('select_bodega');
                $idSede = (int) $request->input('select_sede');
                $idFacultad = (int) $request->input('select_facultad');
                $idInsumo = (int) $request->input('id_insumo');
                $stockMinimo = (float) ($request->input('txt-stock-minimo') ?? 0);
                $userId = auth()->id() ?? Session::get('user_id');

                if (!$userId) {
                    throw new \Exception('No se pudo identificar el usuario autenticado.');
                }

                $bodega = DB::table('inventarios.bodegas')
                    ->where('bod_id', $idBodega)
                    ->where('bod_id_sede', $idSede)
                    ->where('bod_id_facultad', $idFacultad)
                    ->first();

                if (!$bodega) {
                    throw new \Exception('La bodega seleccionada no corresponde a la sede/facultad indicadas.');
                }

                $insumo = DB::table('inventarios.productos')
                    ->where('id', $idInsumo)
                    ->lockForUpdate()
                    ->first();

                if (!$insumo) {
                    throw new \Exception('No se encontró el insumo seleccionado.');
                }

                $requiereLote = (bool) $insumo->requiere_lote;
                $requiereVencimiento = (bool) $insumo->requiere_vencimiento;

                if ($requiereVencimiento) {
                    $requiereLote = true;
                }

                $lotes = $request->input('lotes', []);
                $stockInicial = 0;

                if ($requiereLote) {
                    if (!is_array($lotes) || count($lotes) === 0) {
                        throw new \Exception('Este insumo requiere lotes. Debe registrar al menos un lote.');
                    }

                    $codigosLoteFormulario = [];

                    foreach ($lotes as $index => $lote) {
                        $fila = $index + 1;
                        $codigoLote = trim((string) ($lote['codigo_lote'] ?? ''));
                        $fechaElaboracion = $lote['fecha_elaboracion'] ?? null;
                        $fechaVencimiento = $lote['fecha_vencimiento'] ?? null;
                        $cantidadLote = (float) ($lote['cantidad_inicial'] ?? 0);

                        if ($codigoLote === '') {
                            throw new \Exception("El código del lote #{$fila} es obligatorio.");
                        }

                        $codigoNormalizado = mb_strtolower($codigoLote);

                        if (in_array($codigoNormalizado, $codigosLoteFormulario, true)) {
                            throw new \Exception("El código de lote \"{$codigoLote}\" está repetido en el formulario.");
                        }

                        $codigosLoteFormulario[] = $codigoNormalizado;

                        if (empty($fechaElaboracion)) {
                            throw new \Exception("La fecha de elaboración del lote #{$fila} es obligatoria.");
                        }

                        if ($requiereVencimiento && empty($fechaVencimiento)) {
                            throw new \Exception("La fecha de vencimiento del lote #{$fila} es obligatoria.");
                        }

                        if (!empty($fechaElaboracion) && !empty($fechaVencimiento) && strtotime($fechaVencimiento) < strtotime($fechaElaboracion)) {
                            throw new \Exception("La fecha de vencimiento del lote #{$fila} no puede ser menor a la fecha de elaboración.");
                        }

                        if ($cantidadLote <= 0) {
                            throw new \Exception("La cantidad del lote #{$fila} debe ser mayor a 0.");
                        }

                        $stockInicial += $cantidadLote;
                    }

                    if ($stockInicial <= 0) {
                        throw new \Exception('La suma de cantidades de los lotes debe ser mayor a 0.');
                    }
                } else {
                    $stockInicial = (float) $request->input('txt-stock-inicial', 0);

                    if ($stockInicial <= 0) {
                        throw new \Exception('El stock inicial debe ser mayor a 0.');
                    }

                    $lotes = [];
                }

                $stockBodega = DB::table('inventarios.stock_bodegas')
                    ->where('sb_id_bodega', $idBodega)
                    ->where('sb_id_insumo', $idInsumo)
                    ->lockForUpdate()
                    ->first();

                $movimientos = DB::table('inventarios.movimientos')
                    ->where('mi_id_insumo', $idInsumo)
                    ->where('mi_id_bodega', $idBodega)
                    ->select('mi_id', 'mi_id_estado')
                    ->lockForUpdate()
                    ->get();

                $totalMovimientos = $movimientos->count();
                $totalMovimientosNoInicial = $movimientos->where('mi_id_estado', '!=', 24)->count();

                $puedeEditarInicial = ($totalMovimientosNoInicial === 0);

                if ($stockBodega && !$puedeEditarInicial) {
                    throw new \Exception(
                        'No se puede modificar el inventario inicial porque ya existen movimientos posteriores para este insumo en esta bodega.'
                    );
                }

                if (!$stockBodega && $totalMovimientos > 0 && !$puedeEditarInicial) {
                    throw new \Exception(
                        'Existe historial de movimientos para este insumo en esta bodega. No se puede registrar nuevamente el inventario inicial.'
                    );
                }

                $valoresAntes = $stockBodega ? (array) $stockBodega : [];
                $tipoOperacion = $stockBodega ? 'UPDATE' : 'INSERT';

                if ($stockBodega) {
                    DB::table('inventarios.movimientos')
                        ->where('mi_id_insumo', $idInsumo)
                        ->where('mi_id_bodega', $idBodega)
                        ->where('mi_id_estado', 24)
                        ->delete();

                    DB::table('inventarios.productos_lotes')
                        ->where('id_insumo', $idInsumo)
                        ->where('id_bodega', $idBodega)
                        ->delete();

                    DB::table('inventarios.stock_bodegas')
                        ->where('sb_id', $stockBodega->sb_id)
                        ->update([
                            'sb_cantidad'         => 0,
                            'sb_cantidad_inicial' => 0,
                            'sb_stock_minimo'     => $stockMinimo,
                            'sb_updated_at'       => now(),
                            'sb_id_user'          => $userId,
                        ]);
                }

                $detalleMovimiento = [
                    [
                        'idInsumo' => $idInsumo,
                        'cantidad' => $stockInicial,
                        'lotes'    => array_map(function ($lote) {
                            return [
                                'codigo_lote'       => trim($lote['codigo_lote'] ?? ''),
                                'fecha_elaboracion' => $lote['fecha_elaboracion'] ?? null,
                                'fecha_vencimiento' => $lote['fecha_vencimiento'] ?? null,
                                'cantidad'          => (float) ($lote['cantidad_inicial'] ?? 0),
                            ];
                        }, $lotes),
                    ]
                ];

                $this->guardarMovimientoInventario(
                    $requiereLote ? $detalleMovimiento : [[
                        'idInsumo' => $idInsumo,
                        'cantidad' => $stockInicial,
                    ]],
                    $idBodega,
                    'INGRESO',
                    24,
                    $userId,
                    null,
                    $requiereLote
                        ? 'Movimiento inicial de carga de inventario por lotes'
                        : 'Movimiento inicial de carga de inventario'
                );

                $stockBodegaActualizado = DB::table('inventarios.stock_bodegas')
                    ->where('sb_id_bodega', $idBodega)
                    ->where('sb_id_insumo', $idInsumo)
                    ->lockForUpdate()
                    ->first();

                if (!$stockBodegaActualizado) {
                    throw new \Exception('No se pudo obtener el stock de bodega actualizado.');
                }

                DB::table('inventarios.stock_bodegas')
                    ->where('sb_id', $stockBodegaActualizado->sb_id)
                    ->update([
                        'sb_stock_minimo'     => $stockMinimo,
                        'sb_cantidad_inicial' => $stockInicial,
                        'sb_updated_at'       => now(),
                        'sb_id_user'          => $userId,
                    ]);

                $descripcionAuditoria = "Inventario inicial registrado/actualizado | StockBodegaID: {$stockBodegaActualizado->sb_id} | "
                    . "Insumo: [ID: {$idInsumo}, Nombre: {$insumo->ins_descripcion}] | "
                    . "Cantidad inicial: {$stockInicial} | "
                    . "Bodega: [ID: {$idBodega}, Nombre: {$bodega->bod_nombre}] | "
                    . "Control por lote: " . ($requiereLote ? 'Sí' : 'No') . " | "
                    . "Control de vencimiento: " . ($requiereVencimiento ? 'Sí' : 'No');

                $this->auditoriaController->auditar(
                    'inventarios.stock_bodegas' . ($requiereLote ? ' & inventarios.productos_lotes' : ''),
                    'guardarInventarioInicial()',
                    json_encode($valoresAntes),
                    json_encode($request->all()),
                    $tipoOperacion,
                    $descripcionAuditoria
                );

                return [
                    'success' => true,
                    'message' => $tipoOperacion === 'INSERT'
                        ? 'Inventario inicial guardado exitosamente.'
                        : 'Inventario inicial actualizado exitosamente.',
                    'id' => $stockBodegaActualizado->sb_id,
                    'status_code' => $tipoOperacion === 'INSERT' ? 201 : 200,
                ];
            });

            return response()->json([
                'success' => $resultado['success'],
                'message' => $resultado['message'],
                'id' => $resultado['id'],
            ], $resultado['status_code']);
        } catch (\Exception $e) {
            Log::error('Error al guardar inventario inicial: ' . $e->getMessage());

            $this->logController->saveLog(
                'Controlador: CpuInventariosController, Función: guardarInventarioInicial()',
                'Error al guardar inventario inicial: ' . $e->getMessage()
            );

            return response()->json([
                'success' => false,
                'message' => 'Error al guardar inventario inicial: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getStockBodegaInsumo($id)
    {
        try {
            $data = DB::table('inventarios.stock_bodegas as sb')
                ->join('inventarios.bodegas as b', 'b.bod_id', '=', 'sb.sb_id_bodega')
                ->leftJoin('cpu_sede as s', 's.id', '=', 'b.bod_id_sede')
                ->leftJoin('cpu_facultad as f', 'f.id', '=', 'b.bod_id_facultad')
                ->join('inventarios.productos as i', 'i.id', '=', 'sb.sb_id_insumo')
                ->leftJoin('cpu_estados as e', 'e.id', '=', 'i.id_estado')
                ->leftJoin('inventarios.productos_lotes as il', function ($join) {
                    $join->on('il.id_insumo', '=', 'sb.sb_id_insumo')
                        ->on('il.id_bodega', '=', 'sb.sb_id_bodega');
                })
                ->where('i.id', $id)
                ->groupBy(
                    'sb.sb_id',
                    'sb.sb_cantidad',
                    'sb.sb_stock_minimo',
                    'sb.sb_cantidad_inicial',
                    'sb.sb_id_bodega',
                    'sb.sb_created_at',
                    'sb.sb_updated_at',
                    'b.bod_nombre',
                    'b.bod_id_sede',
                    'b.bod_id_facultad',
                    's.nombre_sede',
                    'f.fac_nombre',
                    'i.id',
                    'i.codigo',
                    'i.ins_descripcion',
                    'i.id_tipo_insumo',
                    'i.estado_insumo',
                    'i.id_estado',
                    'i.modo_adquirido',
                    'i.requiere_lote',
                    'i.requiere_vencimiento',
                    'e.estado'
                )
                ->orderBy('s.nombre_sede')
                ->orderBy('f.fac_nombre')
                ->orderBy('b.bod_nombre')
                ->select([
                    'sb.sb_id',
                    'sb.sb_cantidad as stock_bodega',
                    'sb.sb_stock_minimo',
                    'sb.sb_cantidad_inicial',
                    'sb.sb_id_bodega',
                    'sb.sb_created_at',
                    'sb.sb_updated_at',
                    'b.bod_nombre as nombre_bodega',
                    'b.bod_id_sede',
                    'b.bod_id_facultad',
                    's.nombre_sede',
                    'f.fac_nombre',
                    'i.id as id_insumo',
                    'i.codigo',
                    'i.ins_descripcion',
                    'i.id_tipo_insumo',
                    'i.estado_insumo',
                    'i.id_estado',
                    'i.modo_adquirido',
                    'i.requiere_lote',
                    'i.requiere_vencimiento',
                    'e.estado as nombre_estado',
                ])
                ->selectRaw('COUNT(il.id) as total_lotes')
                ->selectRaw('COALESCE(SUM(il.cantidad_actual), 0) as stock_lotes')
                ->selectRaw('MIN(il.fecha_vencimiento) as proximo_vencimiento')
                ->get();

            return response()->json($data);
        } catch (\Exception $e) {
            Log::error('Error al obtener stock por insumo: ' . $e->getMessage());

            $this->logController->saveLog(
                'Controlador: CpuInventariosController, Función: getStockBodegaInsumo($id)',
                'Error: ' . $e->getMessage()
            );

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el stock del insumo.',
            ], 500);
        }
    }

    public function getStockBodegaInsumoId($id)
    {
        try {
            $row = DB::table('inventarios.stock_bodegas as sb')
                ->join('inventarios.bodegas as b', 'b.bod_id', '=', 'sb.sb_id_bodega')
                ->leftJoin('cpu_sede as s', 's.id', '=', 'b.bod_id_sede')
                ->leftJoin('cpu_facultad as f', 'f.id', '=', 'b.bod_id_facultad')
                ->join('inventarios.productos as i', 'i.id', '=', 'sb.sb_id_insumo')
                ->leftJoin('cpu_estados as e', 'e.id', '=', 'i.id_estado')
                ->where('sb.sb_id', $id)
                ->select([
                    'sb.sb_id',
                    'sb.sb_cantidad as stock_bodega',
                    'sb.sb_stock_minimo',
                    'sb.sb_cantidad_inicial',
                    'sb.sb_id_bodega',
                    'sb.sb_created_at',
                    'sb.sb_updated_at',
                    'b.bod_nombre as nombre_bodega',
                    'b.bod_id_sede',
                    'b.bod_id_facultad',
                    's.nombre_sede',
                    'f.fac_nombre',
                    'i.id as id_insumo',
                    'i.codigo',
                    'i.ins_descripcion',
                    'i.id_tipo_insumo',
                    'i.estado_insumo',
                    'i.id_estado',
                    'i.modo_adquirido',
                    'i.requiere_lote',
                    'i.requiere_vencimiento',
                    'e.estado as nombre_estado',
                ])
                ->first();

            if (!$row) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró el registro de stock solicitado.'
                ], 404);
            }

            $lotes = DB::table('inventarios.productos_lotes as il')
                ->leftJoin('cpu_estados as el', 'el.id', '=', 'il.id_estado')
                ->where('il.id_insumo', $row->id_insumo)
                ->where('il.id_bodega', $row->sb_id_bodega)
                ->orderByRaw('il.fecha_vencimiento ASC NULLS LAST')
                ->orderBy('il.id')
                ->select([
                    'il.id',
                    'il.id_insumo',
                    'il.id_bodega',
                    'il.codigo_lote',
                    'il.fecha_elaboracion',
                    'il.fecha_vencimiento',
                    'il.cantidad_inicial',
                    'il.cantidad_actual',
                    'il.id_estado',
                    'il.created_at',
                    'il.updated_at',
                    'el.estado as nombre_estado_lote',
                ])
                ->get();

            $response = [
                'sb_id' => $row->sb_id,
                'stock_bodega' => $row->stock_bodega,
                'sb_stock_minimo' => $row->sb_stock_minimo,
                'sb_cantidad_inicial' => $row->sb_cantidad_inicial,
                'sb_id_bodega' => $row->sb_id_bodega,
                'sb_created_at' => $row->sb_created_at,
                'sb_updated_at' => $row->sb_updated_at,
                'nombre_bodega' => $row->nombre_bodega,
                'bod_id_sede' => $row->bod_id_sede,
                'bod_id_facultad' => $row->bod_id_facultad,
                'nombre_sede' => $row->nombre_sede,
                'fac_nombre' => $row->fac_nombre,
                'id' => $row->id_insumo,
                'id_insumo' => $row->id_insumo,
                'codigo' => $row->codigo,
                'ins_descripcion' => $row->ins_descripcion,
                'id_tipo_insumo' => $row->id_tipo_insumo,
                'estado_insumo' => $row->estado_insumo,
                'id_estado' => $row->id_estado,
                'nombre_estado' => $row->nombre_estado,
                'modo_adquirido' => $row->modo_adquirido,
                'requiere_lote' => (bool) $row->requiere_lote,
                'requiere_vencimiento' => (bool) $row->requiere_vencimiento,
                'total_lotes' => $lotes->count(),
                'stock_lotes' => $lotes->sum('cantidad_actual'),
                'lotes' => $lotes->values(),
            ];

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Error al obtener stock por sb_id: ' . $e->getMessage());

            $this->logController->saveLog(
                'Controlador: CpuInventariosController, Función: getStockBodegaInsumoId($id)',
                'Error: ' . $e->getMessage()
            );

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el detalle del stock.'
            ], 500);
        }
    }
}
