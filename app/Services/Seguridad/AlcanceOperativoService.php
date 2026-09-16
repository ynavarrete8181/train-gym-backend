<?php

namespace App\Services\Seguridad;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AlcanceOperativoService
{
    public function esGlobal(?int $usuarioId): bool
    {
        if (! $usuarioId) {
            return false;
        }

        return DB::table('seguridad.users as u')
            ->join('seguridad.cpu_userrole as r', 'r.id_userrole', '=', 'u.usr_tipo')
            ->where('u.id', $usuarioId)
            ->where('u.usr_estado', 1)
            ->where('r.activo', true)
            ->where('r.role', 'SUPERADMINISTRADOR')
            ->exists();
    }

    public function sedesPermitidas(?int $usuarioId, ?string $capacidad = null): array
    {
        $query = DB::table('institucional.sedes as s')
            ->where('s.activo', true)
            ->select('s.id_sede');

        if (! $this->esGlobal($usuarioId)) {
            $query->whereExists(function ($sub) use ($usuarioId): void {
                $sub->selectRaw('1')
                    ->from('institucional.usuario_contexto as uc')
                    ->join('institucional.contextos as c', 'c.id_contexto', '=', 'uc.id_contexto')
                    ->whereColumn('c.id_sede', 's.id_sede')
                    ->where('uc.id_usuario', $usuarioId)
                    ->where('uc.activo', true)
                    ->where('c.activo', true);
            });
        }

        if ($capacidad) {
            $this->validarCapacidad($capacidad);
            $query->where("s.{$capacidad}", true);
        }

        return $query->orderBy('s.id_sede')->pluck('s.id_sede')->map(fn ($id) => (int) $id)->all();
    }

    public function aplicarSedes(Builder $query, string $columnaSede, ?int $usuarioId, ?string $capacidad = null): void
    {
        $sedes = $this->sedesPermitidas($usuarioId, $capacidad);

        if (empty($sedes)) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->whereIn($columnaSede, $sedes);
    }

    public function validarSede(?int $usuarioId, ?int $sedeId, ?string $capacidad = null): int
    {
        if (! $sedeId) {
            throw ValidationException::withMessages([
                'sede_id' => 'La operación debe estar asociada a una sede.',
            ]);
        }

        $sedes = $this->sedesPermitidas($usuarioId, $capacidad);
        if (! in_array($sedeId, $sedes, true)) {
            throw ValidationException::withMessages([
                'sede_id' => $capacidad
                    ? 'No tienes acceso a esta sede o la operación no está habilitada en ella.'
                    : 'No tienes acceso operativo a la sede seleccionada.',
            ]);
        }

        return $sedeId;
    }

    public function sedeDeCaja(?int $cajaId): ?int
    {
        if (! $cajaId) {
            return null;
        }

        $sede = DB::table('ventas.cajas')->where('id', $cajaId)->value('sede_id');
        return $sede ? (int) $sede : null;
    }

    public function sedeDeMembresia(?int $membresiaId): ?int
    {
        if (! $membresiaId) {
            return null;
        }

        $sede = DB::table('gimnasio.membresias')->where('id', $membresiaId)->value('sede_id');
        return $sede ? (int) $sede : null;
    }

    public function sedeDeVenta(int $ventaId): ?int
    {
        $venta = DB::table('ventas.ventas as v')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
            ->leftJoin('gimnasio.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->where('v.id', $ventaId)
            ->selectRaw('COALESCE(c.sede_id, m.sede_id) as sede_id')
            ->first();

        return $venta?->sede_id ? (int) $venta->sede_id : null;
    }

    private function validarCapacidad(string $capacidad): void
    {
        $permitidas = ['maneja_caja', 'maneja_inventario', 'permite_reservas', 'permite_entrenamiento'];
        if (! in_array($capacidad, $permitidas, true)) {
            throw new \InvalidArgumentException("Capacidad operativa no soportada: {$capacidad}");
        }
    }
}
