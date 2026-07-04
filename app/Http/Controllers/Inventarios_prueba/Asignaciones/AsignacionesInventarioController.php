<?php

namespace App\Http\Controllers\Inventarios\Asignaciones;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AsignacionesInventarioController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    public function prefetch()
    {
        $bodegas = DB::table('inventarios.bodegas as b')
            ->leftJoin('cpu_sede as s', 's.id', '=', 'b.bod_id_sede')
            ->leftJoin('cpu_facultad as f', 'f.id', '=', 'b.bod_id_facultad')
            ->select(
                'b.bod_id as id',
                'b.bod_nombre as nombre',
                'b.bod_id_sede as sede_id',
                'b.bod_id_facultad as facultad_id',
                's.nombre_sede',
                'f.fac_nombre as nombre_facultad'
            )
            ->where('b.bod_estado', 8)
            ->orderBy('s.nombre_sede')
            ->orderBy('f.fac_nombre')
            ->orderBy('b.bod_nombre')
            ->get();

        $categorias = DB::table('inventarios.categorias_activos')
            ->select('ca_id as id', 'ca_descripcion as nombre')
            ->where('ca_id_estado', 8)
            ->orderBy('ca_descripcion')
            ->get();

        return response()->json([
            'tipos_flujo' => [
                ['value' => 'medicamentos_insumos', 'label' => 'Medicamentos e insumos'],
                ['value' => 'bienes_suministros', 'label' => 'Bienes y suministros'],
            ],
            'tipos_destino' => [
                ['value' => 'bodega', 'label' => 'Otra bodega'],
                ['value' => 'area', 'label' => 'Área'],
                ['value' => 'funcionario', 'label' => 'Funcionario'],
            ],
            'areas_destino' => [
                ['value' => 'TRIAJE', 'label' => 'Triaje'],
                ['value' => 'FISIOTERAPIA', 'label' => 'Fisioterapia'],
                ['value' => 'MEDICINA_GENERAL', 'label' => 'Medicina General'],
                ['value' => 'ENFERMERIA', 'label' => 'Enfermería'],
                ['value' => 'ODONTOLOGIA', 'label' => 'Odontología'],
                ['value' => 'ADMINISTRATIVA', 'label' => 'Administrativa'],
            ],
            'bodegas' => $bodegas,
            'categorias' => $categorias,
        ]);
    }

    public function index(Request $request)
    {
        if (!Schema::hasTable('inventarios.asignaciones') || !Schema::hasTable('inventarios.asignaciones_detalles')) {
            return response()->json([
                'data' => [],
                'summary' => [
                    'total' => 0,
                    'medicamentos_insumos' => 0,
                    'bienes_suministros' => 0,
                ],
                'message' => 'Las tablas de asignaciones aún no existen en este entorno.',
            ]);
        }

        $hasEstadoId = Schema::hasColumn('inventarios.asignaciones', 'estado_id');

        $query = DB::table('inventarios.asignaciones as a')
            ->leftJoin('inventarios.asignaciones_detalles as d', 'd.asignacion_id', '=', 'a.id')
            ->leftJoin('inventarios.bodegas as bo', 'bo.bod_id', '=', 'a.bodega_origen_id')
            ->leftJoin('inventarios.bodegas as bd', 'bd.bod_id', '=', 'a.bodega_destino_id');

        if ($hasEstadoId) {
            $query->leftJoin('cpu_estados as e', 'e.id', '=', 'a.estado_id');
        }

        $selects = [
            'a.id',
            'a.numero_asignacion',
            'a.created_at as fecha',
            'a.tipo_flujo as tipo',
            'a.cedula_destino as cedula',
            'a.nombre_destino as responsable',
            'bo.bod_nombre as origen',
            'bd.bod_nombre as destino',
            DB::raw("COUNT(d.id) as total_items"),
            DB::raw("COALESCE(SUM(d.cantidad), 0) as cantidad_total"),
        ];

        if ($hasEstadoId) {
            $selects[] = 'a.estado_id';
            $selects[] = DB::raw("COALESCE(e.estado, a.estado) as estado");
        } else {
            $selects[] = DB::raw('NULL as estado_id');
            $selects[] = 'a.estado as estado';
        }

        $groupBy = [
            'a.id',
            'a.numero_asignacion',
            'a.created_at',
            'a.tipo_flujo',
            'a.cedula_destino',
            'a.nombre_destino',
            'a.estado',
            'bo.bod_nombre',
            'bd.bod_nombre',
        ];

        if ($hasEstadoId) {
            $groupBy[] = 'a.estado_id';
            $groupBy[] = 'e.estado';
        }

        $rows = $query
            ->select($selects)
            ->groupBy(...$groupBy)
            ->orderByDesc('a.id')
            ->get()
            ->map(function ($row) {
                $row->detalle = "{$row->total_items} item(s) · {$row->cantidad_total} unidad(es)";
                return $row;
            });

        $summary = [
            'total' => $rows->count(),
            'medicamentos_insumos' => $rows->where('tipo', 'medicamentos_insumos')->count(),
            'bienes_suministros' => $rows->where('tipo', 'bienes_suministros')->count(),
        ];

        return response()->json([
            'data' => $rows->values(),
            'summary' => $summary,
            'message' => 'Listado de asignaciones cargado correctamente.',
        ]);
    }

    public function buscarReceptor(Request $request)
    {
        $cedula = trim((string) $request->query('cedula', ''));

        if ($cedula === '') {
            return response()->json([
                'persona' => null,
                'asignaciones' => [],
            ]);
        }

        $persona = DB::table('cpu_personas as p')
            ->select(
                'p.id',
                'p.cedula',
                'p.nombres',
                'p.celular'
            )
            ->where('p.cedula', $cedula)
            ->first();

        $asignaciones = [];

        if ($persona && Schema::hasTable('inventarios.asignaciones') && Schema::hasTable('inventarios.asignaciones_detalles')) {
            $asignaciones = DB::table('inventarios.asignaciones as a')
                ->join('inventarios.asignaciones_detalles as d', 'd.asignacion_id', '=', 'a.id')
                ->leftJoin('inventarios.productos as p', 'p.id', '=', 'd.producto_id')
                ->leftJoin('inventarios.bodegas as bo', 'bo.bod_id', '=', 'a.bodega_origen_id')
                ->leftJoin('inventarios.bodegas as bd', 'bd.bod_id', '=', 'a.bodega_destino_id')
                ->select(
                    'a.id',
                    'a.created_at as fecha',
                    'a.tipo_destino',
                    'bo.bod_nombre as bodega_origen',
                    'bd.bod_nombre as bodega_destino',
                    'p.ins_descripcion as producto',
                    'd.cantidad'
                )
                ->where('a.persona_destino_id', $persona->id)
                ->orderByDesc('a.id')
                ->get();
        }

        return response()->json([
            'persona' => $persona,
            'asignaciones' => $asignaciones,
        ]);
    }

    public function buscarProductos(Request $request)
    {
        $tipoFlujo = (string) $request->query('tipo_flujo', 'medicamentos_insumos');
        $bodegaId = (int) $request->query('bodega_id', 0);
        $search = trim((string) $request->query('q', ''));

        $query = DB::table('inventarios.stock_bodegas as sb')
            ->join('inventarios.productos as p', 'p.id', '=', 'sb.sb_id_insumo')
            ->join('inventarios.bodegas as b', 'b.bod_id', '=', 'sb.sb_id_bodega')
            ->leftJoin('inventarios.categorias_activos as c', 'c.ca_id', '=', 'p.id_tipo_insumo')
            ->leftJoin('inventarios.productos_lotes as l', function ($join) {
                $join->on('l.id_insumo', '=', 'p.id')
                    ->on('l.id_bodega', '=', 'sb.sb_id_bodega');
            })
            ->select(
                'p.id',
                'p.codigo',
                'p.ins_descripcion',
                'p.id_tipo_insumo',
                'p.marca',
                'p.modelo',
                'p.serie',
                'p.requiere_lote',
                'p.requiere_vencimiento',
                'sb.sb_id_bodega as bodega_id',
                'b.bod_nombre as nombre_bodega',
                'sb.sb_cantidad as stock_bodega',
                DB::raw("COALESCE(c.ca_descripcion, '') as categoria_nombre"),
                DB::raw('COUNT(l.id) as total_lotes'),
                DB::raw("SUM(CASE WHEN l.id IS NOT NULL AND (l.fecha_vencimiento IS NULL OR l.fecha_vencimiento >= CURRENT_DATE) THEN COALESCE(l.cantidad_actual, 0) ELSE 0 END) as stock_lotes_vigentes"),
                DB::raw("SUM(CASE WHEN l.id IS NOT NULL AND l.fecha_vencimiento IS NOT NULL AND l.fecha_vencimiento < CURRENT_DATE THEN COALESCE(l.cantidad_actual, 0) ELSE 0 END) as stock_lotes_vencidos"),
                DB::raw("SUM(CASE WHEN l.id IS NOT NULL AND (l.fecha_vencimiento IS NULL OR l.fecha_vencimiento >= CURRENT_DATE) THEN 1 ELSE 0 END) as total_lotes_vigentes"),
                DB::raw("SUM(CASE WHEN l.fecha_vencimiento IS NOT NULL AND l.fecha_vencimiento < CURRENT_DATE THEN 1 ELSE 0 END) as lotes_vencidos"),
                DB::raw("SUM(CASE WHEN l.fecha_vencimiento IS NOT NULL AND l.fecha_vencimiento >= CURRENT_DATE AND l.fecha_vencimiento <= CURRENT_DATE + INTERVAL '45 days' THEN 1 ELSE 0 END) as lotes_por_vencer"),
                DB::raw("MIN(CASE WHEN l.fecha_vencimiento IS NOT NULL AND l.fecha_vencimiento >= CURRENT_DATE AND COALESCE(l.cantidad_actual, 0) > 0 THEN l.fecha_vencimiento ELSE NULL END) as proximo_vencimiento_vigente"),
                DB::raw("
                    CASE
                        WHEN COALESCE(p.requiere_lote, FALSE) = TRUE
                          OR COALESCE(p.requiere_vencimiento, FALSE) = TRUE
                            THEN SUM(
                                CASE
                                    WHEN COALESCE(l.cantidad_actual, 0) > 0
                                     AND (l.fecha_vencimiento IS NULL OR l.fecha_vencimiento >= CURRENT_DATE)
                                        THEN COALESCE(l.cantidad_actual, 0)
                                    ELSE 0
                                END
                            )
                        ELSE sb.sb_cantidad
                    END as stock_util
                ")
            )
            ->where('p.id_estado', 8)
            ->where('sb.sb_cantidad', '>', 0)
            ->groupBy(
                'p.id',
                'p.codigo',
                'p.ins_descripcion',
                'p.id_tipo_insumo',
                'p.marca',
                'p.modelo',
                'p.serie',
                'p.requiere_lote',
                'p.requiere_vencimiento',
                'sb.sb_id_bodega',
                'b.bod_nombre',
                'sb.sb_cantidad',
                'c.ca_descripcion'
            )
            ->orderBy('p.ins_descripcion');

        if ($bodegaId > 0) {
            $query->where('sb.sb_id_bodega', $bodegaId);
        }

        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where(function ($subQuery) use ($like) {
                $subQuery
                    ->where('p.ins_descripcion', 'ILIKE', $like)
                    ->orWhere('p.codigo', 'ILIKE', $like)
                    ->orWhere('p.marca', 'ILIKE', $like)
                    ->orWhere('p.modelo', 'ILIKE', $like)
                    ->orWhere('p.serie', 'ILIKE', $like);
            });
        }

        if ($tipoFlujo === 'medicamentos_insumos') {
            $query->whereIn('p.id_tipo_insumo', [2, 3])
                ->havingRaw("
                    CASE
                        WHEN COALESCE(p.requiere_lote, FALSE) = TRUE
                          OR COALESCE(p.requiere_vencimiento, FALSE) = TRUE
                            THEN SUM(
                                CASE
                                    WHEN COALESCE(l.cantidad_actual, 0) > 0
                                     AND (l.fecha_vencimiento IS NULL OR l.fecha_vencimiento >= CURRENT_DATE)
                                        THEN COALESCE(l.cantidad_actual, 0)
                                    ELSE 0
                                END
                            )
                        ELSE MAX(COALESCE(sb.sb_cantidad, 0))
                    END > 0
                ");
        } else {
            $query->whereNotIn('p.id_tipo_insumo', [2, 3]);
        }

        return response()->json($query->limit(50)->get());
    }

    public function show(int $id)
    {
        if (!Schema::hasTable('inventarios.asignaciones') || !Schema::hasTable('inventarios.asignaciones_detalles')) {
            return response()->json([
                'message' => 'Las tablas de asignaciones aún no existen en este entorno.',
            ], 404);
        }

        $hasEstadoId = Schema::hasColumn('inventarios.asignaciones', 'estado_id');

        $query = DB::table('inventarios.asignaciones as a')
            ->leftJoin('inventarios.bodegas as bo', 'bo.bod_id', '=', 'a.bodega_origen_id')
            ->leftJoin('inventarios.bodegas as bd', 'bd.bod_id', '=', 'a.bodega_destino_id')
            ->leftJoin('cpu_sede as so', 'so.id', '=', 'a.sede_origen_id')
            ->leftJoin('cpu_facultad as fo', 'fo.id', '=', 'a.facultad_origen_id')
            ->leftJoin('cpu_sede as sd', 'sd.id', '=', 'a.sede_destino_id')
            ->leftJoin('cpu_facultad as fd', 'fd.id', '=', 'a.facultad_destino_id');

        if ($hasEstadoId) {
            $query->leftJoin('cpu_estados as e', 'e.id', '=', 'a.estado_id');
        }

        $selects = [
            'a.id',
            'a.numero_asignacion',
            'a.persona_destino_id',
            'a.cedula_destino',
            'a.nombre_destino',
            'a.tipo_flujo',
            'a.tipo_destino',
            'a.observacion',
            'a.created_at',
            'bo.bod_nombre as bodega_origen',
            'bd.bod_nombre as bodega_destino',
            'so.nombre_sede as sede_origen',
            'fo.fac_nombre as facultad_origen',
            'sd.nombre_sede as sede_destino',
            'fd.fac_nombre as facultad_destino',
        ];

        if ($hasEstadoId) {
            $selects[] = 'a.estado_id';
            $selects[] = DB::raw("COALESCE(e.estado, a.estado) as estado");
        } else {
            $selects[] = DB::raw('NULL as estado_id');
            $selects[] = 'a.estado';
        }

        $header = $query
            ->select($selects)
            ->where('a.id', $id)
            ->first();

        if (!$header) {
            return response()->json([
                'message' => 'No se encontró la asignación solicitada.',
            ], 404);
        }

        $detalles = DB::table('inventarios.asignaciones_detalles as d')
            ->select(
                'd.id',
                'd.producto_id',
                'd.producto_codigo',
                'd.producto_nombre',
                'd.cantidad',
                'd.stock_total_origen',
                'd.stock_util_origen',
                'd.proximo_vencimiento_vigente',
                'd.detalle_lotes'
            )
            ->where('d.asignacion_id', $id)
            ->orderBy('d.id')
            ->get()
            ->map(function ($row) {
                $row->detalle_lotes = !empty($row->detalle_lotes)
                    ? json_decode($row->detalle_lotes, true)
                    : [];

                return $row;
            })
            ->values();

        return response()->json([
            'header' => $header,
            'detalles' => $detalles,
        ]);
    }
}
