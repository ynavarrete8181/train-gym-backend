<?php

namespace App\Http\Controllers\Api\Geografia;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CatalogoGeograficoController extends Controller
{
    public function provincias()
    {
        return ApiResponse::exito('Provincias consultadas.', DB::table('geografia.provincias')
            ->where('activo', true)->orderBy('nombre')
            ->get(['codigo_dpa as codigo', 'nombre']));
    }

    public function cantones(Request $request)
    {
        $data = $request->validate(['provincia_codigo' => ['required', 'regex:/^[0-9]{2}$/']]);

        return ApiResponse::exito('Cantones consultados.', DB::table('geografia.cantones')
            ->where('activo', true)->where('provincia_codigo', $data['provincia_codigo'])
            ->orderBy('nombre')->get(['codigo_dpa as codigo', 'provincia_codigo', 'nombre']));
    }

    public function parroquias(Request $request)
    {
        $data = $request->validate(['canton_codigo' => ['required', 'regex:/^[0-9]{4}$/']]);

        return ApiResponse::exito('Parroquias consultadas.', DB::table('geografia.parroquias')
            ->where('activo', true)->where('canton_codigo', $data['canton_codigo'])
            ->orderBy('nombre')->get(['codigo_dpa as codigo', 'canton_codigo', 'nombre', 'tipo']));
    }
}
