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
        $porPagina = (int) $request->input('per_page', 5);
        $pagina = (int) $request->input('page', 1);
        $query = DB::table('configuracion.estados_catalogo');

        if ($request->filled('busqueda')) {
            $texto = mb_strtolower((string) $request->busqueda);
            $query->where(function ($q) use ($texto): void {
                $q->whereRaw('LOWER(codigo) LIKE ?', ["%{$texto}%"])
                    ->orWhereRaw('LOWER(valor_interno) LIKE ?', ["%{$texto}%"])
                    ->orWhereRaw('LOWER(entidad) LIKE ?', ["%{$texto}%"])
                    ->orWhereRaw('LOWER(nombre) LIKE ?', ["%{$texto}%"])
                    ->orWhereRaw('LOWER(color) LIKE ?', ["%{$texto}%"]);
            });
        }

        $this->aplicarFiltroTexto($query, 'codigo', $request->input('codigo'));
        $this->aplicarFiltroTexto($query, 'entidad', $request->input('entidad'));
        $this->aplicarFiltroTexto($query, 'valor_interno', $request->input('valor_interno'));
        $this->aplicarFiltroTexto($query, 'nombre', $request->input('nombre'));
        $this->aplicarFiltroTexto($query, 'color', $request->input('color'));
        $this->aplicarFiltroBooleano($query, 'es_inicial', $request->input('inicial'));
        $this->aplicarFiltroBooleano($query, 'es_final', $request->input('final'));
        $this->aplicarFiltroBooleano($query, 'protegido_sistema', $request->input('protegido'));
        $this->aplicarFiltroBooleano($query, 'activo', $request->input('estado'));

        $paginado = $query
            ->orderBy('entidad')
            ->orderBy('orden')
            ->orderBy('nombre')
            ->paginate($porPagina, ['*'], 'page', $pagina);

        return ApiResponse::exito('Estados consultados.', $paginado->items(), [
            'pagina_actual' => $paginado->currentPage(),
            'por_pagina' => $paginado->perPage(),
            'total' => $paginado->total(),
            'ultima_pagina' => $paginado->lastPage(),
            'opciones_filtro' => $this->opcionesFiltro(),
        ]);
    }

    public function store(Request $request)
    {
        $datos = $this->validar($request);
        $datos['codigo'] = strtoupper($datos['codigo']);
        $datos['entidad'] = strtoupper($datos['entidad']);
        $datos['valor_interno'] = strtoupper($datos['valor_interno']);
        $this->validarValorUnico($datos['entidad'], $datos['valor_interno']);
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
        $datos['valor_interno'] = strtoupper($datos['valor_interno']);

        if ($actual->protegido_sistema) {
            unset($datos['codigo'], $datos['entidad'], $datos['valor_interno']);
        } else {
            $datos['codigo'] = strtoupper($datos['codigo']);
            $this->validarValorUnico($datos['entidad'], $datos['valor_interno'], $id);
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
            'valor_interno' => 'required|string|max:80',
            'nombre' => 'required|string|max:120',
            'descripcion' => 'nullable|string|max:255',
            'color' => 'required|string|in:default,info,success,warning,error',
            'orden' => 'required|integer|min:1|max:999',
            'activo' => 'boolean',
            'es_inicial' => 'boolean',
            'es_final' => 'boolean',
        ]);
    }

    private function validarValorUnico(string $entidad, string $valorInterno, ?int $ignorarId = null): void
    {
        $query = DB::table('configuracion.estados_catalogo')
            ->where('entidad', $entidad)
            ->where('valor_interno', $valorInterno);

        if ($ignorarId) {
            $query->where('id', '<>', $ignorarId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'valor_interno' => 'Ya existe un estado con ese valor interno para la entidad seleccionada.',
            ]);
        }
    }

    private function aplicarFiltroTexto($query, string $columna, mixed $valor): void
    {
        if ($valor === null || $valor === '' || $valor === []) {
            return;
        }

        if (is_array($valor)) {
            $valores = array_values(array_filter(array_map('strval', $valor), fn ($item) => $item !== ''));
            if ($valores !== []) {
                $query->whereIn($columna, $valores);
            }
            return;
        }

        $texto = mb_strtolower((string) $valor);
        $query->whereRaw("LOWER({$columna}) LIKE ?", ["%{$texto}%"]);
    }

    private function aplicarFiltroBooleano($query, string $columna, mixed $valor): void
    {
        if ($valor === null || $valor === '' || $valor === []) {
            return;
        }

        $valores = is_array($valor) ? $valor : [$valor];
        $booleanos = collect($valores)
            ->map(fn ($item) => filter_var($item, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE))
            ->filter(fn ($item) => $item !== null)
            ->values()
            ->all();

        if ($booleanos !== []) {
            $query->whereIn($columna, $booleanos);
        }
    }

    private function opcionesFiltro(): array
    {
        $base = DB::table('configuracion.estados_catalogo');

        return [
            'codigo' => (clone $base)->distinct()->orderBy('codigo')->pluck('codigo')->values(),
            'entidad' => (clone $base)->distinct()->orderBy('entidad')->pluck('entidad')->values(),
            'valor_interno' => (clone $base)->distinct()->orderBy('valor_interno')->pluck('valor_interno')->values(),
            'nombre' => (clone $base)->distinct()->orderBy('nombre')->pluck('nombre')->values(),
            'color' => (clone $base)->distinct()->orderBy('color')->pluck('color')->values(),
        ];
    }
}
