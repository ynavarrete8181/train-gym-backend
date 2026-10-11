<?php

namespace App\Services\Seguridad;

use App\Services\Concerns\RegistraAuditoria;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RolPermisoService
{
    use RegistraAuditoria;

    public function __construct(private readonly ?TiempoRealNavegacionService $tiempoReal = null) {}

    public function crearRol(array $datos): object
    {
        $id = DB::table('seguridad.cpu_userrole')->insertGetId([
            'role' => mb_strtoupper(trim($datos['role'])),
            'activo' => $datos['activo'] ?? true,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id_userrole');

        $rol = DB::table('seguridad.cpu_userrole')->where('id_userrole', $id)->first();
        $this->auditar('seguridad', 'CREAR', 'seguridad.cpu_userrole', $id, null, $rol);
        return $rol;
    }

    public function actualizarRol(int $idRol, array $datos): object
    {
        $antes = $this->obtenerRol($idRol);

        DB::transaction(function () use ($idRol, $datos): void {
            DB::table('seguridad.cpu_userrole')
                ->where('id_userrole', $idRol)
                ->update([
                    'role' => mb_strtoupper(trim($datos['role'])),
                    'activo' => $datos['activo'],
                    'updated_at' => now(),
                ]);

            if (! $datos['activo']) {
                $usuarios = DB::table('seguridad.users')->where('usr_tipo', $idRol)->pluck('id');
                DB::table('seguridad.tokens_acceso')->whereIn('id_usuario', $usuarios)->delete();
            }
        });

        $rol = DB::table('seguridad.cpu_userrole')->where('id_userrole', $idRol)->first();
        $this->auditar('seguridad', 'ACTUALIZAR', 'seguridad.cpu_userrole', $idRol, $antes, $rol);
        return $rol;
    }

    public function cambiarEstadoRol(int $idRol, bool $activo): object
    {
        $antes = $this->obtenerRol($idRol);

        DB::transaction(function () use ($idRol, $activo): void {
            DB::table('seguridad.cpu_userrole')
                ->where('id_userrole', $idRol)
                ->update(['activo' => $activo, 'updated_at' => now()]);

            if (! $activo) {
                $usuarios = DB::table('seguridad.users')->where('usr_tipo', $idRol)->pluck('id');
                DB::table('seguridad.tokens_acceso')->whereIn('id_usuario', $usuarios)->delete();
            }
        });

        $rol = DB::table('seguridad.cpu_userrole')->where('id_userrole', $idRol)->first();
        $this->auditar('seguridad', 'ACTUALIZAR', 'seguridad.cpu_userrole', $idRol, $antes, $rol, 'Cambio de estado del rol.');
        return $rol;
    }

    public function crearMenu(array $datos): object
    {
        $id = DB::table('seguridad.cpu_usermenu')->insertGetId([
            'menu' => trim($datos['menu']),
            'icono' => $datos['icono'] ?? null,
            'activo' => $datos['activo'] ?? true,
            'orden' => $datos['orden'] ?? $this->siguienteOrdenMenu(),
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id_usermenu');

        $menu = DB::table('seguridad.cpu_usermenu')->where('id_usermenu', $id)->first();
        $this->notificarNavegacion('MENU-'.$id);
        $this->auditar('seguridad', 'CREAR', 'seguridad.cpu_usermenu', $id, null, $menu);

        return $menu;
    }

    public function actualizarMenu(int $idMenu, array $datos): object
    {
        $antes = $this->obtenerMenu($idMenu);

        DB::table('seguridad.cpu_usermenu')
            ->where('id_usermenu', $idMenu)
            ->update([
                'menu' => trim($datos['menu']),
                'icono' => $datos['icono'] ?? null,
                'activo' => $datos['activo'],
                'orden' => $datos['orden'] ?? $antes->orden,
                'updated_at' => now(),
            ]);

        $menu = DB::table('seguridad.cpu_usermenu')->where('id_usermenu', $idMenu)->first();
        $this->notificarNavegacion('MENU-'.$idMenu);
        $this->auditar('seguridad', 'ACTUALIZAR', 'seguridad.cpu_usermenu', $idMenu, $antes, $menu);

        return $menu;
    }

    public function cambiarEstadoMenu(int $idMenu, bool $activo): object
    {
        $this->obtenerMenu($idMenu);

        DB::table('seguridad.cpu_usermenu')
            ->where('id_usermenu', $idMenu)
            ->update(['activo' => $activo, 'updated_at' => now()]);

        $menu = DB::table('seguridad.cpu_usermenu')->where('id_usermenu', $idMenu)->first();
        $this->notificarNavegacion('MENU-'.$idMenu);

        return $menu;
    }

    public function moverMenu(int $idMenu, string $direccion): object
    {
        $resultado = DB::transaction(function () use ($idMenu, $direccion): object {
            $menu = DB::table('seguridad.cpu_usermenu')->where('id_usermenu', $idMenu)->first();

            if (! $menu) {
                throw ValidationException::withMessages([
                    'menu' => 'El menú seleccionado no existe.',
                ]);
            }

            $vecino = DB::table('seguridad.cpu_usermenu')
                ->when(
                    $direccion === 'arriba',
                    fn ($query) => $query->where('orden', '<', $menu->orden)->orderByDesc('orden'),
                    fn ($query) => $query->where('orden', '>', $menu->orden)->orderBy('orden'),
                )
                ->orderBy('menu')
                ->first();

            if (! $vecino) {
                return $menu;
            }

            $this->intercambiarOrden('seguridad.cpu_usermenu', 'id_usermenu', $menu->id_usermenu, $menu->orden, $vecino->id_usermenu, $vecino->orden);

            return DB::table('seguridad.cpu_usermenu')->where('id_usermenu', $idMenu)->first();
        });

        $this->notificarNavegacion('ORDEN-MENUS');

        return $resultado;
    }

    public function eliminarMenu(int $idMenu): void
    {
        $this->obtenerMenu($idMenu);

        $tieneSubmenus = DB::table('seguridad.cpu_userrolefunction')->where('id_usermenu', $idMenu)->exists()
            || DB::table('seguridad.cpu_userfunction')->where('id_usermenu', $idMenu)->exists();

        if ($tieneSubmenus) {
            throw ValidationException::withMessages([
                'menu' => 'No se puede eliminar el menú porque tiene submenús asociados.',
            ]);
        }

        DB::table('seguridad.cpu_usermenu')->where('id_usermenu', $idMenu)->delete();
        $this->notificarNavegacion('MENU-'.$idMenu);
        $this->auditar('seguridad', 'ELIMINAR', 'seguridad.cpu_usermenu', $idMenu, null, null);
    }

    public function guardarFuncionRol(array $datos, ?int $idFuncion = null): object
    {
        $funcionActual = $idFuncion
            ? DB::table('seguridad.cpu_userrolefunction')->where('id_userrf', $idFuncion)->first()
            : null;

        if ($idFuncion && ! $funcionActual) {
            throw ValidationException::withMessages([
                'funcion' => 'El submenú seleccionado no existe.',
            ]);
        }

        $this->obtenerRol((int) $datos['id_userrole']);
        $this->obtenerMenu((int) $datos['id_usermenu']);

        $nombre = trim($datos['nombre']);
        $idMenu = trim((string) ($datos['id_menu'] ?? ''));
        $accion = trim((string) ($datos['accion'] ?? ''));

        $payload = [
            'id_userrole' => $datos['id_userrole'],
            'id_usermenu' => $datos['id_usermenu'],
            'nombre' => $nombre,
            'icono' => $datos['icono'] ?? null,
            'accion' => $accion !== '' ? $accion : ($funcionActual->accion ?? $this->generarRuta($nombre)),
            'id_menu' => $idMenu !== '' ? mb_strtoupper($idMenu) : ($funcionActual->id_menu ?? $this->generarCodigoPermiso($nombre)),
            'activo' => $datos['activo'] ?? true,
            'orden' => $datos['orden'] ?? ($funcionActual->orden ?? $this->siguienteOrdenFuncion((int) $datos['id_userrole'], (int) $datos['id_usermenu'])),
            'updated_at' => now(),
        ];

        $duplicado = DB::table('seguridad.cpu_userrolefunction')
            ->where('id_userrole', $payload['id_userrole'])
            ->where('id_menu', $payload['id_menu'])
            ->when($idFuncion, fn ($query) => $query->where('id_userrf', '<>', $idFuncion))
            ->exists();

        if ($duplicado) {
            throw ValidationException::withMessages([
                'id_menu' => 'Ya existe una función con ese código para el rol seleccionado.',
            ]);
        }

        $funcion = DB::transaction(function () use ($idFuncion, $funcionActual, $payload): object {
            if ($idFuncion) {
                DB::table('seguridad.cpu_userrolefunction')->where('id_userrf', $idFuncion)->update($payload);

                if ((int) $funcionActual->id_userrole !== (int) $payload['id_userrole'] || $funcionActual->id_menu !== $payload['id_menu']) {
                    DB::table('seguridad.cpu_userfunction')
                        ->where('id_userrole', $funcionActual->id_userrole)
                        ->where('id_menu', $funcionActual->id_menu)
                        ->delete();
                }
            } else {
                $payload['created_at'] = now();
                $idFuncion = DB::table('seguridad.cpu_userrolefunction')->insertGetId($payload, 'id_userrf');
            }

            $funcion = DB::table('seguridad.cpu_userrolefunction')->where('id_userrf', $idFuncion)->first();
            $this->sincronizarFuncionEnUsuarios($funcion);

            return $funcion;
        });

        $this->notificarNavegacion($funcion->id_menu);
        $this->auditar('seguridad', $idFuncion ? 'ACTUALIZAR' : 'CREAR', 'seguridad.cpu_userrolefunction', $funcion->id_userrf, $funcionActual, $funcion);

        return $funcion;
    }

    public function guardarFuncionEnRoles(array $datos): Collection
    {
        return DB::transaction(function () use ($datos): Collection {
            $funciones = collect();

            foreach ($datos['id_userroles'] as $idRol) {
                $funciones->push($this->guardarFuncionRol([
                    ...$datos,
                    'id_userrole' => (int) $idRol,
                ]));
            }

            return $funciones;
        });
    }

    public function asignarFuncionExistente(int $idRol, string $codigo): object
    {
        $codigo = mb_strtoupper(trim($codigo));
        $funcionRol = DB::table('seguridad.cpu_userrolefunction')
            ->where('id_userrole', $idRol)
            ->where('id_menu', $codigo)
            ->first();

        if ($funcionRol) {
            DB::table('seguridad.cpu_userrolefunction')
                ->where('id_userrf', $funcionRol->id_userrf)
                ->update(['activo' => true, 'updated_at' => now()]);

            $funcionRol = DB::table('seguridad.cpu_userrolefunction')->where('id_userrf', $funcionRol->id_userrf)->first();
            $this->sincronizarFuncionEnUsuarios($funcionRol);

            $this->notificarNavegacion($codigo);
            return $funcionRol;
        }

        $funcionBase = DB::table('seguridad.cpu_userrolefunction')
            ->where('id_menu', $codigo)
            ->orderBy('orden')
            ->first();

        if (! $funcionBase) {
            throw ValidationException::withMessages([
                'funcion' => 'La opción seleccionada no existe en el catálogo.',
            ]);
        }

        $resultado = $this->guardarFuncionRol([
            'id_userrole' => $idRol,
            'id_usermenu' => $funcionBase->id_usermenu,
            'nombre' => $funcionBase->nombre,
            'icono' => $funcionBase->icono ?? null,
            'accion' => $funcionBase->accion,
            'id_menu' => $funcionBase->id_menu,
            'activo' => true,
            'orden' => $this->siguienteOrdenFuncion($idRol, (int) $funcionBase->id_usermenu),
        ]);
        $this->notificarNavegacion($codigo);
        return $resultado;
    }

    public function quitarFuncionRol(int $idRol, string $codigo): void
    {
        $codigo = mb_strtoupper(trim($codigo));
        $funcion = DB::table('seguridad.cpu_userrolefunction')
            ->where('id_userrole', $idRol)
            ->where('id_menu', $codigo)
            ->first();

        if (! $funcion) {
            throw ValidationException::withMessages([
                'funcion' => 'La opción no está asignada al rol seleccionado.',
            ]);
        }

        DB::transaction(function () use ($idRol, $codigo, $funcion): void {
            DB::table('seguridad.cpu_userrolefunction')
                ->where('id_userrf', $funcion->id_userrf)
                ->update(['activo' => false, 'updated_at' => now()]);

            DB::table('seguridad.cpu_userfunction')
                ->where('id_userrole', $idRol)
                ->where('id_menu', $codigo)
                ->delete();
        });
        $this->notificarNavegacion($codigo);
        $this->auditar('seguridad', 'ACTUALIZAR', 'seguridad.cpu_userrolefunction', $funcion->id_userrf, null, null, "Permiso {$codigo} quitado del rol {$idRol}.");
    }

    public function eliminarFuncion(int $idFuncion): void
    {
        $funcion = DB::table('seguridad.cpu_userrolefunction')->where('id_userrf', $idFuncion)->first();

        if (! $funcion) {
            throw ValidationException::withMessages([
                'funcion' => 'El submenú seleccionado no existe.',
            ]);
        }

        $asignadoUsuarios = DB::table('seguridad.cpu_userfunction')->where('id_menu', $funcion->id_menu)->exists();

        if ($asignadoUsuarios) {
            throw ValidationException::withMessages([
                'funcion' => 'No se puede eliminar el submenú porque está asignado a usuarios.',
            ]);
        }

        DB::table('seguridad.cpu_userrolefunction')->where('id_userrf', $idFuncion)->delete();
        $this->notificarNavegacion($funcion->id_menu);
        $this->auditar('seguridad', 'ELIMINAR', 'seguridad.cpu_userrolefunction', $idFuncion, $funcion, null);
    }

    public function cambiarEstadoFuncion(int $idFuncion, bool $activo): object
    {
        $funcion = DB::table('seguridad.cpu_userrolefunction')->where('id_userrf', $idFuncion)->first();

        if (! $funcion) {
            throw ValidationException::withMessages([
                'funcion' => 'El submenú seleccionado no existe.',
            ]);
        }

        DB::table('seguridad.cpu_userrolefunction')
            ->where('id_userrf', $idFuncion)
            ->update(['activo' => $activo, 'updated_at' => now()]);

        $funcion = DB::table('seguridad.cpu_userrolefunction')->where('id_userrf', $idFuncion)->first();

        if (! $activo) {
            DB::table('seguridad.cpu_userfunction')
                ->where('id_userrole', $funcion->id_userrole)
                ->where('id_menu', $funcion->id_menu)
                ->update(['activo' => false, 'updated_at' => now()]);
        } else {
            $this->sincronizarFuncionEnUsuarios($funcion);
        }

        $this->notificarNavegacion($funcion->id_menu);
        $this->auditar('seguridad', 'ACTUALIZAR', 'seguridad.cpu_userrolefunction', $idFuncion, null, $funcion, 'Cambio de estado del permiso.');

        return $funcion;
    }

    public function moverFuncion(int $idFuncion, string $direccion): object
    {
        $resultado = DB::transaction(function () use ($idFuncion, $direccion): object {
            $funcion = DB::table('seguridad.cpu_userrolefunction')->where('id_userrf', $idFuncion)->first();

            if (! $funcion) {
                throw ValidationException::withMessages([
                    'funcion' => 'El submenú seleccionado no existe.',
                ]);
            }

            $vecino = DB::table('seguridad.cpu_userrolefunction')
                ->where('id_userrole', $funcion->id_userrole)
                ->where('id_usermenu', $funcion->id_usermenu)
                ->when(
                    $direccion === 'arriba',
                    fn ($query) => $query->where('orden', '<', $funcion->orden)->orderByDesc('orden'),
                    fn ($query) => $query->where('orden', '>', $funcion->orden)->orderBy('orden'),
                )
                ->orderBy('nombre')
                ->first();

            if (! $vecino) {
                return $funcion;
            }

            $this->intercambiarOrden('seguridad.cpu_userrolefunction', 'id_userrf', $funcion->id_userrf, $funcion->orden, $vecino->id_userrf, $vecino->orden);
            $this->sincronizarOrdenFuncionUsuarios($funcion, (int) $vecino->orden);
            $this->sincronizarOrdenFuncionUsuarios($vecino, (int) $funcion->orden);

            return DB::table('seguridad.cpu_userrolefunction')->where('id_userrf', $idFuncion)->first();
        });

        $this->notificarNavegacion($resultado->id_menu ?: 'ORDEN-SUBMENUS');

        return $resultado;
    }

    public function sincronizarRol(int $idRol): int
    {
        $this->obtenerRol($idRol);

        $usuarios = DB::table('seguridad.users')->where('usr_tipo', $idRol)->pluck('id');
        $funciones = DB::table('seguridad.cpu_userrolefunction')
            ->where('id_userrole', $idRol)
            ->where('activo', true)
            ->get();

        foreach ($usuarios as $idUsuario) {
            DB::table('seguridad.cpu_userfunction')->where('id_users', $idUsuario)->delete();

            foreach ($funciones as $funcion) {
                $this->insertarFuncionUsuario((int) $idUsuario, $funcion);
            }
        }

        $this->notificarNavegacion('ROL-'.$idRol);

        return $usuarios->count();
    }

    private function notificarNavegacion(string $codigo): void
    {
        if (! $this->tiempoReal) {
            return;
        }

        DB::afterCommit(fn () => $this->tiempoReal?->emitir($codigo));
    }

    public function detalleRol(int $idRol): array
    {
        $rol = $this->obtenerRol($idRol);
        $funciones = DB::table('seguridad.cpu_userrolefunction as funcion')
            ->join('seguridad.cpu_usermenu as menu', 'menu.id_usermenu', '=', 'funcion.id_usermenu')
            ->where('funcion.id_userrole', $idRol)
            ->select([
                'funcion.id_userrf',
                'funcion.id_userrole',
                'funcion.id_usermenu',
                'funcion.nombre',
                'funcion.icono',
                'funcion.accion',
                'funcion.id_menu',
                'funcion.activo',
                'funcion.orden',
                'menu.menu',
            ])
            ->orderBy('menu.orden')
            ->orderBy('funcion.orden')
            ->get();

        return [
            'rol' => $rol,
            'funciones' => $funciones,
            'funciones_agrupadas' => $this->agruparFunciones($funciones),
        ];
    }

    private function sincronizarFuncionEnUsuarios(object $funcion): void
    {
        if (! $funcion->activo) {
            DB::table('seguridad.cpu_userfunction')
                ->where('id_userrole', $funcion->id_userrole)
                ->where('id_menu', $funcion->id_menu)
                ->update(['activo' => false, 'updated_at' => now()]);

            return;
        }

        $usuarios = DB::table('seguridad.users')->where('usr_tipo', $funcion->id_userrole)->pluck('id');

        foreach ($usuarios as $idUsuario) {
            DB::table('seguridad.cpu_userfunction')->updateOrInsert(
                ['id_users' => $idUsuario, 'id_menu' => $funcion->id_menu],
                [
                    'id_userrole' => $funcion->id_userrole,
                    'id_usermenu' => $funcion->id_usermenu,
                    'nombre' => $funcion->nombre,
                    'icono' => $funcion->icono,
                    'accion' => $funcion->accion,
                    'activo' => true,
                    'orden' => $funcion->orden,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    private function insertarFuncionUsuario(int $idUsuario, object $funcion): void
    {
        DB::table('seguridad.cpu_userfunction')->insert([
            'id_users' => $idUsuario,
            'id_userrole' => $funcion->id_userrole,
            'id_usermenu' => $funcion->id_usermenu,
            'nombre' => $funcion->nombre,
            'icono' => $funcion->icono,
            'accion' => $funcion->accion,
            'id_menu' => $funcion->id_menu,
            'activo' => true,
            'orden' => $funcion->orden,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function obtenerRol(int $idRol): object
    {
        $rol = DB::table('seguridad.cpu_userrole')->where('id_userrole', $idRol)->first();

        if (! $rol) {
            throw ValidationException::withMessages([
                'rol' => 'El rol seleccionado no existe.',
            ]);
        }

        return $rol;
    }

    private function obtenerMenu(int $idMenu): object
    {
        $menu = DB::table('seguridad.cpu_usermenu')->where('id_usermenu', $idMenu)->first();

        if (! $menu) {
            throw ValidationException::withMessages([
                'menu' => 'El menú seleccionado no existe.',
            ]);
        }

        return $menu;
    }

    private function siguienteOrdenMenu(): int
    {
        return ((int) DB::table('seguridad.cpu_usermenu')->max('orden')) + 1;
    }

    private function siguienteOrdenFuncion(int $idRol, int $idMenu): int
    {
        return ((int) DB::table('seguridad.cpu_userrolefunction')
            ->where('id_userrole', $idRol)
            ->where('id_usermenu', $idMenu)
            ->max('orden')) + 1;
    }

    private function generarCodigoPermiso(string $nombre): string
    {
        return mb_strtoupper(Str::slug($nombre, '-'));
    }

    private function generarRuta(string $nombre): string
    {
        return Str::slug($nombre, '/');
    }

    private function intercambiarOrden(string $tabla, string $columnaId, int $idActual, int $ordenActual, int $idVecino, int $ordenVecino): void
    {
        DB::table($tabla)->where($columnaId, $idActual)->update([
            'orden' => $ordenVecino,
            'updated_at' => now(),
        ]);

        DB::table($tabla)->where($columnaId, $idVecino)->update([
            'orden' => $ordenActual,
            'updated_at' => now(),
        ]);
    }

    private function sincronizarOrdenFuncionUsuarios(object $funcion, int $orden): void
    {
        DB::table('seguridad.cpu_userfunction')
            ->where('id_userrole', $funcion->id_userrole)
            ->where('id_menu', $funcion->id_menu)
            ->update([
                'orden' => $orden,
                'updated_at' => now(),
            ]);
    }

    private function agruparFunciones(Collection $funciones): Collection
    {
        return $funciones
            ->groupBy('id_usermenu')
            ->map(fn (Collection $items): array => [
                'id_usermenu' => $items->first()->id_usermenu,
                'menu' => $items->first()->menu,
                'funciones' => $items->values(),
            ])
            ->values();
    }
}
