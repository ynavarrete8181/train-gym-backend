<?php

namespace App\Http\Controllers\Inventarios\Producto;

use App\Http\Controllers\Controller;
use App\Http\Controllers\LogController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ProductosController extends Controller
{
    protected $logController;

    public function __construct()
    {
        $this->middleware('auth:api');
        $this->logController = new LogController();
    }

    public function consultarProductos()
    {
        $data = DB::table('inventarios.productos as p')
            ->leftJoin('inventarios.categorias_activos as c', 'c.ca_id', '=', 'p.id_tipo_insumo')
            ->leftJoin('cpu_estados as e', 'e.id', '=', 'p.id_estado')
            ->select(
                'p.id as pro_id',
                'p.id_tipo_insumo as pro_id_categoria',
                'p.ins_descripcion as pro_descripcion',
                'p.codigo as pro_codigo',
                'p.id_tipo_insumo as pro_tipo',
                'p.unidad_medida as pro_unidad_medida',
                'p.id_estado as pro_estado',
                DB::raw("COALESCE(c.ca_descripcion, '') as descripcion"),
                DB::raw("COALESCE(e.estado, '') as estado")
            )
            ->orderByDesc('p.id')
            ->get();

        return response()->json($data);
    }

    public function saveProductos(Request $request)
    {
        Log::info('data', $request->all());
        $data = $request->all();

        $validator = Validator::make($request->all(), [
            'txt-descripcion' => 'required|string|max:500',
            'txt-codigo' => 'required|string|max:500',
            'select-tipo' => 'required|integer|min:1',
            'select-estado' => 'required|integer|min:1',
            'select-unidad-medida' => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
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

        DB::table('inventarios.productos')->insert([
            'id_tipo_insumo' => (int) $data['select-tipo'],
            'ins_descripcion' => trim((string) $data['txt-descripcion']),
            'codigo' => $codigo,
            'id_estado' => (int) $data['select-estado'],
            'unidad_medida' => trim((string) $data['select-unidad-medida']),
            'requiere_lote' => filter_var($data['requiere_lote'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'requiere_vencimiento' => filter_var($data['requiere_vencimiento'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'created_at' => now(),
            'updated_at' => now()
        ]);

        return response()->json(['success' => true, 'message' => 'Producto agregado correctamente']);
    }

    public function modificarProductos(Request $request, $id)
    {
        Log::info('data', $request->all());
        $data = $request->all();

        $validator = Validator::make($request->all(), [
            'txt-descripcion' => 'required|string|max:500',
            'txt-codigo' => 'required|string|max:500',
            'select-tipo' => 'required|integer|min:1',
            'select-estado' => 'required|integer|min:1',
            'select-unidad-medida' => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $codigo = trim((string) $data['txt-codigo']);

        $existeCodigo = DB::table('inventarios.productos')
            ->whereRaw('LOWER(TRIM(codigo)) = ?', [mb_strtolower($codigo)])
            ->where('id', '<>', $id)
            ->exists();

        if ($existeCodigo) {
            return response()->json([
                'success' => false,
                'message' => 'El código "' . $codigo . '" ya está registrado'
            ], 200);
        }

        DB::table('inventarios.productos')
            ->where('id', $id)
            ->update([
                'id_tipo_insumo' => (int) $data['select-tipo'],
                'ins_descripcion' => trim((string) $data['txt-descripcion']),
                'codigo' => $codigo,
                'id_estado' => (int) $data['select-estado'],
                'unidad_medida' => trim((string) $data['select-unidad-medida']),
                'requiere_lote' => filter_var($data['requiere_lote'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'requiere_vencimiento' => filter_var($data['requiere_vencimiento'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'updated_at' => now()
            ]);

        return response()->json(['success' => true, 'message' => 'Producto modificado correctamente']);
    }

    public function getProductosAtencionMedicinaGeneral()
    {
        try {
            $estadoActivo = 8;

            $tipoProducto = 1;
            $tipoInsumoMedico = 3;
            $tipoMedicamento = 2;

            $productos = DB::select(
                "
            SELECT
                s.id AS sede_id,
                s.nombre_sede AS sede_nombre,
                b.bod_id,
                b.bod_nombre AS bodega_nombre,
                i.id,
                i.id_tipo_insumo,
                i.ins_descripcion,
                COALESCE(sb.sb_cantidad, 0)::int AS cantidad_unidades,
                COALESCE(sb.sb_stock_minimo, 0)::int AS stock_minimo,
                b.bod_estado,
                i.id_estado,
                FALSE AS usa_lotes,
                NULL::date AS proxima_fecha_vencimiento,
                0::int AS total_lotes
            FROM inventarios.stock_bodegas sb
            INNER JOIN inventarios.productos i
                ON i.id = sb.sb_id_insumo
            INNER JOIN inventarios.bodegas b
                ON b.bod_id = sb.sb_id_bodega
            LEFT JOIN cpu_sede s
                ON s.id = b.bod_id_sede
            WHERE COALESCE(sb.sb_cantidad, 0) > 0
              AND i.id_tipo_insumo = ?
              AND i.id_estado = ?
            ORDER BY s.nombre_sede, b.bod_nombre, i.ins_descripcion
            ",
                [$tipoProducto, $estadoActivo]
            );

            $insumosMedicos = DB::select(
                "
            SELECT
                s.id AS sede_id,
                s.nombre_sede AS sede_nombre,
                b.bod_id,
                b.bod_nombre AS bodega_nombre,
                i.id,
                i.id_tipo_insumo,
                i.ins_descripcion,
                COALESCE(sb.sb_cantidad, 0)::int AS cantidad_unidades,
                COALESCE(sb.sb_stock_minimo, 0)::int AS stock_minimo,
                b.bod_estado,
                i.id_estado,
                FALSE AS usa_lotes,
                NULL::date AS proxima_fecha_vencimiento,
                0::int AS total_lotes
            FROM inventarios.stock_bodegas sb
            INNER JOIN inventarios.productos i
                ON i.id = sb.sb_id_insumo
            INNER JOIN inventarios.bodegas b
                ON b.bod_id = sb.sb_id_bodega
            LEFT JOIN cpu_sede s
                ON s.id = b.bod_id_sede
            WHERE COALESCE(sb.sb_cantidad, 0) > 0
              AND i.id_tipo_insumo = ?
              AND i.id_estado = ?
            ORDER BY s.nombre_sede, b.bod_nombre, i.ins_descripcion
            ",
                [$tipoInsumoMedico, $estadoActivo]
            );

            $medicamentos = DB::select(
                "
            SELECT
                x.sede_id,
                x.sede_nombre,
                x.bod_id,
                x.bodega_nombre,
                x.id,
                x.id_tipo_insumo,
                x.ins_descripcion,
                x.cantidad_unidades,
                x.stock_minimo,
                x.bod_estado,
                x.id_estado,
                x.requiere_lote,
                x.requiere_vencimiento,
                x.usa_lotes,
                x.proxima_fecha_vencimiento,
                x.total_lotes
            FROM (
                SELECT
                    s.id AS sede_id,
                    s.nombre_sede AS sede_nombre,
                    b.bod_id,
                    b.bod_nombre AS bodega_nombre,
                    i.id,
                    i.id_tipo_insumo,
                    i.ins_descripcion,
                    COALESCE(i.requiere_lote, FALSE) AS requiere_lote,
                    COALESCE(i.requiere_vencimiento, FALSE) AS requiere_vencimiento,

                    CASE
                        WHEN COALESCE(i.requiere_lote, FALSE) = TRUE
                          OR COALESCE(i.requiere_vencimiento, FALSE) = TRUE
                            THEN COALESCE(SUM(l.cantidad_actual), 0)
                        ELSE COALESCE(MAX(sb.sb_cantidad), 0)
                    END::int AS cantidad_unidades,

                    COALESCE(MAX(sb.sb_stock_minimo), 0)::int AS stock_minimo,
                    b.bod_estado,
                    i.id_estado,

                    CASE
                        WHEN COALESCE(i.requiere_lote, FALSE) = TRUE
                          OR COALESCE(i.requiere_vencimiento, FALSE) = TRUE
                            THEN TRUE
                        ELSE FALSE
                    END AS usa_lotes,

                    MIN(l.fecha_vencimiento) FILTER (
                        WHERE l.fecha_vencimiento IS NOT NULL
                          AND COALESCE(l.cantidad_actual, 0) > 0
                    ) AS proxima_fecha_vencimiento,

                    COUNT(l.id) FILTER (
                        WHERE COALESCE(l.cantidad_actual, 0) > 0
                    )::int AS total_lotes

                FROM inventarios.stock_bodegas sb
                INNER JOIN inventarios.productos i
                    ON i.id = sb.sb_id_insumo
                INNER JOIN inventarios.bodegas b
                    ON b.bod_id = sb.sb_id_bodega
                LEFT JOIN cpu_sede s
                    ON s.id = b.bod_id_sede
                LEFT JOIN inventarios.productos_lotes l
                    ON l.id_insumo = i.id
                   AND l.id_bodega = b.bod_id
                   AND COALESCE(l.cantidad_actual, 0) > 0
                   AND (
                        l.fecha_vencimiento IS NULL
                        OR l.fecha_vencimiento > CURRENT_DATE
                   )

                WHERE i.id_tipo_insumo = ?
                  AND i.id_estado = ?
                  AND COALESCE(sb.sb_cantidad, 0) > 0

                GROUP BY
                    s.id,
                    s.nombre_sede,
                    b.bod_id,
                    b.bod_nombre,
                    i.id,
                    i.id_tipo_insumo,
                    i.ins_descripcion,
                    i.requiere_lote,
                    i.requiere_vencimiento,
                    b.bod_estado,
                    i.id_estado
            ) x
            WHERE x.cantidad_unidades > 0
            ORDER BY x.sede_nombre, x.bodega_nombre, x.ins_descripcion
            ",
                [$tipoMedicamento, $estadoActivo]
            );

            return response()->json([
                'productos' => $productos,
                'insumosMedicos' => $insumosMedicos,
                'medicamentos' => $medicamentos
            ]);
        } catch (\Throwable $e) {
            $errorData = [
                'descripcion' => $e->getMessage(),
                'detalle' => $e->getTraceAsString(),
            ];

            Log::error('Error en getProductosAtencionMedicinaGeneral: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            $this->logController->saveLog(
                'Controlador: Producto\\ProductosController, Funcion: getProductosAtencionMedicinaGeneral()',
                json_encode($errorData, JSON_UNESCAPED_UNICODE)
            );

            return response()->json([
                'message' => 'Ocurrió un error al consultar productos, insumos y medicamentos.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
