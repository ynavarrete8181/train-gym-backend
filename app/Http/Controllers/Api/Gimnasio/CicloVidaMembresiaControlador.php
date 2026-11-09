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
        private readonly \App\Services\Gimnasio\ContratoMembresiaServicio $contrato,
    ) {}

    public function actualizarSedes(Request $request, int $id)
    {
        $this->validarAlcance($request, $id);
        $datos = $request->validate([
            'sedes_habilitadas' => 'required|array|min:1',
            'sedes_habilitadas.*' => 'required|integer|distinct|exists:pgsql.institucional.sedes,id_sede',
            'motivo' => 'required|string|min:8|max:500',
        ]);
        return ApiResponse::exito('Sedes de la membresía actualizadas.',
            $this->contrato->actualizarSedes($id, $datos['sedes_habilitadas'], $datos['motivo'], (int) $request->user()->id));
    }

    public function actualizarConfiguracion(Request $request, int $id)
    {
        $this->validarAlcance($request, $id);
        $datos = $request->validate([
            'dias_gracia' => 'required|integer|min:0|max:365',
            'renovacion_automatica' => 'required|boolean',
            'motivo' => 'required|string|min:8|max:500',
        ]);
        return ApiResponse::exito('Configuración del contrato actualizada.', (array) $this->contrato->actualizarConfiguracion(
            $id, ['dias_gracia' => (int) $datos['dias_gracia'], 'renovacion_automatica' => (bool) $datos['renovacion_automatica']],
            $datos['motivo'], (int) $request->user()->id));
    }

    public function resumenPdf(Request $request, int $id)
    {
        $this->validarAlcance($request, $id);
        $resultado = app(\App\Services\Gimnasio\ResumenContratoPdfServicio::class)->generar($id, (int) $request->user()->id);
        return response($resultado['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$resultado['nombre'].'"',
        ]);
    }

    public function descuentos(Request $request, int $id)
    {
        return ApiResponse::exito('Descuentos de la membresía consultados.', $this->contrato->descuentos($id));
    }

    public function registrarDescuento(Request $request, int $id)
    {
        $this->validarAlcance($request, $id);
        return ApiResponse::exito('Descuento registrado.',
            (array) $this->contrato->registrarDescuento($id, $request->validate($this->reglasDescuento()), (int) $request->user()->id));
    }

    public function actualizarDescuento(Request $request, int $id, int $descuentoId)
    {
        $this->validarAlcance($request, $id);
        return ApiResponse::exito('Descuento actualizado.',
            (array) $this->contrato->actualizarDescuento($id, $descuentoId, $request->validate($this->reglasDescuento()), (int) $request->user()->id));
    }

    public function finalizarDescuento(Request $request, int $id, int $descuentoId)
    {
        $this->validarAlcance($request, $id);
        $datos = $request->validate(['motivo' => 'required|string|min:8|max:500']);
        return ApiResponse::exito('Descuento finalizado.',
            (array) $this->contrato->finalizarDescuento($id, $descuentoId, $datos['motivo'], (int) $request->user()->id));
    }

    private function reglasDescuento(): array
    {
        return [
            'tipo' => 'required|in:PORCENTAJE,FIJO',
            'valor' => 'required|numeric|gt:0',
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'nullable|date',
            'motivo' => 'required|string|min:8|max:250',
        ];
    }

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

    /** Reabrir un contrato cancelado es excepcional: solo administradores. */
    private function exigirAdministrador(Request $request): void
    {
        abort_unless($this->alcance->esGlobal((int) $request->user()->id), 403, 'Solo un administrador puede reabrir un contrato.');
    }

    public function mostrar(Request $request, int $id)
    {
        $this->validarAlcance($request, $id);
        $resumen = $this->servicio->resumen($id);
        $resumen['puede_reabrir'] = strtoupper((string) $resumen['estado']) === 'CANCELADA'
            && $this->alcance->esGlobal((int) $request->user()->id);

        return ApiResponse::exito('Ciclo de vida consultado.', $resumen);
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

    public function previsualizarEdicion(Request $request, int $id, int $suspensionId)
    {
        $this->validarAlcance($request, $id);
        $datos = $request->validate([
            'fecha_inicio' => 'required|date_format:Y-m-d',
            'fecha_fin' => 'required|date_format:Y-m-d|after_or_equal:fecha_inicio',
        ]);
        return ApiResponse::exito('Vista previa de actualización sin efectos sobre el contrato.',
            $this->servicio->previsualizarEdicion($id, $suspensionId, $datos));
    }

    public function actualizarProgramada(Request $request, int $id, int $suspensionId)
    {
        $this->validarAlcance($request, $id);
        $datos = $request->validate([
            'fecha_inicio' => 'required|date_format:Y-m-d',
            'fecha_fin' => 'required|date_format:Y-m-d|after_or_equal:fecha_inicio',
            'motivo' => 'required|string|min:8|max:1000',
            'motivo_codigo' => 'nullable|string|max:60',
        ]);
        return ApiResponse::exito('Fechas de congelación programada actualizadas.',
            $this->servicio->actualizarProgramada($id, $suspensionId, $datos,
                (int) $request->user()->id));
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

    public function previsualizarReactivacion(Request $request, int $id)
    {
        $this->validarAlcance($request, $id);
        return ApiResponse::exito('Vista previa de reactivación.',
            $this->servicio->previsualizarReactivacion($id));
    }

    public function reactivar(Request $request, int $id)
    {
        $this->validarAlcance($request, $id);
        $datos = $request->validate([
            'motivo' => 'required|string|min:8|max:1000',
            'motivo_codigo' => 'nullable|string|max:60',
        ]);
        return ApiResponse::exito('Membresía reactivada.', $this->servicio->reactivar(
            $id, $datos['motivo'], (int) $request->user()->id, null, $datos['motivo_codigo'] ?? null
        ));
    }

    public function previsualizarProrroga(Request $request, int $id, int $suspensionId)
    {
        $this->validarAlcance($request, $id);
        $datos = $request->validate(['fecha_fin' => 'required|date_format:Y-m-d']);
        return ApiResponse::exito('Vista previa de prórroga.',
            $this->servicio->previsualizarProrroga($id, $suspensionId, $datos['fecha_fin']));
    }

    public function prorrogar(Request $request, int $id, int $suspensionId)
    {
        $this->validarAlcance($request, $id);
        $datos = $request->validate([
            'fecha_fin' => 'required|date_format:Y-m-d',
            'motivo' => 'required|string|min:8|max:1000',
            'motivo_codigo' => 'nullable|string|max:60',
        ]);
        return ApiResponse::exito('Congelación prorrogada con trazabilidad.',
            $this->servicio->prorrogar($id, $suspensionId, $datos, (int) $request->user()->id));
    }

    public function previsualizarCancelacion(Request $request, int $id)
    {
        $this->validarAlcance($request, $id);
        return ApiResponse::exito('Vista previa de cancelación contractual.',
            $this->servicio->previsualizarCancelacion($id));
    }

    public function editarVigencia(Request $request, int $id)
    {
        $this->validarAlcance($request, $id);
        $datos = $request->validate([
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'required|date',
            'motivo' => 'required|string|min:8|max:1000',
        ]);
        return ApiResponse::exito('Vigencia del pase actualizada.',
            $this->servicio->editarVigencia($id, $datos, (int) $request->user()->id));
    }

    public function cancelarContrato(Request $request, int $id)
    {
        $this->validarAlcance($request, $id);
        $datos = $request->validate([
            'motivo' => 'required|string|min:8|max:1000',
            'motivo_codigo' => 'required|string|max:60',
        ]);
        return ApiResponse::exito('Contrato cancelado. El historial financiero se conserva.',
            $this->servicio->cancelarContrato($id, $datos, (int) $request->user()->id));
    }

    public function previsualizarReapertura(Request $request, int $id)
    {
        $this->validarAlcance($request, $id);
        $this->exigirAdministrador($request);
        return ApiResponse::exito('Vista previa de reapertura excepcional.',
            $this->servicio->previsualizarReapertura($id));
    }

    public function reabrirContrato(Request $request, int $id)
    {
        $this->validarAlcance($request, $id);
        $this->exigirAdministrador($request);
        $datos = $request->validate([
            'motivo' => 'required|string|min:8|max:1000',
            'motivo_codigo' => 'required|string|max:60',
        ]);
        return ApiResponse::exito('Contrato reabierto con trazabilidad completa.',
            $this->servicio->reabrirContrato($id, $datos, (int) $request->user()->id));
    }
}
