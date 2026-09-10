<?php

namespace App\Services\Seguridad;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MenuService
{
    public function obtenerMenuUsuario(User $usuario): Collection
    {
        $filas = DB::table('seguridad.cpu_usermenu as menu')
            ->join('seguridad.cpu_userfunction as funcion', 'funcion.id_usermenu', '=', 'menu.id_usermenu')
            ->leftJoin('seguridad.cpu_pagina_sistema as pagina', 'pagina.id_menu', '=', 'funcion.id_menu')
            ->where('funcion.id_users', $usuario->id)
            ->where('funcion.activo', true)
            ->where('menu.activo', true)
            ->select([
                'menu.id_usermenu',
                'menu.menu',
                'menu.icono as menu_icono',
                'menu.orden as menu_orden',
                'funcion.id_userfunction',
                'funcion.id_userrole',
                'funcion.nombre',
                'funcion.icono as funcion_icono',
                'funcion.accion',
                'funcion.id_menu',
                'funcion.orden as funcion_orden',
                'pagina.clave_pagina',
            ])
            ->orderBy('menu.orden')
            ->orderBy('funcion.orden')
            ->get();

        return $filas
            ->groupBy('id_usermenu')
            ->map(function (Collection $items): array {
                $menu = $items->first();

                return [
                    'id_usermenu' => $menu->id_usermenu,
                    'menu' => $menu->menu,
                    'icono' => $menu->menu_icono,
                    'subItems' => $items->map(fn ($item): array => [
                        'id_userfunction' => $item->id_userfunction,
                        'id_userrole' => $item->id_userrole,
                        'id_usermenu' => $item->id_usermenu,
                        'nombre' => $item->nombre,
                        'icono' => $item->funcion_icono,
                        'accion' => $item->accion,
                        'id_menu' => $item->id_menu,
                        'clave_pagina' => $item->clave_pagina,
                    ])->values(),
                ];
            })
            ->values();
    }
}
