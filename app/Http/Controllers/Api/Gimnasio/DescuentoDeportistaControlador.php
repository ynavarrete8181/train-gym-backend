<?php

namespace App\Http\Controllers\Api\Gimnasio;

use App\Http\Controllers\Controller;
use App\Services\Gimnasio\DescuentoDeportistaServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DescuentoDeportistaControlador extends Controller
{
    public function __construct(private readonly DescuentoDeportistaServicio $servicio) {}

    public function index(Request $request)
    {
        $request->validate(['deportista_id' => 'required|integer|exists:pgsql.clientes.deportistas,id']);
        $datos = DB::table('membresias.descuentos_deportistas')
            ->where('deportista_id', (int) $request->input('deportista_id'))
            ->orderByDesc('created_at')->get();
        return ApiResponse::exito('Beneficios consultados.', $datos);
    }

    public function store(Request $request)
    {
        $datos = $request->validate($this->reglas(true));
        $descuento = $this->servicio->crear($datos, (int) $request->user()->id);
        return ApiResponse::exito('Beneficio creado.', $descuento, [], 201);
    }

    public function update(Request $request, int $id)
    {
        $datos = $request->validate($this->reglas(false));
        $descuento = $this->servicio->actualizar($id, $datos, (int) $request->user()->id);
        return ApiResponse::exito('Beneficio actualizado.', $descuento);
    }

    private function reglas(bool $crear): array
    {
        $requerido = $crear ? 'required' : 'sometimes';
        return [
            'deportista_id' => [$requerido, 'integer', 'exists:pgsql.clientes.deportistas,id'],
            'plan_id' => ['nullable', 'integer', 'exists:pgsql.membresias.planes,id'],
            'modalidad_id' => ['nullable', 'integer', 'exists:pgsql.membresias.plan_modalidades,id'],
            'tipo' => [$requerido, Rule::in(['PORCENTAJE', 'FIJO'])],
            'valor' => [$requerido, 'numeric', 'min:0'],
            'fecha_inicio' => [$requerido, 'date'],
            'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'motivo' => [$requerido, 'string', 'min:3', 'max:250'],
            'activo' => ['sometimes', 'boolean'],
        ];
    }
}
