<?php

namespace App\Http\Controllers\Api\Gimnasio;

use App\Http\Controllers\Controller;
use App\Services\Seguridad\PermisoService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClienteCatalogoControlador extends Controller
{
    public function __construct(private readonly PermisoService $permisoService) {}

    public function capacidades(Request $request)
    {
        $usuario = $request->user();

        $tiene = fn (string ...$codigos): bool => $this->permisoService->usuarioTieneAlgunaFuncion($usuario, $codigos);

        return ApiResponse::exito('Capacidades de ficha de clientes', [
            'datos' => $tiene('GIMNASIO-DEPORTISTAS'),
            'membresia' => $tiene('GIMNASIO-MEMBRESIAS'),
            'entrenador' => $tiene('GIMNASIO-ENTRENADORES'),
            'progreso' => $tiene('ENTRENAMIENTO-PROGRESO'),
            'entrenamiento' => $tiene('ENTRENAMIENTO-PLANES', 'ENTRENAMIENTO-RUTINAS', 'ENTRENAMIENTO-RM'),
        ]);
    }

    public function usuariosDeportistasDisponibles()
    {
        $usuarios = DB::table('seguridad.users')
            ->join('seguridad.cpu_userrole', 'seguridad.cpu_userrole.id_userrole', '=', 'seguridad.users.usr_tipo')
            ->where('seguridad.cpu_userrole.role', 'DEPORTISTA')
            ->where('seguridad.users.usr_estado', 1)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('gimnasio.deportistas')
                    ->whereColumn('gimnasio.deportistas.usuario_id', 'seguridad.users.id');
            })
            ->orderBy('seguridad.users.name')
            ->select([
                'seguridad.users.id',
                'seguridad.users.name',
                'seguridad.users.nombres',
                'seguridad.users.apellidos',
                'seguridad.users.email',
                'seguridad.users.cedula',
            ])
            ->get();

        return ApiResponse::exito('Usuarios deportistas disponibles', $usuarios->all());
    }
}
