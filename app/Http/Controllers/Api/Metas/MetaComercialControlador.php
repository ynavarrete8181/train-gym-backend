<?php

namespace App\Http\Controllers\Api\Metas;

use App\Http\Controllers\Controller;
use App\Services\Metas\MetaComercialServicio;
use App\Services\Metas\Exportaciones\MetasComercialesExportacionServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class MetaComercialControlador extends Controller
{
    public function __construct(
        private readonly MetaComercialServicio $servicio,
        private readonly MetasComercialesExportacionServicio $exportacion,
    ) {}

    public function index(Request $request)
    {
        $filtros = $request->validate([
            'busqueda' => 'nullable|string|max:160',
            'anio' => 'nullable|integer|min:2020|max:2100',
            'mes' => 'nullable|integer|min:1|max:12',
            'estado' => 'nullable|in:ACTIVA,INACTIVA',
            'sede_id' => 'nullable',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|in:5,10,25,50',
        ]);

        $resultado = $this->servicio->listar(
            $filtros,
            (int) $request->user()->id,
        );

        return ApiResponse::exito(
            'Metas comerciales consultadas.',
            $resultado['datos'],
            $resultado['meta'],
        );
    }

    public function show(Request $request, int $meta)
    {
        return ApiResponse::exito(
            'Meta comercial consultada.',
            $this->servicio->detalle($meta, (int) $request->user()->id),
        );
    }

    public function store(Request $request)
    {
        return ApiResponse::exito(
            'Meta comercial creada.',
            $this->servicio->guardar(
                $this->validar($request),
                (int) $request->user()->id,
            ),
        );
    }

    public function update(Request $request, int $meta)
    {
        return ApiResponse::exito(
            'Meta comercial actualizada.',
            $this->servicio->guardar(
                $this->validar($request),
                (int) $request->user()->id,
                $meta,
            ),
        );
    }

    public function excel(Request $request)
    {
        $filtros = $request->validate([
            'busqueda' => 'nullable|string|max:160',
            'anio' => 'nullable|integer|min:2020|max:2100',
            'mes' => 'nullable|integer|min:1|max:12',
            'estado' => 'nullable|in:ACTIVA,INACTIVA',
            'sede_id' => 'nullable',
        ]);

        return $this->exportacion->excel(
            $filtros,
            (int) $request->user()->id,
        );
    }

    public function catalogos(Request $request)
    {
        $datos = $request->validate([
            'sede_id' => 'nullable|integer|min:1',
        ]);

        return ApiResponse::exito(
            'Catálogos de metas consultados.',
            $this->servicio->catalogos(
                (int) $request->user()->id,
                isset($datos['sede_id']) ? (int) $datos['sede_id'] : null,
            ),
        );
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'sede_id' => 'required|integer|min:1',
            'anio' => 'required|integer|min:2020|max:2100',
            'mes' => 'required|integer|min:1|max:12',
            'meta_ventas' => 'nullable|numeric|min:0',
            'meta_cobros' => 'nullable|numeric|min:0',
            'meta_membresias_nuevas' => 'nullable|integer|min:0',
            'meta_renovaciones' => 'nullable|integer|min:0',
            'estado' => 'required|in:ACTIVA,INACTIVA',
            'observaciones' => 'nullable|string|max:1000',
            'responsables' => 'nullable|array',
            'responsables.*.usuario_id' => 'required|integer|min:1',
            'responsables.*.meta_ventas' => 'nullable|numeric|min:0',
            'responsables.*.meta_cobros' => 'nullable|numeric|min:0',
            'responsables.*.meta_membresias_nuevas' => 'nullable|integer|min:0',
            'responsables.*.meta_renovaciones' => 'nullable|integer|min:0',
        ]);
    }
}
