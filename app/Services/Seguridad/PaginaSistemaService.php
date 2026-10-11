<?php

namespace App\Services\Seguridad;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaginaSistemaService
{
    public function __construct(private readonly TiempoRealNavegacionService $tiempoReal) {}

    public function listar(): Collection
    {
        return DB::table('seguridad.cpu_userrolefunction as funcion')
            ->join('seguridad.cpu_usermenu as menu', 'menu.id_usermenu', '=', 'funcion.id_usermenu')
            ->leftJoin('seguridad.cpu_pagina_sistema as pagina', 'pagina.id_menu', '=', 'funcion.id_menu')
            ->select([
                'funcion.id_menu',
                DB::raw('MIN(funcion.nombre) as nombre'),
                DB::raw('MIN(funcion.icono) as icono'),
                DB::raw('MIN(menu.menu) as menu'),
                DB::raw('MIN(menu.orden) as menu_orden'),
                'pagina.clave_pagina',
            ])
            ->whereNotNull('funcion.id_menu')
            ->groupBy('funcion.id_menu', 'pagina.clave_pagina')
            ->orderBy('menu_orden')
            ->orderBy('nombre')
            ->get();
    }

    public function asociar(string $codigo, string $clavePagina): object
    {
        $existe = DB::table('seguridad.cpu_userrolefunction')->where('id_menu', $codigo)->exists();
        if (! $existe) {
            throw ValidationException::withMessages(['id_menu' => 'El submenú seleccionado no existe.']);
        }

        DB::table('seguridad.cpu_pagina_sistema')->updateOrInsert(
            ['id_menu' => $codigo],
            ['clave_pagina' => $clavePagina, 'updated_at' => now()]
        );

        $this->tiempoReal->emitir($codigo);

        return DB::table('seguridad.cpu_pagina_sistema')->where('id_menu', $codigo)->first();
    }

    public function desasociar(string $codigo): void
    {
        DB::table('seguridad.cpu_pagina_sistema')->where('id_menu', $codigo)->delete();
        $this->tiempoReal->emitir($codigo);
    }
}
