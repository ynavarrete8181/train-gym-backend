<?php

namespace App\Http\Controllers\Api\Seguridad;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seguridad\ProcesarCargaUsuariosRequest;
use App\Services\Seguridad\CargaMasivaUsuarioLoteService;
use App\Services\Seguridad\CargaMasivaUsuarioService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CargaMasivaUsuarioController extends Controller
{
    public function __construct(
        private readonly CargaMasivaUsuarioService $service,
        private readonly CargaMasivaUsuarioLoteService $loteService,
    ) {}

    public function plantilla(): StreamedResponse
    {
        return response()->streamDownload(fn () => $this->service->escribirPlantilla(), 'plantilla_usuarios.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function validar(ProcesarCargaUsuariosRequest $request): JsonResponse
    {
        $filas = $this->service->leerArchivo($request->file('archivo'));
        $resultados = $this->service->validar($filas);

        return ApiResponse::exito('Archivo validado correctamente.', [
            'resumen' => [
                'total' => count($resultados),
                'validos' => collect($resultados)->where('estado', 'valido')->count(),
                'errores' => collect($resultados)->where('estado', 'error')->count(),
            ],
            'filas' => $resultados,
            'filas_procesar' => $filas,
        ]);
    }

    public function procesar(ProcesarCargaUsuariosRequest $request): JsonResponse
    {
        $datos = $request->validated();
        $lote = $this->loteService->crear(
            $datos['filas'],
            (bool) ($datos['notificar_credenciales'] ?? false),
            $request->user()?->id,
        );

        return ApiResponse::exito('La carga masiva fue enviada a procesamiento en segundo plano.', $lote);
    }

    public function estado(int $id): JsonResponse
    {
        return ApiResponse::exito('Estado de carga consultado.', $this->loteService->estado($id));
    }

    public function recientes(Request $request): JsonResponse
    {
        return ApiResponse::exito('Cargas recientes consultadas.', $this->loteService->recientes($request->user()?->id));
    }
}
