<?php

namespace App\Services\Seguridad;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CatalogoSeguridadService
{
    public function roles(): Collection
    {
        return DB::table('seguridad.cpu_userrole')
            ->select('id_userrole', 'role', 'activo')
            ->orderBy('role')
            ->get();
    }

    public function menus(): Collection
    {
        return DB::table('seguridad.cpu_usermenu')
            ->select('id_usermenu', 'menu', 'icono', 'activo', 'orden')
            ->orderBy('orden')
            ->orderBy('menu')
            ->get();
    }

    public function funcionesPorRol(int $idRol): Collection
    {
        return DB::table('seguridad.cpu_userrolefunction as funcion')
            ->join('seguridad.cpu_usermenu as menu', 'menu.id_usermenu', '=', 'funcion.id_usermenu')
            ->where('funcion.id_userrole', $idRol)
            ->where('funcion.activo', true)
            ->select([
                'funcion.id_userrf',
                'funcion.id_userrole',
                'funcion.id_usermenu',
                'funcion.nombre',
                'funcion.icono',
                'funcion.accion',
                'funcion.id_menu',
                'funcion.orden',
                'menu.menu',
            ])
            ->orderBy('menu.orden')
            ->orderBy('funcion.orden')
            ->get();
    }

    public function funcionesAgrupadasPorRol(int $idRol): Collection
    {
        return $this->funcionesPorRol($idRol)
            ->groupBy('id_usermenu')
            ->map(function (Collection $items): array {
                $menu = $items->first();

                return [
                    'id_usermenu' => $menu->id_usermenu,
                    'menu' => $menu->menu,
                    'funciones' => $items->map(fn ($funcion): array => [
                        'id_userrf' => $funcion->id_userrf,
                        'id_userrole' => $funcion->id_userrole,
                        'id_usermenu' => $funcion->id_usermenu,
                        'nombre' => $funcion->nombre,
                        'icono' => $funcion->icono,
                        'accion' => $funcion->accion,
                        'id_menu' => $funcion->id_menu,
                        'orden' => $funcion->orden,
                    ])->values(),
                ];
            })
            ->values();
    }

    public function funcionesDisponibles(): Collection
    {
        $funciones = DB::table('seguridad.cpu_userrolefunction as funcion')
            ->join('seguridad.cpu_usermenu as menu', 'menu.id_usermenu', '=', 'funcion.id_usermenu')
            ->where('menu.activo', true)
            ->where('funcion.activo', true)
            ->select([
                'funcion.id_usermenu',
                'funcion.nombre',
                'funcion.icono',
                'funcion.accion',
                'funcion.id_menu',
                DB::raw('MIN(funcion.orden) as orden'),
                'menu.menu',
                'menu.orden as menu_orden',
            ])
            ->groupBy('funcion.id_usermenu', 'funcion.nombre', 'funcion.icono', 'funcion.accion', 'funcion.id_menu', 'menu.menu', 'menu.orden')
            ->orderBy('menu.orden')
            ->orderBy('orden')
            ->get();

        return $funciones
            ->groupBy('id_usermenu')
            ->map(fn (Collection $items): array => [
                'id_usermenu' => $items->first()->id_usermenu,
                'menu' => $items->first()->menu,
                'funciones' => $items->map(fn ($funcion): array => [
                    'id_usermenu' => $funcion->id_usermenu,
                    'nombre' => $funcion->nombre,
                    'icono' => $funcion->icono,
                    'accion' => $funcion->accion,
                    'id_menu' => $funcion->id_menu,
                    'orden' => $funcion->orden,
                ])->values(),
            ])
            ->values();
    }
}
