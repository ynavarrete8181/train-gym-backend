<?php

namespace App\Http\Controllers\Api\Gimnasio;

use App\Http\Controllers\Controller;
use App\Services\Gimnasio\CicloVidaMembresiaServicio;
use App\Services\Seguridad\AlcanceOperativoService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CicloVidaMembresiaControlador extends Controller
{
    public function __construct(
        private readonly CicloVidaMembresiaServicio $servicio,
        private readonly AlcanceOperativoService $alcance,
    ) {}

    private function validarAlcance(Request $request, int $id): void
    {
        $usuarioId = (int) $request->user()->id;
        $sedeId = $this->alcance->sedeDeMembresia($id);

        // La ficha del deportista puede consultarse sin la misma autorización
        // exigida para realizar ajustes financieros en una sede.
        if (! $sedeId) {
            throw ValidationException::withMessages([
                'sede_id' => 'La membresía no tiene sede principal. Asigna una sede al contrato antes de continuar.',
            ]);
        }

        if (! in_array($sedeId, $this->alcance->sedesPermitidas($usuarioId), true)) {
            $sedeNombre = DB::table('institucional.sedes')
                ->where('id_sede', $sedeId)->value('nombre');
            throw ValidationException::withMessages([
                'sede_id' => 'No tienes acceso operativo a la sede '
                    . ($sedeNombre ?: 'de este contrato')
                    . '. Solicita que se habilite esa sede en tu contexto de usuario.',
            ]);
        }

        $this->alcance->validarSede($usuarioId, $sedeId);
    }

    public function mostrar(Request $request, int $id)
    {
        $this->validarAlcance($request, $id);
        return ApiResponse::exito('Ciclo de vida consultado.', $this->servicio->resumen($id));
    }

    public function previsualizar(Request $request, int $id)
    {
        $this->validarAlcance($request, $id);
        $datos = $request->validate([
            'tipo' => 'required|in:TIEMPO,FACTURACION',
            'fecha_inicio' => 'required|date_format:Y-m-d',
            'fecha_fin' => 'required|date_format:Y-m-d|after_or_equal:fecha_inicio',
        ]);
        return ApiResponse::exito('Vista previa sin efectos sobre el contrato.', $this->servicio->previsualizar($id, $datos));
    }

    public function programar(Request $request, int $id)
    {
        $this->validarAlcance($request, $id);
        $datos = $request->validate([
            'tipo' => 'required|in:TIEMPO',
            'fecha_inicio' => 'required|date_format:Y-m-d',
            'fecha_fin' => 'required|date_format:Y-m-d|after_or_equal:fecha_inicio',
            'motivo' => 'required|string|min:8|max:1000',
            'motivo_codigo' => 'nullable|string|max:60',
        ]);
        return ApiResponse::exito('Vacaciones programadas para su fecha de inicio.',
            $this->servicio->programarTiempo($id, $datos, (int) $request->user()->id));
    }

    public function cancelarProgramada(Request $request, int $id, int $suspensionId)
    {
        $this->validarAlcance($request, $id);
        $datos = $request->validate(['motivo' => 'required|string|min:8|max:1000']);
        return ApiResponse::exito('Programación de vacaciones cancelada.',
            $this->servicio->cancelarProgramada($id, $suspensionId,
                $datos['motivo'], (int) $request->user()->id));
    }

    public function congelar(Request $request, int $id)
    {
        $this->validarAlcance($request, $id);
        $datos = $request->validate([
            'tipo' => 'required|in:TIEMPO,FACTURACION',
            'fecha_inicio' => 'required|date_format:Y-m-d',
            'fecha_fin' => 'required|date_format:Y-m-d|after_or_equal:fecha_inicio',
            'motivo' => 'required|string|min:8|max:1000',
            'motivo_codigo' => 'nullable|string|max:60',
        ]);
        return ApiResponse::exito('Congelación registrada.', $this->servicio->congelar($id, $datos, (int) $request->user()->id));
    }

    public function reactivar(Request $request, int $id)
    {
        $this->validarAlcance($request, $id);
        $datos = $request->validate(['motivo' => 'required|string|min:8|max:1000']);
        return ApiResponse::exito('Membresía reactivada.', $this->servicio->reactivar($id, $datos['motivo'], (int) $request->user()->id));
    }
}
