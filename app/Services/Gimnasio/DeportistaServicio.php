<?php

namespace App\Services\Gimnasio;

use App\Services\Concerns\RegistraAuditoria;
use Illuminate\Support\Facades\DB;

class DeportistaServicio
{
    use RegistraAuditoria;

    public function crear(array $datos)
    {
        $datos['created_at'] = now();
        $datos['updated_at'] = now();

        $id = DB::table('gimnasio.deportistas')->insertGetId($datos);
        $cliente = $this->obtenerDeportistaConRelaciones($id);
        $this->auditar('gimnasio', 'CREAR', 'gimnasio.deportistas', $id, null, $cliente);
        return $cliente;
    }

    public function actualizar(int $id, array $datos)
    {
        $antes = DB::table('gimnasio.deportistas')->where('id', $id)->first();
        $datos['updated_at'] = now();

        DB::table('gimnasio.deportistas')->where('id', $id)->update($datos);
        $cliente = $this->obtenerDeportistaConRelaciones($id);
        $this->auditar('gimnasio', 'ACTUALIZAR', 'gimnasio.deportistas', $id, $antes, $cliente);
        return $cliente;
    }

    public function obtenerDeportistaConRelaciones(int $id)
    {
        $deportista = DB::table('gimnasio.deportistas')
            ->leftJoin('seguridad.users', 'gimnasio.deportistas.usuario_id', '=', 'seguridad.users.id')
            ->leftJoin('institucional.sedes', 'gimnasio.deportistas.sede_principal_id', '=', 'institucional.sedes.id_sede')
            ->select(
                'gimnasio.deportistas.*',
                'seguridad.users.name as usuario_nombre',
                'seguridad.users.email as usuario_email',
                'institucional.sedes.nombre as sede_nombre'
            )
            ->where('gimnasio.deportistas.id', $id)
            ->first();

        return $deportista;
    }
}
