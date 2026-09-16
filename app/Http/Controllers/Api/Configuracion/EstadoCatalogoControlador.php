<?php

namespace App\Http\Controllers\Api\Configuracion;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EstadoCatalogoControlador extends Controller
{
    public function index(Request $request)
    {
        $query = DB::table('configuracion.estados_catalogo');

        if ($request->filled('entidad')) {
            $query->where('entidad', strtoupper((string) $request->entidad));
        }

        if ($request->has('activo') && $request->activo !== '') {
            $query->where('activo', filter_var($request->activo, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('busqueda')) {
            $texto = mb_strtolower((string) $request->busqueda);
            $query->where(function ($q) use ($texto): void {
                $q->whereRaw('LOWER(codigo) LIKE ?', ["%{$texto}%"])
                    ->orWhereRaw('LOWER(entidad) LIKE ?', ["%{$texto}%"])
                    ->orWhereRaw('LOWER(nombre) LIKE ?', ["%{$texto}%"]);
            });
        }

        $items = $query->orderBy('entidad')->orderBy('orden')->orderBy('nombre')->get();
        $entidades = DB::table('configuracion.estados_catalogo')->distinct()->orderBy('entidad')->pluck('entidad')->values();

        return ApiResponse::exito('Estados consultados.', $items, ['entidades' => $entidades]);
    }

    public function store(Request $request)
    {
        $datos = $this->validar($request);
        $datos['codigo'] = strtoupper($datos['codigo']);
        $datos['entidad'] = strtoupper($datos['entidad']);
        $datos['protegido_sistema'] = false;
        $datos['created_at'] = now();
        $datos['updated_at'] = now();

        $id = DB::table('configuracion.estados_catalogo')->insertGetId($datos);
        return ApiResponse::exito('Estado creado correctamente.', (array) DB::table('configuracion.estados_catalogo')->where('id', $id)->first(), [], 201);
    }

    public function update(Request $request, int $id)
    {
        $actual = DB::table('configuracion.estados_catalogo')->where('id', $id)->first();
        if (! $actual) {
            return response()->json(['mensaje' => 'Estado no encontrado.'], 404);
        }

        $datos = $this->validar($request, $id);
        $datos['entidad'] = strtoupper($datos['entidad']);

        if ($actual->protegido_sistema) {
            unset($datos['codigo'], $datos['entidad']);
        } else {
            $datos['codigo'] = strtoupper($datos['codigo']);
        }

        $datos['updated_at'] = now();
        DB::table('configuracion.estados_catalogo')->where('id', $id)->update($datos);

        return ApiResponse::exito('Estado actualizado correctamente.', (array) DB::table('configuracion.estados_catalogo')->where('id', $id)->first());
    }

    public function desactivar(int $id)
    {
        $actual = DB::table('configuracion.estados_catalogo')->where('id', $id)->first();
        if (! $actual) {
            return response()->json(['mensaje' => 'Estado no encontrado.'], 404);
        }

        if ($actual->protegido_sistema) {
            throw ValidationException::withMessages([
                'estado' => 'Los estados protegidos del sistema no pueden desactivarse porque participan en reglas de negocio.',
            ]);
        }

        DB::table('configuracion.estados_catalogo')->where('id', $id)->update(['activo' => false, 'updated_at' => now()]);
        return ApiResponse::exito('Estado desactivado correctamente.');
    }

    private function validar(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'codigo' => ['required', 'string', 'max:80', Rule::unique('configuracion.estados_catalogo', 'codigo')->ignore($id)],
            'entidad' => 'required|string|max:60',
            'nombre' => 'required|string|max:120',
            'descripcion' => 'nullable|string|max:255',
            'color' => 'required|string|in:default,info,success,warning,error',
            'orden' => 'required|integer|min:1|max:999',
            'activo' => 'boolean',
            'es_inicial' => 'boolean',
            'es_final' => 'boolean',
        ]);
    }
}
