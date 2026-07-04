<?php

namespace App\Http\Controllers\Inventarios;

use App\Http\Controllers\AuditoriaControllers;
use App\Http\Controllers\Controller;
use App\Http\Controllers\LogController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

class ProveedoresControllers extends Controller
{
    protected $auditoriaController;
    protected $logController;

    public function __construct()
    {
        $this->middleware('auth:api');
        $this->auditoriaController = new AuditoriaControllers();
    }

    public function consultarProveedores()
    {
        try {
            $data = DB::select('SELECT p.prov_id,
                p.prov_ruc,
                p.prov_nombre,
                p.prov_direccion,
                p.prov_telefono,
                p.prov_correo,
                p.prov_id_usuario,
                p.prov_estado,
                e.estado,
                p.created_at,
                p.updated_at
            FROM inventarios.proveedores p
                JOIN cpu_estados e ON p.prov_estado = e.id
            WHERE p.prov_estado = 8
            ORDER BY p.prov_id DESC;');

            return response()->json($data, 200);
        } catch (\Exception $e) {

            // Guardar log con tu LogController
            $this->logController->saveLog(
                'Controlador: ProveedoresController, Función: consultarProveedores()',
                'Error al consultar proveedores: ' . $e->getMessage()
            );

            return response()->json([
                'message' => 'Ocurrió un error al consultar los proveedores.',
            ], 500);
        }
    }


    public function guardarProveedores(Request $request)
    {
        log::info('data', $request->all());
        $data = $request->all();

        $validator = Validator::make($request->all(), [
            'txt-ruc' => 'required|string|max:500',
            'txt-nombre' => 'required|string|max:500',
            'txt-direccion' => 'required|string|max:500',
            'txt-telefono' => 'required|string|max:500',
            'txt-correo' => 'required|string|max:500',
            'select-estado' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }
        $id = DB::table('inventarios.proveedores')->insert([
            'prov_ruc' => $data['txt-ruc'],
            'prov_nombre' => $data['txt-nombre'],
            'prov_direccion' => $data['txt-direccion'],
            'prov_telefono' => $data['txt-telefono'],
            'prov_correo' => $data['txt-correo'],
            'prov_id_usuario' => $data['id_usuario'],
            'created_at' => now(),
            'updated_at' => now(),
            'prov_estado' => $data['select-estado']
        ]);

        $id = DB::table('inventarios.proveedores')->latest('prov_id')->first()->prov_id;

        $descripcionAuditoria = 'Se guardo el proveedor: ' . $data['txt-nombre'] . ' con RUC: ' . $data['txt-ruc'] . ' y ID: ' . $id;
        $this->auditoriaController->auditar('inventarios.proveedores', 'guardarProveedores()', '', json_encode($data), 'INSERT', $descripcionAuditoria);

        return response()->json(['success' => true, 'message' => 'Proveedor agregado correctamente']);
    }

    public function modificarProveedores(Request $request, $id)
    {
        log::info('data', $request->all());
        $data = $request->all();
        $validator = Validator::make($request->all(), [
            'txt-ruc' => 'required|string|max:500',
            'txt-nombre' => 'required|string|max:500',
            'txt-direccion' => 'required|string|max:500',
            'txt-telefono' => 'required|string|max:500',
            'txt-correo' => 'required|string|max:500',
            'select-estado' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $usuario = $request->user()->name;
        $ip = $request->ip();
        $nombreequipo = gethostbyaddr($ip);
        $fecha = now();
        $nombreAud_descripcion = $data['txt-nombre'];

        DB::table('inventarios.proveedores')
            ->where('prov_id', $id)
            ->update([
                'prov_ruc' => $data['txt-ruc'],
                'prov_nombre' => $data['txt-nombre'],
                'prov_direccion' => $data['txt-direccion'],
                'prov_telefono' => $data['txt-telefono'],
                'prov_correo' => $data['txt-correo'],
                'prov_id_usuario' => $data['id_usuario'],
                'updated_at' => now(),
                'prov_estado' => $data['select-estado']
            ]);

        $descripcionAuditoria = 'Se modifico el proveedor: ' . $data['txt-nombre'] . ' con RUC: ' . $data['txt-ruc'] . ' y ID: ' . $id;
        $this->auditoriaController->auditar('inventarios.proveedores', 'modificarProveedores()', '', json_encode($data), 'UPDATE', $descripcionAuditoria);;

        return response()->json(['success' => true, 'message' => 'Proveedor modificado correctamente']);
    }
}
