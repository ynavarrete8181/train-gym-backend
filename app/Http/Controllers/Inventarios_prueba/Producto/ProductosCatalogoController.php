<?php

namespace App\Http\Controllers\Inventarios\Producto;

use App\Http\Controllers\AuditoriaControllers;
use App\Http\Controllers\Controller;
use App\Http\Controllers\LogController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Session;

class ProductosCatalogoController extends Controller
{
    protected $auditoriaController;
    protected $logController;

    public function __construct()
    {
        $this->middleware('auth:api');
        $this->auditoriaController = new AuditoriaControllers();
        $this->logController = new LogController();
    }

    public function consultarCatalogo()
    {
        try {
            $stockSub = DB::table('inventarios.stock_bodegas')
                ->select(
                    'sb_id_insumo',
                    DB::raw('COALESCE(SUM(sb_cantidad), 0) as stock_total'),
                    DB::raw('COUNT(DISTINCT sb_id_bodega) as total_bodegas'),
                    DB::raw('COALESCE(SUM(sb_cantidad_inicial), 0) as stock_inicial_total')
                )
                ->groupBy('sb_id_insumo');

            $lotesSub = DB::table('inventarios.productos_lotes')
                ->select(
                    'id_insumo',
                    DB::raw('COUNT(*) as total_lotes'),
                    DB::raw('COALESCE(SUM(cantidad_actual), 0) as stock_lotes'),
                    DB::raw('MIN(fecha_vencimiento) as proximo_vencimiento')
                )
                ->groupBy('id_insumo');

            $data = DB::table('inventarios.productos as i')
                ->leftJoin('inventarios.categorias_activos as ca', 'ca.ca_id', '=', 'i.id_tipo_insumo')
                ->leftJoin('cpu_estados as e', 'e.id', '=', 'i.id_estado')
                ->leftJoinSub($stockSub, 'stk', function ($join) {
                    $join->on('stk.sb_id_insumo', '=', 'i.id');
                })
                ->leftJoinSub($lotesSub, 'lot', function ($join) {
                    $join->on('lot.id_insumo', '=', 'i.id');
                })
                ->select([
                    'i.id',
                    'i.id_tipo_insumo',
                    DB::raw("COALESCE(ca.ca_descripcion, '') as tipo_insumo"),
                    'i.ins_descripcion',
                    'i.codigo',
                    'i.unidad_medida',
                    'i.marca',
                    'i.modelo',
                    'i.serie',
                    'i.id_estado',
                    DB::raw("COALESCE(e.estado, '') as nombre_estado"),
                    'i.estado_insumo',
                    'i.modo_adquirido',
                    'i.requiere_lote',
                    'i.requiere_vencimiento',
                    'i.created_at',
                    'i.updated_at',
                    DB::raw('COALESCE(stk.stock_total, 0) as stock_total'),
                    DB::raw('COALESCE(stk.stock_inicial_total, 0) as stock_inicial_total'),
                    DB::raw('COALESCE(stk.total_bodegas, 0) as total_bodegas'),
                    DB::raw('COALESCE(lot.total_lotes, 0) as total_lotes'),
                    DB::raw('COALESCE(lot.stock_lotes, 0) as stock_lotes'),
                    'lot.proximo_vencimiento',
                ])
                ->orderByDesc('i.id')
                ->get();

            return response()->json($data);
        } catch (\Exception $e) {
            Log::error('Error al consultar productos del catalogo: ' . $e->getMessage());

            $this->logController->saveLog(
                'Controlador: Producto\\ProductosCatalogoController, Función: consultarCatalogo()',
                'Error al consultar productos del catalogo: ' . $e->getMessage()
            );

            return response()->json([
                'success' => false,
                'message' => 'Error al consultar el catalogo de productos.'
            ], 500);
        }
    }

    public function consultarTiposCatalogo()
    {
        $data = DB::select('SELECT * FROM public.view_tipos_insumos');
        return response()->json($data);
    }

    public function consultarCatalogoPorTipo($idTipo)
    {
        $data = DB::select('SELECT * FROM public.view_insumos WHERE id_tipo_insumo = ?', [$idTipo]);
        return response()->json($data);
    }

