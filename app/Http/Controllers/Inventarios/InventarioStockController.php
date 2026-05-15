<?php

namespace App\Http\Controllers\Inventarios;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class InventarioStockController extends Controller
{
    public function index(int $id)
    {
        $stock = DB::table('train_gimnasio.producto_stock_sede as ps')
            ->join('train_gimnasio.sedes as s', 'ps.sede_id', '=', 's.id')
            ->where('ps.producto_id', $id)
            ->where('ps.estado', 1)
            ->select(
                'ps.id',
                'ps.sede_id',
                's.nombre as sede_nombre',
                'ps.stock_actual as cantidad',
                'ps.stock_minimo',
                'ps.ubicacion'
            )
            ->orderBy('s.nombre')
            ->get();

        return response()->json($stock);
    }
}
