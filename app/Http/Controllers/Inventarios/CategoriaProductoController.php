<?php

namespace App\Http\Controllers\Inventarios;

use App\Http\Controllers\Controller;
use App\Services\Inventarios\ProductoService;

class CategoriaProductoController extends Controller
{
    public function __construct(private ProductoService $productoService)
    {
    }

    public function index()
    {
        return response()->json($this->productoService->categories());
    }
}
