<?php

namespace App\Http\Controllers\Inventarios;

use App\Http\Controllers\AuditoriaControllers;
use App\Http\Controllers\Controller;
use App\Http\Controllers\LogController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class CategoriaActivosControllers extends Controller
{
    protected $auditoriaController;
    protected $logController;
    public function __construct()
    {
        $this->middleware('auth:api');
        $this->auditoriaController = new AuditoriaControllers();
        $this->logController = new LogController();
    }

    public function consultarCategoriaActivos()
    {
        try {
            $data = DB::select(
                'SELECT ca.ca_id,
                        ca.ca_descripcion,
                        ca.ca_created_at,
                        ca.ca_id_usuario,
                        ca.ca_updated_at,
                        ca.ca_parametros,
                        ca.ca_id_estado,
                        e.estado
                    FROM inventarios.categorias_activos ca
                        JOIN cpu_estados e ON ca.ca_id_estado = e.id
                    WHERE ca.ca_id_estado = 8
                    ORDER BY ca.ca_id DESC'
            );

            return response()->json([
                'success' => true,
                'data' => $data
            ], 200);
        } catch (\Exception $e) {
            $this->logController->saveLog(
                'Controlador: CategoriaActivosController, Función: listarCategoriasActivos()',
                'Error al consultar categorías de activos: ' . $e->getMessage()
            );

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las categorías de activos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function guardarCategoriaActivos(Request $request)
    {
        log::info('data', $request->all());
        $data = $request->all();
        $usuarioId = $request->input('id_usuario') ?? optional($request->user())->id;

        $validator = Validator::make($request->all(), [
            'txt-descripcion' => 'required|string|max:500',
            'select-estado' => 'required|integer'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }
        if (empty($usuarioId)) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo identificar el usuario que registra la categoría.'
            ], 422);
        }
        $id = DB::table('inventarios.categorias_activos')->insert([
            'ca_descripcion' => $data['txt-descripcion'],
            'ca_created_at' => now(),
            'ca_id_usuario' => $usuarioId,
            'ca_updated_at' => now(),
            'ca_parametros' => '',
            'ca_id_estado' => $data['select-estado'],
        ]);

        $id = DB::table('inventarios.categorias_activos')->latest('ca_id')->first()->ca_id;

        $descripcionAuditoria = 'Se guardo la categoria de activos: ' . $data['txt-descripcion'] . ' con ID: ' . $id;
        $this->auditoriaController->auditar('inventarios.categorias_activos', 'guardarCategoriaActivos()', '', json_encode($data), 'INSERT', $descripcionAuditoria);

        return response()->json(['success' => true, 'message' => 'Proveedor agregado correctamente']);
    }

    public function modificarCategoriaActivos(Request $request, $id)
    {
        log::info('data', $request->all());
        $data = $request->all();
        $usuarioId = $request->input('id_usuario') ?? optional($request->user())->id;
        $validator = Validator::make($request->all(), [
            'txt-descripcion' => 'required|string|max:500',
            'select-estado' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }
        if (empty($usuarioId)) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo identificar el usuario que modifica la categoría.'
            ], 422);
        }

        DB::table('inventarios.categorias_activos')
            ->where('ca_id', $id)
            ->update([
                'ca_descripcion' => $data['txt-descripcion'],
                'ca_id_usuario' => $usuarioId,
                'ca_updated_at' => now(),
                'ca_id_estado' => $data['select-estado'],
            ]);

        $descripcionAuditoria = 'Se modifico la categoria de activos: ' . $data['txt-descripcion'] . ' con ID: ' . $id;
        $this->auditoriaController->auditar('inventarios.categorias_activos', 'modificarCategoriaActivos()', '', json_encode($data), 'UPDATE', $descripcionAuditoria);
        return response()->json(['success' => true, 'message' => 'Categoria de Activos modificado correctamente']);
    }
}