    public function guardarCatalogo(Request $request)
    {
        Log::info('Datos recibidos en guardarCatalogo:', $request->all());

        $data = $request->all();
        $userId = $data['id_usuario'] ?? null;

        $requiereLote = filter_var($request->input('requiere_lote', false), FILTER_VALIDATE_BOOLEAN);
        $requiereVencimiento = filter_var($request->input('requiere_vencimiento', false), FILTER_VALIDATE_BOOLEAN);

        if ($requiereVencimiento) {
            $requiereLote = true;
        }

        $validator = Validator::make(
            array_merge($request->all(), [
                'requiere_lote' => $requiereLote,
                'requiere_vencimiento' => $requiereVencimiento,
            ]),
            [
                'txt-descripcion' => 'required|string|max:500',
                'txt-codigo' => 'required|string|max:500',
                'select-tipo' => 'required|integer|min:1',
                'select-estado' => 'required|integer|min:1',
                'select-unidad-medida' => 'required|string|max:100',
                'requiere_lote' => 'required|boolean',
                'requiere_vencimiento' => 'required|boolean',
            ]
        );

        if ($validator->fails()) {
            $this->logController->saveLog(
                'Controlador: Producto\\ProductosCatalogoController, Función: guardarCatalogo()',
                'Error de validación: ' . json_encode($validator->errors())
            );

            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'data' => $validator->errors()
            ], 400);
        }

        $codigo = trim((string) $data['txt-codigo']);

        $existeCodigo = DB::table('inventarios.productos')
            ->whereRaw('LOWER(TRIM(codigo)) = ?', [mb_strtolower($codigo)])
            ->exists();

        if ($existeCodigo) {
            return response()->json([
                'success' => false,
                'message' => 'El código "' . $codigo . '" ya está registrado'
            ], 200);
        }

        try {
            DB::beginTransaction();

            $id = DB::table('inventarios.productos')->insertGetId([
                'id_tipo_insumo' => (int) $data['select-tipo'],
                'ins_descripcion' => trim((string) $data['txt-descripcion']),
                'marca' => !empty($data['txt-marca']) ? trim((string) $data['txt-marca']) : null,
                'modelo' => !empty($data['txt-modelo']) ? trim((string) $data['txt-modelo']) : null,
                'serie' => !empty($data['txt-serie']) ? trim((string) $data['txt-serie']) : null,
                'codigo' => $codigo,
                'id_estado' => (int) $data['select-estado'],
                'unidad_medida' => trim((string) $data['select-unidad-medida']),
                'created_at' => now(),
                'updated_at' => now(),
                'id_usuario' => $userId,
                'requiere_lote' => $requiereLote,
                'requiere_vencimiento' => $requiereVencimiento,
                'lotes' => json_encode([]),
            ]);

            $this->auditoriaController->auditar(
                'inventarios.productos',
                'guardarCatalogo',
                '',
                json_encode([
                    'id_tipo_insumo' => (int) $data['select-tipo'],
                    'ins_descripcion' => trim((string) $data['txt-descripcion']),
                    'codigo' => $codigo,
                    'id_estado' => (int) $data['select-estado'],
                    'unidad_medida' => trim((string) $data['select-unidad-medida']),
                    'id_usuario' => $userId,
                    'requiere_lote' => $requiereLote,
                    'requiere_vencimiento' => $requiereVencimiento,
                ]),
                'INSERT',
                'Se guardó el producto del catalogo "' . trim((string) $data['txt-descripcion']) . '" (ID ' . $id . ').'
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Producto agregado correctamente',
                'data' => ['id' => $id]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            $this->logController->saveLog(
                'Controlador: Producto\\ProductosCatalogoController, Función: guardarCatalogo()',
                'Error al guardar producto: ' . $e->getMessage()
            );

            return response()->json([
                'success' => false,
                'message' => 'Hubo un problema al guardar el producto: ' . $e->getMessage()
            ], 500);
        }
    }

    public function modificarCatalogo(Request $request, $id)
    {
        Log::info('Datos recibidos para modificar catalogo:', $request->all());

        $data = $request->all();
        $userId = Session::get('user_id') ?? ($data['id_usuario'] ?? null);

        $requiereLote = filter_var($request->input('requiere_lote', false), FILTER_VALIDATE_BOOLEAN);
        $requiereVencimiento = filter_var($request->input('requiere_vencimiento', false), FILTER_VALIDATE_BOOLEAN);

        if ($requiereVencimiento) {
            $requiereLote = true;
        }

        $payloadValidacion = array_merge($request->all(), [
            'requiere_lote' => $requiereLote,
            'requiere_vencimiento' => $requiereVencimiento,
        ]);

        $rules = [
            'txt-descripcion' => 'required|string|max:500',
            'txt-codigo' => 'required|string|max:500',
            'select-tipo' => 'required|integer|min:1',
            'select-estado' => 'required|integer|min:1',
            'select-unidad-medida' => 'required|string|max:100',
            'requiere_lote' => 'required|boolean',
            'requiere_vencimiento' => 'required|boolean',
        ];

        if ((int) ($data['select-tipo'] ?? 0) === 1) {
            $rules['txt-marca'] = 'required|string|max:255';
            $rules['txt-modelo'] = 'required|string|max:255';
            $rules['txt-serie'] = 'required|string|max:255';
        }

        $validator = Validator::make($payloadValidacion, $rules);

        if ($validator->fails()) {
            $this->logController->saveLog(
                'Controlador: Producto\\ProductosCatalogoController, Función: modificarCatalogo()',
                'Error de validación: ' . json_encode($validator->errors())
            );

            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'data' => $validator->errors()
            ], 400);
        }

        $productoActual = DB::table('inventarios.productos')->where('id', $id)->first();

        if (!$productoActual) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontró el producto con ID ' . $id
            ], 404);
        }

        $codigo = trim((string) $data['txt-codigo']);

        $existeCodigo = DB::table('inventarios.productos')
            ->whereRaw('LOWER(TRIM(codigo)) = ?', [mb_strtolower($codigo)])
            ->where('id', '<>', $id)
            ->exists();

        if ($existeCodigo) {
            return response()->json([
                'success' => false,
                'message' => 'El código "' . $codigo . '" ya está registrado en otro producto'
            ], 200);
        }

        try {
            DB::beginTransaction();

            $datosActualizar = [
                'id_tipo_insumo' => (int) $data['select-tipo'],
                'ins_descripcion' => trim((string) $data['txt-descripcion']),
                'codigo' => $codigo,
                'id_estado' => (int) $data['select-estado'],
                'unidad_medida' => trim((string) $data['select-unidad-medida']),
                'marca' => !empty($data['txt-marca']) ? trim((string) $data['txt-marca']) : null,
                'modelo' => !empty($data['txt-modelo']) ? trim((string) $data['txt-modelo']) : null,
                'serie' => !empty($data['txt-serie']) ? trim((string) $data['txt-serie']) : null,
                'requiere_lote' => $requiereLote,
                'requiere_vencimiento' => $requiereVencimiento,
                'updated_at' => now(),
                'id_usuario' => $userId,
            ];

            DB::table('inventarios.productos')
                ->where('id', $id)
                ->update($datosActualizar);

            $this->auditoriaController->auditar(
                'inventarios.productos',
                'modificarCatalogo',
                json_encode($productoActual),
                json_encode($datosActualizar),
                'UPDATE',
                'Se modificó el producto del catalogo "' . trim((string) $data['txt-descripcion']) . '" (ID ' . $id . ').'
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Producto modificado correctamente',
                'data' => ['id' => $id]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            $this->logController->saveLog(
                'Controlador: Producto\\ProductosCatalogoController, Función: modificarCatalogo()',
                'Error al modificar producto: ' . $e->getMessage()
            );

            return response()->json([
                'success' => false,
                'message' => 'Hubo un problema al modificar el producto: ' . $e->getMessage()
            ], 500);
        }
    }

    public function obtenerProductoCatalogoPorId($id)
    {
        try {
            $stockSub = DB::table('inventarios.stock_bodegas')
                ->select(
                    'sb_id_insumo',
                    DB::raw('COALESCE(SUM(sb_cantidad), 0) as stock_total'),
                    DB::raw('COUNT(DISTINCT sb_id_bodega) as total_bodegas'),
                    DB::raw('COALESCE(SUM(sb_cantidad_inicial), 0) as stock_inicial_total')
                )
                ->groupBy('sb_id_insumo');

            $lotesSub = DB::table('inventarios.productos_lotes')
                ->select(
                    'id_insumo',
                    DB::raw('COUNT(*) as total_lotes'),
                    DB::raw('COALESCE(SUM(cantidad_actual), 0) as stock_lotes'),
                    DB::raw('MIN(fecha_vencimiento) as proximo_vencimiento')
                )
                ->groupBy('id_insumo');

            $data = DB::table('inventarios.productos as i')
                ->leftJoin('inventarios.categorias_activos as ca', 'ca.ca_id', '=', 'i.id_tipo_insumo')
                ->leftJoin('cpu_estados as e', 'e.id', '=', 'i.id_estado')
                ->leftJoinSub($stockSub, 'stk', function ($join) {
                    $join->on('stk.sb_id_insumo', '=', 'i.id');
                })
                ->leftJoinSub($lotesSub, 'lot', function ($join) {
                    $join->on('lot.id_insumo', '=', 'i.id');
                })
                ->select([
                    'i.id',
                    'i.id_tipo_insumo',
                    DB::raw("COALESCE(ca.ca_descripcion, '') as tipo_insumo"),
                    'i.ins_descripcion',
                    'i.codigo',
                    'i.unidad_medida',
                    'i.marca',
                    'i.modelo',
                    'i.serie',
                    'i.id_estado',
                    DB::raw("COALESCE(e.estado, '') as nombre_estado"),
                    'i.estado_insumo',
                    'i.modo_adquirido',
                    'i.requiere_lote',
                    'i.requiere_vencimiento',
                    'i.created_at',
                    'i.updated_at',
                    DB::raw('COALESCE(stk.stock_total, 0) as stock_total'),
                    DB::raw('COALESCE(stk.stock_inicial_total, 0) as stock_inicial_total'),
                    DB::raw('COALESCE(stk.total_bodegas, 0) as total_bodegas'),
                    DB::raw('COALESCE(lot.total_lotes, 0) as total_lotes'),
                    DB::raw('COALESCE(lot.stock_lotes, 0) as stock_lotes'),
                    'lot.proximo_vencimiento',
                ])
                ->where('i.id', $id)
                ->first();

            if (!$data) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró el producto solicitado.'
                ], 404);
            }

            return response()->json($data);
        } catch (\Exception $e) {
            Log::error('Error al obtener producto del catalogo por ID: ' . $e->getMessage());
            $this->logController->saveLog(
                'Controlador: Producto\\ProductosCatalogoController, Función: obtenerProductoCatalogoPorId($id)',
                'Error al obtener producto: ' . $e->getMessage()
            );

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el producto.',
            ], 500);
        }
    }

    public function eliminarProductoCatalogo($id)
    {
        try {
            $producto = DB::table('inventarios.productos')->where('id', $id)->first();

            if (!$producto) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró el producto con ID ' . $id,
                ], 404);
            }

            $dependencias = [
                ['tabla' => 'inventarios.stock_bodegas', 'columna' => 'sb_id_insumo', 'label' => 'stock por bodega'],
                ['tabla' => 'inventarios.productos_lotes', 'columna' => 'id_insumo', 'label' => 'lotes'],
                ['tabla' => 'inventarios.movimientos', 'columna' => 'mi_id_insumo', 'label' => 'movimientos'],
                ['tabla' => 'cpu_insumos_ocupados', 'columna' => 'id_insumo', 'label' => 'consumos registrados'],
                ['tabla' => 'inventarios.bajas_detalles', 'columna' => 'insumo_id', 'label' => 'bajas registradas'],
            ];

            $bloqueos = [];

            foreach ($dependencias as $dependencia) {
                $count = DB::table($dependencia['tabla'])
                    ->where($dependencia['columna'], $id)
                    ->count();

                if ($count > 0) {
                    $bloqueos[] = $dependencia['label'];
                }
            }

            if (!empty($bloqueos)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede eliminar el producto porque tiene registros relacionados: ' . implode(', ', $bloqueos) . '.',
                ], 409);
            }

            DB::table('inventarios.productos')->where('id', $id)->delete();

            $this->auditoriaController->auditar(
                'inventarios.productos',
                'eliminarProductoCatalogo',
                json_encode($producto),
                '',
                'DELETE',
                'Se eliminó el producto "' . ($producto->ins_descripcion ?? '') . '" (ID ' . $id . ').'
            );

            return response()->json([
                'success' => true,
                'message' => 'Producto eliminado correctamente.',
            ]);
        } catch (\Exception $e) {
            Log::error('Error al eliminar producto del catalogo: ' . $e->getMessage());
            $this->logController->saveLog(
                'Controlador: Producto\\ProductosCatalogoController, Función: eliminarProductoCatalogo($id)',
                'Error al eliminar producto: ' . $e->getMessage()
            );

            return response()->json([
                'success' => false,
                'message' => 'No se pudo eliminar el producto: ' . $e->getMessage(),
            ], 500);
        }
    }

    // Compatibilidad temporal con nombres heredados.
    public function consultarInsumos()
    {
        return $this->consultarCatalogo();
    }

    public function consultarTiposInsumos()
    {
        return $this->consultarTiposCatalogo();
    }

    public function consultarInsumosPorTipo($idTipo)
    {
        return $this->consultarCatalogoPorTipo($idTipo);
    }

    public function saveInsumos(Request $request)
    {
        return $this->guardarCatalogo($request);
    }

    public function modificarInsumo(Request $request, $id)
    {
        return $this->modificarCatalogo($request, $id);
    }

    public function getInsumoById($id)
    {
        return $this->obtenerProductoCatalogoPorId($id);
    }

    public function eliminarInsumo($id)
    {
        return $this->eliminarProductoCatalogo($id);
    }
}
