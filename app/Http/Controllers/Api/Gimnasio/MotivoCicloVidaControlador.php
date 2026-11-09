<?php

namespace App\Http\Controllers\Api\Gimnasio;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class MotivoCicloVidaControlador extends Controller
{
    private const OPERACIONES = ['CONGELAR', 'REACTIVAR', 'CERRAR', 'REABRIR'];

    public function index(Request $request)
    {
        $operacion = mb_strtoupper(trim((string) $request->query('operacion', '')));
        $consulta = DB::table('membresias.motivos_ciclo_vida');

        if ($operacion !== '') {
            if (! in_array($operacion, self::OPERACIONES, true)) {
                throw ValidationException::withMessages(['operacion' => 'Operación no reconocida.']);
            }
            $consulta->where('operacion', $operacion);
        }

        if ($request->boolean('solo_activos')) {
            $consulta->where('activo', true);
        }

        return ApiResponse::exito('Motivos del ciclo de vida consultados.',
            $consulta->orderBy('operacion')->orderBy('orden')->orderBy('nombre')->get());
    }

    public function store(Request $request)
    {
        $datos = $this->validar($request);
        $id = DB::table('membresias.motivos_ciclo_vida')->insertGetId(
            $datos + ['created_at' => now(), 'updated_at' => now()]
        );

        return ApiResponse::exito('Motivo registrado.',
            DB::table('membresias.motivos_ciclo_vida')->where('id', $id)->first(), codigo: 201);
    }

    public function update(Request $request, int $id)
    {
        $actual = DB::table('membresias.motivos_ciclo_vida')->where('id', $id)->first();
        if (! $actual) {
            throw ValidationException::withMessages(['id' => 'Motivo no encontrado.']);
        }
        $datos = $this->validar($request, $id);
        // La operación y el código son identidad histórica. Un motivo en uso
        // puede desactivarse, pero no cambiar a otra operación.
        if ($actual->operacion !== $datos['operacion'] || $actual->codigo !== $datos['codigo']) {
            throw ValidationException::withMessages([
                'codigo' => 'No se puede cambiar el código ni la operación de un motivo existente; desactívalo y crea otro.',
            ]);
        }
        DB::table('membresias.motivos_ciclo_vida')->where('id', $id)->update($datos + ['updated_at' => now()]);

        return ApiResponse::exito('Motivo actualizado.',
            DB::table('membresias.motivos_ciclo_vida')->where('id', $id)->first());
    }

    private function validar(Request $request, ?int $id = null): array
    {
        $request->merge([
            'codigo' => mb_strtoupper(trim((string) $request->input('codigo', ''))),
            'operacion' => mb_strtoupper(trim((string) $request->input('operacion', ''))),
        ]);
        return $request->validate([
            'codigo' => ['required', 'string', 'max:60', 'regex:/^[A-Z][A-Z0-9_]*$/',
                Rule::unique('pgsql.membresias.motivos_ciclo_vida', 'codigo')->ignore($id)],
            'nombre' => ['required', 'string', 'max:160'],
            'operacion' => ['required', Rule::in(self::OPERACIONES)],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'orden' => ['required', 'integer', 'min:1', 'max:999'],
            'activo' => ['required', 'boolean'],
        ]);
    }
}
