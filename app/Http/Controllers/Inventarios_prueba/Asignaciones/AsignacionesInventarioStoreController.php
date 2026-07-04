<?php

namespace App\Http\Controllers\Inventarios\Asignaciones;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Inventarios\CpuInventariosController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AsignacionesInventarioStoreController extends Controller
{
    private const ESTADO_ASIGNACION = 'REGISTRADO';
    private const ESTADO_MOVIMIENTO = 'Traslado interno entre bodegas';

    public function __construct()
    {
        $this->middleware('auth:api');
    }

    public function store(Request $request)
    {
        if (!Schema::hasTable('inventarios.asignaciones') || !Schema::hasTable('inventarios.asignaciones_detalles')) {
            throw ValidationException::withMessages([
                'asignaciones' => ['Las tablas de asignaciones aún no existen. Ejecuta las migraciones del módulo primero.'],
            ]);
        }

        $data = $request->validate([
            'persona_destino_id' => 'required|integer',
            'tipo_flujo' => 'required|string|max:60',
            'tipo_destino' => 'required|string|max:60',
            'sede_origen_id' => 'required|integer',
            'facultad_origen_id' => 'required|integer',
            'bodega_origen_id' => 'required|integer',
            'sede_destino_id' => 'required|integer',
            'facultad_destino_id' => 'required|integer',
            'bodega_destino_id' => 'required|integer',
            'observacion' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.producto_id' => 'required|integer',
            'items.*.producto' => 'required|string',
            'items.*.cantidad' => 'required|numeric|gt:0',
            'items.*.stock_total' => 'nullable|numeric|min:0',
            'items.*.stock_util' => 'nullable|numeric|min:0',
            'items.*.proximo_vencimiento_vigente' => 'nullable|date',
        ]);

        $persona = DB::table('cpu_personas')
            ->select('id', 'cedula', 'nombres')
            ->where('id', $data['persona_destino_id'])
            ->first();

        if (!$persona) {
            throw ValidationException::withMessages([
                'persona_destino_id' => ['No se encontró el funcionario o receptor seleccionado.'],
            ]);
        }

        if ((int) $data['bodega_origen_id'] === (int) $data['bodega_destino_id']) {
            throw ValidationException::withMessages([
                'bodega_destino_id' => ['La bodega destino debe ser distinta a la bodega de origen.'],
            ]);
        }

        $userId = optional($request->user())->id;

        if (empty($userId) || (int) $userId <= 0) {
            throw ValidationException::withMessages([
                'auth' => ['No se pudo identificar el usuario autenticado para registrar el traslado.'],
            ]);
        }

        $inventariosController = new CpuInventariosController();

        $result = DB::transaction(function () use ($data, $persona, $userId, $inventariosController) {
            $estadoAsignacionId = $this->getOrCreateEstadoId(self::ESTADO_ASIGNACION);
            $estadoMovimientoId = $this->getOrCreateEstadoId(self::ESTADO_MOVIMIENTO);

            $asignacionId = DB::table('inventarios.asignaciones')->insertGetId([
                'numero_asignacion' => null,
                'persona_destino_id' => $persona->id,
                'cedula_destino' => $persona->cedula,
                'nombre_destino' => $persona->nombres,
                'tipo_flujo' => $data['tipo_flujo'],
                'tipo_destino' => $data['tipo_destino'],
                'sede_origen_id' => $data['sede_origen_id'],
                'facultad_origen_id' => $data['facultad_origen_id'],
                'bodega_origen_id' => $data['bodega_origen_id'],
                'sede_destino_id' => $data['sede_destino_id'],
                'facultad_destino_id' => $data['facultad_destino_id'],
                'bodega_destino_id' => $data['bodega_destino_id'],
                'observacion' => $data['observacion'] ?? null,
                'estado_id' => $estadoAsignacionId,
                'estado' => self::ESTADO_ASIGNACION,
                'user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $numeroAsignacion = 'ASG-' . str_pad((string) $asignacionId, 6, '0', STR_PAD_LEFT);

            DB::table('inventarios.asignaciones')
                ->where('id', $asignacionId)
                ->update([
                    'numero_asignacion' => $numeroAsignacion,
                    'updated_at' => now(),
                ]);

            $observacionMovimientoBase = trim((string) ($data['observacion'] ?? ''));

            foreach ($data['items'] as $item) {
                $producto = DB::table('inventarios.productos')
                    ->select('id', 'codigo', 'ins_descripcion', 'requiere_lote', 'requiere_vencimiento')
                    ->where('id', $item['producto_id'])
                    ->lockForUpdate()
                    ->first();

                if (!$producto) {
                    throw ValidationException::withMessages([
                        'items' => ["El producto {$item['producto_id']} no existe en inventarios.productos."],
                    ]);
                }

                $cantidad = (float) $item['cantidad'];
                $usaLotes = (bool) ($producto->requiere_lote || $producto->requiere_vencimiento);
                $stockUtilOrigen = $this->obtenerStockUtilOrigen(
                    $producto,
                    (int) $data['bodega_origen_id'],
                    $usaLotes
                );

                if ($stockUtilOrigen < $cantidad) {
                    throw ValidationException::withMessages([
                        'items' => ["La cantidad a trasladar para {$producto->ins_descripcion} no puede superar el stock útil vigente. Disponible: {$stockUtilOrigen}."],
                    ]);
                }

                $desgloseOrigen = $usaLotes
                    ? $this->construirDesgloseTrasladoPorLote($producto, (int) $data['bodega_origen_id'], $cantidad)
                    : [];

                $payloadMovimientoOrigen = [
                    'idInsumo' => $producto->id,
                    'cantidad' => $cantidad,
                    'desglose_lotes' => $desgloseOrigen,
                ];

                $payloadMovimientoDestino = [
                    'idInsumo' => $producto->id,
                    'cantidad' => $cantidad,
                    'desglose_lotes' => $this->normalizarDesgloseDestino($desgloseOrigen),
                ];

                $observacionMovimiento = trim(sprintf(
                    '%s%s%s',
                    "Traslado interno {$numeroAsignacion} del producto {$producto->ins_descripcion} desde bodega {$data['bodega_origen_id']} hacia bodega {$data['bodega_destino_id']}.",
                    $observacionMovimientoBase !== '' ? ' ' : '',
                    $observacionMovimientoBase
                ));

                $inventariosController->guardarMovimientoInventario(
                    [$payloadMovimientoOrigen],
                    (int) $data['bodega_origen_id'],
                    'EGRESO',
                    $estadoMovimientoId,
                    (int) $userId,
                    $asignacionId,
                    $observacionMovimiento
                );

                $inventariosController->guardarMovimientoInventario(
                    [$payloadMovimientoDestino],
                    (int) $data['bodega_destino_id'],
                    'INGRESO',
                    $estadoMovimientoId,
                    (int) $userId,
                    $asignacionId,
                    $observacionMovimiento
                );

                DB::table('inventarios.asignaciones_detalles')->insert([
                    'asignacion_id' => $asignacionId,
                    'producto_id' => $producto->id,
                    'producto_codigo' => $producto->codigo,
                    'producto_nombre' => $producto->ins_descripcion,
                    'cantidad' => $cantidad,
                    'stock_total_origen' => $item['stock_total'] ?? null,
                    'stock_util_origen' => $item['stock_util'] ?? null,
                    'proximo_vencimiento_vigente' => $item['proximo_vencimiento_vigente'] ?? null,
                    'detalle_lotes' => !empty($desgloseOrigen) ? json_encode($desgloseOrigen, JSON_UNESCAPED_UNICODE) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return [
                'id' => $asignacionId,
                'numero_asignacion' => $numeroAsignacion,
                'estado_id' => $estadoAsignacionId,
                'estado_movimiento_id' => $estadoMovimientoId,
            ];
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Asignación registrada correctamente.',
            'data' => $result,
        ], 201);
    }

    private function getOrCreateEstadoId(string $nombre): int
    {
        $id = DB::table('cpu_estados')
            ->whereRaw('UPPER(estado) = ?', [mb_strtoupper($nombre)])
            ->value('id');

        if ($id) {
            return (int) $id;
        }

        $nextId = ((int) DB::table('cpu_estados')->max('id')) + 1;

        DB::table('cpu_estados')->insert([
            'id' => $nextId,
            'estado' => $nombre,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $nextId;
    }

    private function construirDesgloseTrasladoPorLote(object $producto, int $bodegaOrigenId, float $cantidadSolicitada): array
    {
        if ($cantidadSolicitada <= 0) {
            throw ValidationException::withMessages([
                'items' => ["La cantidad solicitada para {$producto->ins_descripcion} no es válida."],
            ]);
        }

        $lotesDisponibles = DB::table('inventarios.productos_lotes')
            ->where('id_insumo', $producto->id)
            ->where('id_bodega', $bodegaOrigenId)
            ->where('cantidad_actual', '>', 0)
            ->where(function ($query) {
                $query->whereNull('fecha_vencimiento')
                    ->orWhereDate('fecha_vencimiento', '>=', now()->toDateString());
            })
            ->orderByRaw('CASE WHEN fecha_vencimiento IS NULL THEN 1 ELSE 0 END')
            ->orderBy('fecha_vencimiento', 'asc')
            ->orderByRaw('CASE WHEN fecha_elaboracion IS NULL THEN 1 ELSE 0 END')
            ->orderBy('fecha_elaboracion', 'asc')
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get(['id', 'codigo_lote', 'fecha_elaboracion', 'fecha_vencimiento', 'cantidad_actual']);

        if ($lotesDisponibles->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => ["No hay lotes vigentes disponibles para {$producto->ins_descripcion} en la bodega origen."],
            ]);
        }

        $stockVigente = (float) $lotesDisponibles->sum('cantidad_actual');

        if ($stockVigente < $cantidadSolicitada) {
            throw ValidationException::withMessages([
                'items' => ["No existe stock útil suficiente para {$producto->ins_descripcion}. Disponible: {$stockVigente}."],
            ]);
        }

        $cantidadRestante = $cantidadSolicitada;
        $desglose = [];

        foreach ($lotesDisponibles as $lote) {
            if ($cantidadRestante <= 0) {
                break;
            }

            $stockLote = (float) ($lote->cantidad_actual ?? 0);

            if ($stockLote <= 0) {
                continue;
            }

            $cantidadTomar = min($cantidadRestante, $stockLote);

            $desglose[] = [
                'id_lote' => (int) $lote->id,
                'codigo_lote' => (string) ($lote->codigo_lote ?? ''),
                'fecha_elaboracion' => $lote->fecha_elaboracion,
                'fecha_vencimiento' => $lote->fecha_vencimiento,
                'cantidad' => (float) $cantidadTomar,
            ];

            $cantidadRestante -= $cantidadTomar;
        }

        if ($cantidadRestante > 0) {
            throw ValidationException::withMessages([
                'items' => ["No se pudo completar el traslado FEFO de {$producto->ins_descripcion} con lotes vigentes."],
            ]);
        }

        return $desglose;
    }

    private function obtenerStockUtilOrigen(object $producto, int $bodegaOrigenId, bool $usaLotes): float
    {
        if ($usaLotes) {
            return (float) DB::table('inventarios.productos_lotes')
                ->where('id_insumo', $producto->id)
                ->where('id_bodega', $bodegaOrigenId)
                ->where('cantidad_actual', '>', 0)
                ->where(function ($query) {
                    $query->whereNull('fecha_vencimiento')
                        ->orWhereDate('fecha_vencimiento', '>=', now()->toDateString());
                })
                ->sum('cantidad_actual');
        }

        return (float) DB::table('inventarios.stock_bodegas')
            ->where('sb_id_insumo', $producto->id)
            ->where('sb_id_bodega', $bodegaOrigenId)
            ->value('sb_cantidad');
    }

    private function normalizarDesgloseDestino(array $desgloseOrigen): array
    {
        return collect($desgloseOrigen)
            ->map(function ($segmento) {
                return [
                    'codigo_lote' => $segmento['codigo_lote'] ?? '',
                    'fecha_elaboracion' => $segmento['fecha_elaboracion'] ?? null,
                    'fecha_vencimiento' => $segmento['fecha_vencimiento'] ?? null,
                    'cantidad' => (float) ($segmento['cantidad'] ?? 0),
                ];
            })
            ->values()
            ->all();
    }
}
