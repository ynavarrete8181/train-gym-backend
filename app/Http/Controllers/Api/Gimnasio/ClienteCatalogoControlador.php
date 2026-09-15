<?php

namespace App\Http\Controllers\Api\Gimnasio;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\DB;

class ClienteCatalogoControlador extends Controller
{
    public function usuariosDeportistasDisponibles()
    {
        $usuarios = DB::table('seguridad.users')
            ->join('seguridad.cpu_userrole', 'seguridad.cpu_userrole.id_userrole', '=', 'seguridad.users.usr_tipo')
            ->where('seguridad.cpu_userrole.role', 'DEPORTISTA')
            ->where('seguridad.users.activo', true)
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
