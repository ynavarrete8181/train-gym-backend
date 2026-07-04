<?php

namespace App\Http\Controllers\Inventarios;

use App\Http\Controllers\AuditoriaControllers;
use App\Http\Controllers\Controller;
use App\Http\Controllers\LogController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class EgresosControllers extends Controller
{
    protected $auditoriaController;
    protected $inventariosController;
    protected $logController;

    public function __construct()
    {
        $this->middleware('auth:api');
        $this->auditoriaController = new AuditoriaControllers();
        $this->inventariosController = new CpuInventariosController();
        $this->logController = new LogController();
    }

    public function consultarEgresos(Request $request)
    {
        try {
            $page = max((int) $request->query('page', 1), 1);
            $perPage = (int) $request->query('per_page', 10);
            $perPage = $perPage <= 0 ? 10 : min($perPage, 100);
            $offset = ($page - 1) * $perPage;

            $q = trim((string) $request->query('q', ''));
            $estado = trim((string) $request->query('estado', ''));

            $base = DB::table('inventarios.egresos as cee')
                ->leftJoin('inventarios.bodegas as b', 'cee.ee_id_bodega', '=', 'b.bod_id')
                ->leftJoin('cpu_sede as s', 'b.bod_id_sede', '=', 's.id')
                ->leftJoin('cpu_facultad as f', 'b.bod_id_facultad', '=', 'f.id')
                ->leftJoin('users as uf', 'cee.ee_id_funcionario', '=', 'uf.id')
                ->leftJoin('users as uu', 'cee.ee_id_user', '=', 'uu.id')
                ->leftJoin('cpu_estados as e', 'cee.ee_id_estado', '=', 'e.id')
                ->leftJoin('cpu_personas as p', 'cee.ee_id_paciente', '=', 'p.id');

            if ($estado !== '' && $estado !== '0') {
                $base->where('e.estado', '=', $estado);
            }

            if ($q !== '') {
                $like = "%{$q}%";
                $base->where(function ($w) use ($like) {
                    $w->whereRaw('CAST(cee.ee_id AS TEXT) ILIKE ?', [$like])
                        ->orWhereRaw("COALESCE(cee.ee_numero_egreso,'') ILIKE ?", [$like])
                        ->orWhereRaw("COALESCE(cee.ee_created_at::text,'') ILIKE ?", [$like])
                        ->orWhereRaw("COALESCE(cee.ee_cedula_paciente, p.cedula, '') ILIKE ?", [$like])
                        ->orWhereRaw("COALESCE(p.nombres,'') ILIKE ?", [$like])
                        ->orWhereRaw("COALESCE(uf.name,'') ILIKE ?", [$like])
                        ->orWhereRaw("COALESCE(uu.name,'') ILIKE ?", [$like])
                        ->orWhereRaw("COALESCE(e.estado,'') ILIKE ?", [$like])
                        ->orWhereRaw("COALESCE(cee.ee_observacion,'') ILIKE ?", [$like])
                        ->orWhereRaw("COALESCE(s.nombre_sede,'') ILIKE ?", [$like])
                        ->orWhereRaw("COALESCE(f.fac_nombre,'') ILIKE ?", [$like])
                        ->orWhereRaw("COALESCE(b.bod_nombre,'') ILIKE ?", [$like]);
                });
            }

            $total = (clone $base)->count();

            $rows = (clone $base)
                ->select(
                    'cee.ee_id',
                    'cee.ee_numero_egreso',
                    'cee.ee_id_bodega',
                    'b.bod_id as bodega_id',
                    'b.bod_nombre as bodega_nombre',
                    'b.bod_id_sede as bodega_id_sede',
                    'b.bod_id_facultad as bodega_id_facultad',
                    'b.bod_id_carrera as bodega_id_carrera',
                    'b.bod_estado as bodega_estado',
                    's.id as sede_id',
                    's.nombre_sede as sede_nombre',
                    'f.id as facultad_id',
                    'f.fac_nombre as facultad_nombre',
                    'cee.ee_id_funcionario',
                    'uf.name as nombre_funcionario',
                    'uf.email as email_funcionario',
                    'cee.ee_cedula_funcionario',
                    'cee.ee_id_paciente',
                    'cee.ee_cedula_paciente',
                    'p.nombres as nombre_paciente',
                    DB::raw('COALESCE(cee.ee_cedula_paciente, p.cedula) as cedula_paciente'),
                    'p.celular as celular_paciente',
                    'cee.ee_detalle',
                    'cee.ee_id_estado',
                    'e.estado as nombre_estado',
                    'cee.ee_id_user',
                    'uu.name as nombre_usuario',
                    'uu.email as email_usuario',
                    'cee.ee_created_at',
                    'cee.ee_updated_at',
                    'cee.ee_observacion',
                    'cee.ee_id_atencion_medicina_general'
                )
                ->orderBy('cee.ee_id', 'desc')
                ->limit($perPage)
                ->offset($offset)
                ->get();

            return response()->json([
                'data' => $rows,
                'total' => (int) $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => (int) ceil(((int) $total) / $perPage),
            ], 200);
        } catch (\Exception $e) {
            $this->logController->saveLog(
                'Nombre de Controlador: EgresosControllers, Nombre de Funcion: consultarEgresos()',
                'Error al consultar egresos: ' . $e->getMessage()
            );

            Log::error('Error al consultar egresos: ' . $e->getMessage());

            return response()->json([
                'error' => 'Error al consultar egresos: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getConsultarEgresosId($id)
    {
        try {
            $data = DB::table('inventarios.egresos as cee')
                ->select(
                    'cee.ee_id',
                    'cee.ee_numero_egreso',
                    'cee.ee_id_bodega',
                    'b.bod_id as bodega_id',
                    'b.bod_nombre as bodega_nombre',
                    'b.bod_id_sede as bodega_id_sede',
                    'b.bod_id_facultad as bodega_id_facultad',
                    'b.bod_id_carrera as bodega_id_carrera',
                    'b.bod_estado as bodega_estado',
                    's.id as sede_id',
                    's.nombre_sede as sede_nombre',
                    'f.id as facultad_id',
                    'f.fac_nombre as facultad_nombre',
                    'cee.ee_id_funcionario',
                    'uf.name as nombre_funcionario',
                    'uf.email as email_funcionario',
                    'cee.ee_cedula_funcionario',
                    'cee.ee_id_paciente',
                    'cee.ee_cedula_paciente',
                    'p.nombres as nombre_paciente',
                    DB::raw('COALESCE(cee.ee_cedula_paciente, p.cedula) as cedula_paciente'),
                    'p.celular as celular_paciente',
                    'cee.ee_detalle',
                    'cee.ee_id_estado',
                    'e.estado as nombre_estado',
                    'cee.ee_id_user',
                    'uu.name as nombre_usuario',
                    'uu.email as email_usuario',
                    'cee.ee_created_at',
                    'cee.ee_updated_at',
                    'cee.ee_observacion',
                    'cee.ee_id_atencion_medicina_general'
                )
                ->leftJoin('inventarios.bodegas as b', 'cee.ee_id_bodega', '=', 'b.bod_id')
                ->leftJoin('cpu_sede as s', 'b.bod_id_sede', '=', 's.id')
                ->leftJoin('cpu_facultad as f', 'b.bod_id_facultad', '=', 'f.id')
                ->leftJoin('users as uf', 'cee.ee_id_funcionario', '=', 'uf.id')
                ->leftJoin('users as uu', 'cee.ee_id_user', '=', 'uu.id')
                ->leftJoin('cpu_estados as e', 'cee.ee_id_estado', '=', 'e.id')
                ->leftJoin('cpu_personas as p', 'cee.ee_id_paciente', '=', 'p.id')
                ->where('cee.ee_id', '=', $id)
                ->first();

            if (!$data) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró el egreso solicitado.'
                ], 404);
            }

            return response()->json($data, 200);
        } catch (\Exception $e) {
            $this->logController->saveLog(
                'Nombre de Controlador: EgresosControllers, Nombre de Funcion: getConsultarEgresosId($id)',
                'Error al consultar egresos: ' . $e->getMessage()
            );

            Log::error('Error al consultar egresos: ' . $e->getMessage());

            return response()->json([
                'error' => 'Error al consultar egresos: ' . $e->getMessage()
            ], 500);
        }
    }

    public function guardarAtencionEgreso(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'idEgreso'      => 'required|integer',
            'estado'        => 'required|integer|in:2,5',
            'observacion'   => 'nullable|string',
            'select_bodega' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            $mensaje = json_encode($validator->errors());

            $this->logController->saveLog(
                'EgresosControllers -> guardarAtencionEgreso',
                'Validación fallida: ' . $mensaje
            );

            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors'  => $validator->errors()
            ], 422);
        }

        $auditoriaConsolidada = [];

        try {
            DB::beginTransaction();

            $egreso = DB::table('inventarios.egresos')
                ->where('ee_id', $request->idEgreso)
                ->lockForUpdate()
                ->first();

            if (!$egreso) {
                throw new \Exception("No existe el egreso {$request->idEgreso}.");
            }

            if (in_array((int) $egreso->ee_id_estado, [2, 5], true)) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => "La atención ya fue realizada (estado actual: {$egreso->ee_id_estado})."
                ], 409);
            }

            if (!$request->user() || !$request->user()->id) {
                throw new \Exception('No se pudo identificar el usuario autenticado.');
            }

            $nroEgreso = $egreso->ee_numero_egreso ?: $egreso->ee_id;
            $detalleEgreso = json_decode($egreso->ee_detalle, true);

            if (!is_array($detalleEgreso) || empty($detalleEgreso)) {
                throw new \Exception("El egreso no tiene detalle de productos.");
            }

            $usuarioId = (int) $request->user()->id;
            $nuevoEstado = (int) $request->estado;

            $yaTieneMovimientoEgreso = DB::table('inventarios.movimientos')
                ->where('mi_id_encabezado', $egreso->ee_id)
                ->where('mi_tipo_transaccion', 2)
                ->where('mi_id_estado', 30)
                ->exists();

            $yaTieneReversoNoAsistencia = DB::table('inventarios.movimientos')
                ->where('mi_id_encabezado', $egreso->ee_id)
                ->where('mi_tipo_transaccion', 1)
                ->where('mi_id_estado', 28)
                ->exists();

            $idBodega = (int) (
                $egreso->ee_id_bodega
                ?: $request->select_bodega
                ?: ($detalleEgreso[0]['idBodega'] ?? $detalleEgreso[0]['bod_id'] ?? 0)
            );

            if ($idBodega <= 0) {
                throw new \Exception("No se pudo determinar la bodega del egreso.");
            }

            if ($yaTieneMovimientoEgreso && (int) $egreso->ee_id_bodega > 0) {
                $idBodega = (int) $egreso->ee_id_bodega;
            }

            $detalleProcesado = $detalleEgreso;
            $descripcionMovimiento = null;

            if ($nuevoEstado === 2) {
                if (!$yaTieneMovimientoEgreso) {
                    $resultadoMovimiento = $this->inventariosController->guardarMovimientoInventario(
                        $detalleEgreso,
                        $idBodega,
                        'EGRESO',
                        30,
                        $usuarioId,
                        $egreso->ee_id,
                        "Egreso #{$nroEgreso} confirmado desde atención"
                    );

                    if (!empty($resultadoMovimiento['detalle_procesado'])) {
                        $detalleProcesado = $resultadoMovimiento['detalle_procesado'];
                    }

                    $descripcionMovimiento = "Se realizó el egreso de inventario del egreso #{$nroEgreso}.";
                } else {
                    $descripcionMovimiento = "El egreso #{$nroEgreso} ya tenía movimiento de salida; solo se confirmó la atención.";
                }
            }

            if ($nuevoEstado === 5) {
                if ($yaTieneMovimientoEgreso && !$yaTieneReversoNoAsistencia) {
                    $resultadoMovimiento = $this->inventariosController->guardarMovimientoInventario(
                        $detalleEgreso,
                        $idBodega,
                        'INGRESO',
                        28,
                        $usuarioId,
                        $egreso->ee_id,
                        "Reverso por no asistencia del egreso #{$nroEgreso}"
                    );

                    if (!empty($resultadoMovimiento['detalle_procesado'])) {
                        $detalleProcesado = $resultadoMovimiento['detalle_procesado'];
                    }

                    $descripcionMovimiento = "Se realizó reverso de inventario por no asistencia del egreso #{$nroEgreso}.";
                } elseif (!$yaTieneMovimientoEgreso) {
                    $descripcionMovimiento = "Se marcó No Asistió sin movimiento de inventario, porque el egreso aún no había descontado stock.";
                } else {
                    throw new \Exception("El egreso #{$nroEgreso} ya tiene reverso por no asistencia.");
                }
            }

            DB::table('inventarios.egresos')
                ->where('ee_id', $egreso->ee_id)
                ->update([
                    'ee_id_estado'   => $nuevoEstado,
                    'ee_observacion' => $request->observacion ?? $egreso->ee_observacion,
                    'ee_id_user'     => $usuarioId,
                    'ee_id_bodega'   => $idBodega,
                    'ee_detalle'     => json_encode($detalleProcesado, JSON_UNESCAPED_UNICODE),
                    'ee_updated_at'  => now(),
                ]);

            $auditoriaConsolidada[] = [
                'accion'          => 'UPDATE_ESTADO',
                'descripcion'     => "Se actualizó el egreso #{$nroEgreso} al estado {$nuevoEstado}. {$descripcionMovimiento}",
                'estado_anterior' => $egreso->ee_id_estado,
                'estado_nuevo'    => $nuevoEstado,
                'bodega'          => $idBodega,
            ];

            $this->auditoriaController->auditar(
                'inventarios.egresos',
                'guardarAtencionEgreso(Request $request)',
                json_encode([
                    'ee_id'           => $egreso->ee_id,
                    'estado_anterior' => $egreso->ee_id_estado,
                    'bodega_anterior' => $egreso->ee_id_bodega,
                ], JSON_UNESCAPED_UNICODE),
                json_encode([
                    'ee_id'        => $egreso->ee_id,
                    'estado_nuevo' => $nuevoEstado,
                    'bodega'       => $idBodega,
                    'observacion'  => $request->observacion,
                    'detalle'      => $detalleProcesado,
                ], JSON_UNESCAPED_UNICODE),
                'UPDATE',
                json_encode($auditoriaConsolidada, JSON_UNESCAPED_UNICODE)
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $nuevoEstado === 5
                    ? 'Atención guardada correctamente. Se procesó el no asistió.'
                    : 'Atención guardada correctamente. Se procesó el egreso.',
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            $this->logController->saveLog(
                'EgresosControllers -> guardarAtencionEgreso',
                'Error al guardar la atención: ' . $e->getMessage()
            );

            return response()->json([
                'success' => false,
                'message' => 'Error al guardar la atención: ' . $e->getMessage()
            ], 500);
        }
    }
}
