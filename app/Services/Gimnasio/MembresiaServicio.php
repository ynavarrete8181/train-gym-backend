<?php

namespace App\Services\Gimnasio;

use App\Services\Concerns\RegistraAuditoria;
use Illuminate\Support\Facades\DB;

class MembresiaServicio
{
    use RegistraAuditoria;

    public function crear(array $datos)
    {
        $datos['precio_aplicado'] = $this->resolverPrecio((int) $datos['plan_id'], $datos['sede_id'] ?? null);
        $datos['created_at'] = now();
        $datos['updated_at'] = now();

        $id = DB::table('gimnasio.membresias')->insertGetId($datos);
        $membresia = $this->obtenerMembresiaConRelaciones($id);
        $this->auditar('gimnasio', 'CREAR', 'gimnasio.membresias', $id, null, $membresia);
        return $membresia;
    }

    public function actualizar(int $id, array $datos)
    {
        $antes = DB::table('gimnasio.membresias')->where('id', $id)->first();
        $datos['updated_at'] = now();

        DB::table('gimnasio.membresias')->where('id', $id)->update($datos);
        $membresia = $this->obtenerMembresiaConRelaciones($id);
        $this->auditar('gimnasio', 'ACTUALIZAR', 'gimnasio.membresias', $id, $antes, $membresia);
        return $membresia;
    }

    public function eliminar(int $id): void
    {
        $antes = $this->obtenerMembresiaConRelaciones($id);
        DB::table('gimnasio.membresias')->where('id', $id)->delete();
        $this->auditar('gimnasio', 'ELIMINAR', 'gimnasio.membresias', $id, $antes, null);
    }

    public function obtenerMembresiaConRelaciones(int $id)
    {
        $membresia = DB::table('gimnasio.membresias')
            ->join('gimnasio.deportistas', 'gimnasio.membresias.deportista_id', '=', 'gimnasio.deportistas.id')
            ->join('seguridad.users', 'gimnasio.deportistas.usuario_id', '=', 'seguridad.users.id')
            ->join('gimnasio.planes', 'gimnasio.membresias.plan_id', '=', 'gimnasio.planes.id')
            ->leftJoin('institucional.sedes', 'gimnasio.membresias.sede_id', '=', 'institucional.sedes.id_sede')
            ->select(
                'gimnasio.membresias.*',
                'gimnasio.deportistas.codigo_deportista',
                'seguridad.users.name as deportista_nombre',
                'seguridad.users.email as deportista_email',
                'gimnasio.planes.nombre as plan_nombre',
                'institucional.sedes.nombre as sede_nombre'
            )
            ->where('gimnasio.membresias.id', $id)
            ->first();

        return $membresia;
    }

    /**
     * Resuelve el precio de un plan para una sede: usa el precio específico de esa
     * sede en gimnasio.plan_precios_sede si existe y está activo; si no, cae al
     * precio_base del plan. Se calcula siempre en el servidor (nunca se confía en
     * un precio_aplicado enviado desde el frontend).
     */
    private function resolverPrecio(int $planId, mixed $sedeId): float
    {
        if ($sedeId) {
            $precioSede = DB::table('gimnasio.plan_precios_sede')
                ->where('plan_id', $planId)
                ->where('sede_id', $sedeId)
                ->where('activo', true)
                ->value('precio');

            if ($precioSede !== null) {
                return (float) $precioSede;
            }
        }

        return (float) DB::table('gimnasio.planes')->where('id', $planId)->value('precio_base');
    }
}
