<?php

namespace App\Http\Controllers\Inventarios;

use App\Http\Controllers\AuditoriaControllers;
use App\Http\Controllers\Controller;
use App\Http\Controllers\LogController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class CpuBodegasController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api');
        $this->auditoriaController = new AuditoriaControllers();
        $this->logController = new LogController();
    }

    public function getBodegas($idSede, $idFacultad)
    {
        $data = DB::select('SELECT * 
            FROM inventarios.bodegas 
            WHERE bod_id_sede = :idSede 
              AND bod_id_facultad = :idFacultad
              AND bod_estado = 8
            ORDER BY bod_nombre ASC', [
            'idSede' => $idSede,
            'idFacultad' => $idFacultad
        ]);
        return response()->json($data);
    }


    public function getIdBodegas($id_sede = null, $id_facultad = null, $id_bodega = null)
    {
        try {
            $id_sede = !empty($id_sede) ? (int) $id_sede : null;
            $id_facultad = !empty($id_facultad) ? (int) $id_facultad : null;
            $id_bodega = !empty($id_bodega) ? (int) $id_bodega : null;

            $query = DB::table('inventarios.stock_bodegas as sb')
                ->join('inventarios.bodegas as b', 'sb.sb_id_bodega', '=', 'b.bod_id')
                ->leftJoin('cpu_sede as s', 's.id', '=', 'b.bod_id_sede')
                ->leftJoin('cpu_facultad as f', 'f.id', '=', 'b.bod_id_facultad')
                ->join('inventarios.productos as i', 'i.id', '=', 'sb.sb_id_insumo')
                ->leftJoin('cpu_estados as e', 'e.id', '=', 'i.id_estado')
                ->select(
                    'sb.sb_id',
                    'sb.sb_cantidad as stock_bodega',
                    'sb.sb_stock_minimo',
                    'sb.sb_id_bodega',

                    'b.bod_id',
                    'b.bod_nombre as nombre_bodega',
                    'b.bod_id_sede',
                    'b.bod_id_facultad',

                    's.nombre_sede',
                    'f.fac_nombre as nombre_facultad',

                    'i.id as idInsumo',
                    'i.codigo',
                    'i.ins_descripcion',
                    'i.id_tipo_insumo',
                    'i.estado_insumo',
                    'i.id_estado',
                    'e.estado as estado_insumo_nombre',
                    'i.modo_adquirido',
                    'i.requiere_lote',
                    'i.requiere_vencimiento',
                    'i.unidad_medida',
                    'i.marca',
                    'i.modelo',
                    'i.serie'
                )
                ->where('i.id_estado', 8);

            if ($id_sede !== null) {
                $query->where('b.bod_id_sede', $id_sede);
            }

            if ($id_facultad !== null) {
                $query->where('b.bod_id_facultad', $id_facultad);
            }

            if ($id_bodega !== null) {
                $query->where('b.bod_id', $id_bodega);
            }

            $data = $query
                ->orderBy('i.ins_descripcion', 'asc')
                ->get();

            return response()->json($data, 200);
        } catch (\Exception $e) {
            $this->logController->saveLog(
                'Controlador: IngresosController, Función: getIdBodegas($id_sede, $id_facultad, $id_bodega)',
                'Error al consultar insumos por bodega: ' . $e->getMessage()
            );

            Log::error('Error al consultar insumos por bodega: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al consultar insumos por bodega: ' . $e->getMessage(),
            ], 500);
        }
    }


    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
