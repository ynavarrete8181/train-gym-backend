<?php

namespace App\Http\Controllers\Logs;

use App\Http\Controllers\Controller;
use App\Queries\Logs\LogSistemaQuery;
use Illuminate\Http\Request;

class LogSistemaController extends Controller
{
    public function __construct(private LogSistemaQuery $query)
    {
    }

    public function eventos(Request $request)
    {
        $filters = $this->validarFiltrosBase($request);
        return response()->json($this->query->eventos($filters));
    }

    public function resumen(Request $request)
    {
        $filters = $this->validarFiltrosBase($request, false);
        return response()->json($this->query->resumen($filters));
    }

    public function excepciones(Request $request)
    {
        $filters = $request->validate([
            'request_id' => ['nullable', 'string', 'max:80'],
            'modulo' => ['nullable', 'string', 'max:80'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return response()->json($this->query->excepciones($filters));
    }

    public function integraciones(Request $request)
    {
        $filters = $request->validate([
            'request_id' => ['nullable', 'string', 'max:80'],
            'proveedor' => ['nullable', 'string', 'max:120'],
            'tipo' => ['nullable', 'string', 'max:40'],
            'direccion' => ['nullable', 'string', 'max:20'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return response()->json($this->query->integraciones($filters));
    }

    private function validarFiltrosBase(Request $request, bool $withLimit = true): array
    {
        $rules = [
            'nivel' => ['nullable', 'string', 'max:20'],
            'canal' => ['nullable', 'string', 'max:40'],
            'modulo' => ['nullable', 'string', 'max:80'],
            'accion' => ['nullable', 'string', 'max:120'],
            'request_id' => ['nullable', 'string', 'max:80'],
            'sede_id' => ['nullable', 'integer'],
            'usuario_id' => ['nullable', 'integer'],
            'cedula' => ['nullable', 'string', 'max:30'],
            'buscar' => ['nullable', 'string', 'max:150'],
            'fecha_desde' => ['nullable', 'date'],
            'fecha_hasta' => ['nullable', 'date'],
        ];

        if ($withLimit) {
            $rules['limit'] = ['nullable', 'integer', 'min:1', 'max:300'];
        }

        return $request->validate($rules);
    }
}
