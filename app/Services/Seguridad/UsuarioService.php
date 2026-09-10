<?php

namespace App\Services\Seguridad;

use App\Models\User;
use App\Services\Concerns\RegistraAuditoria;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UsuarioService
{
    use RegistraAuditoria;

    public function __construct(private readonly TiempoRealUsuarioService $tiempoReal) {}

    public function listar(array $filtros): LengthAwarePaginator
    {
        $busqueda = trim((string) ($filtros['busqueda'] ?? ''));
        $roles = $this->resolverRoles($filtros['rol'] ?? []);
        $estados = array_filter((array) ($filtros['estado'] ?? []), fn ($valor) => $valor !== '');
        $porPagina = min((int) ($filtros['per_page'] ?? 10), 50);

        return User::query()
            ->leftJoin('seguridad.cpu_userrole as rol', 'rol.id_userrole', '=', 'seguridad.users.usr_tipo')
            ->select([
                'seguridad.users.id',
                'seguridad.users.name',
                'seguridad.users.email',
                'seguridad.users.cedula',
                'seguridad.users.nombres',
                'seguridad.users.apellidos',
                'seguridad.users.usr_tipo',
                'seguridad.users.usr_estado',
                'seguridad.users.created_at',
                'rol.role as rol_nombre',
            ])
            ->when($busqueda !== '', function ($query) use ($busqueda): void {
                $texto = mb_strtolower($busqueda);
                $query->where(function ($subquery) use ($texto): void {
                    $subquery
                        ->whereRaw('LOWER(seguridad.users.name) LIKE ?', ["%{$texto}%"])
                        ->orWhereRaw('LOWER(seguridad.users.email) LIKE ?', ["%{$texto}%"])
                        ->orWhereRaw('LOWER(COALESCE(seguridad.users.cedula, \'\')) LIKE ?', ["%{$texto}%"]);
                });
            })
            ->when($filtros['usuario'] ?? [], fn ($query, $valores) => $query->whereIn('seguridad.users.name', (array) $valores))
            ->when($filtros['correo'] ?? [], fn ($query, $valores) => $query->whereIn('seguridad.users.email', (array) $valores))
            ->when($filtros['cedula'] ?? [], fn ($query, $valores) => $query->whereIn('seguridad.users.cedula', (array) $valores))
            ->when($roles, fn ($query) => $query->whereIn('seguridad.users.usr_tipo', $roles))
            ->when($estados, fn ($query) => $query->whereIn('seguridad.users.usr_estado', $estados))
            ->orderByDesc('seguridad.users.created_at')
            ->paginate($porPagina);
    }

    /**
     * Acepta tanto ids de rol (id_userrole) como nombres de rol (ej. "DEPORTISTA"),
     * para que otros módulos puedan filtrar usuarios por rol sin conocer el id.
     */
    private function resolverRoles(mixed $rol): array
    {
        $valores = array_filter((array) $rol, fn ($valor) => $valor !== '' && $valor !== null);
        if (! $valores) {
            return [];
        }

        $ids = array_values(array_filter($valores, fn ($valor) => is_numeric($valor)));
        $nombres = array_values(array_filter($valores, fn ($valor) => ! is_numeric($valor)));

        if ($nombres) {
            $idsPorNombre = DB::table('seguridad.cpu_userrole')->whereIn('role', $nombres)->pluck('id_userrole')->all();
            $ids = array_merge($ids, $idsPorNombre);
        }

        return array_values(array_unique($ids));
    }

    public function opcionesFiltro(): array
    {
        $usuarios = User::query()->select('name', 'email', 'cedula')->orderBy('name')->get();

        return [
            'usuario' => $usuarios->pluck('name')->filter()->unique()->values(),
            'correo' => $usuarios->pluck('email')->filter()->unique()->values(),
            'cedula' => $usuarios->pluck('cedula')->filter()->unique()->values(),
        ];
    }

    public function crear(array $datos): User
    {
        return DB::transaction(function () use ($datos): User {
            $usuario = User::create([
                'name' => $this->resolverNombreCompleto($datos),
                'email' => mb_strtolower(trim($datos['email'])),
                'password' => Hash::make($datos['password']),
                'cedula' => $datos['cedula'] ?? null,
                'nombres' => $datos['nombres'],
                'apellidos' => $datos['apellidos'] ?? null,
                'usr_tipo' => $datos['usr_tipo'],
                'usr_estado' => $datos['usr_estado'] ?? 1,
            ]);

            DB::table('seguridad.preferencias_usuario')->updateOrInsert(
                ['id_usuario' => $usuario->id],
                [
                    'tema' => 'sistema',
                    'notificaciones_push' => true,
                    'notificaciones_correo' => true,
                    'notificaciones_internas' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            if (array_key_exists('funciones', $datos) && is_array($datos['funciones'])) {
                $this->guardarFuncionesUsuario($usuario->id, (int) $usuario->usr_tipo, $datos['funciones']);
            } else {
                $this->sincronizarFuncionesDesdeRol($usuario->id, (int) $usuario->usr_tipo);
            }

            $creado = $usuario->fresh();
            $this->auditar('seguridad', 'CREAR', 'seguridad.users', $creado->id, null, $creado);
            return $creado;
        });
    }

    public function actualizar(User $usuario, array $datos): User
    {
        return DB::transaction(function () use ($usuario, $datos): User {
            $antes = $usuario->fresh();
            $rolAnterior = (int) $usuario->usr_tipo;
            $rolNuevo = (int) ($datos['usr_tipo'] ?? $usuario->usr_tipo);

            $payload = [
                'name' => $this->resolverNombreCompleto($datos, $usuario),
                'email' => mb_strtolower(trim($datos['email'] ?? $usuario->email)),
                'cedula' => $datos['cedula'] ?? $usuario->cedula,
                'nombres' => $datos['nombres'] ?? $usuario->nombres,
                'apellidos' => $datos['apellidos'] ?? $usuario->apellidos,
                'usr_tipo' => $rolNuevo,
                'usr_estado' => $datos['usr_estado'] ?? $usuario->usr_estado,
            ];

            if (! empty($datos['password'])) {
                $payload['password'] = Hash::make($datos['password']);
            }

            $usuario->update($payload);

            if ($rolAnterior !== $rolNuevo || empty($this->obtenerFuncionesUsuario($usuario->id))) {
                $this->sincronizarFuncionesDesdeRol($usuario->id, $rolNuevo);
            }

            $actualizado = $usuario->fresh();
            $this->auditar('seguridad', 'ACTUALIZAR', 'seguridad.users', $actualizado->id, $antes, $actualizado);
            return $actualizado;
        });
    }

    public function cambiarEstado(User $usuario, int $estado): User
    {
        $antes = $usuario->fresh();
        $usuario->update(['usr_estado' => $estado]);

        if ($estado !== 1) {
            DB::table('seguridad.tokens_acceso')->where('id_usuario', $usuario->id)->delete();
        }

        $actualizado = $usuario->fresh();
        $this->auditar('seguridad', 'ACTUALIZAR', 'seguridad.users', $actualizado->id, $antes, $actualizado, 'Cambio de estado del usuario.');
        return $actualizado;
    }

    public function cambiarClave(User $usuario, string $password): void
    {
        $usuario->update(['password' => Hash::make($password)]);
        DB::table('seguridad.tokens_acceso')->where('id_usuario', $usuario->id)->delete();
        $this->auditar('seguridad', 'ACTUALIZAR', 'seguridad.users', $usuario->id, null, null, 'Contraseña actualizada.');
    }

    public function obtenerFuncionesUsuario(int $idUsuario): array
    {
        return DB::table('seguridad.cpu_userfunction')
            ->where('id_users', $idUsuario)
            ->where('activo', true)
            ->orderBy('id_usermenu')
            ->orderBy('orden')
            ->get()
            ->map(fn ($funcion): array => (array) $funcion)
            ->all();
    }

    public function sincronizarFuncionesDesdeRol(int $idUsuario, int $idRol): void
    {
        $funcionesRol = DB::table('seguridad.cpu_userrolefunction')
            ->where('id_userrole', $idRol)
            ->where('activo', true)
            ->orderBy('id_usermenu')
            ->orderBy('orden')
            ->get();

        DB::table('seguridad.cpu_userfunction')->where('id_users', $idUsuario)->delete();

        foreach ($funcionesRol as $funcion) {
            DB::table('seguridad.cpu_userfunction')->insert([
                'id_users' => $idUsuario,
                'id_userrole' => $idRol,
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

        DB::afterCommit(fn () => $this->tiempoReal->emitir($idUsuario, 'MENU_ACTUALIZADO', [
            'usuario_id' => $idUsuario,
        ]));
    }

    public function guardarFuncionesUsuario(int $idUsuario, int $idRol, array $codigosFunciones): void
    {
        $funciones = DB::table('seguridad.cpu_userrolefunction')
            ->whereIn('id_menu', $codigosFunciones)
            ->where('activo', true)
            ->orderByRaw('CASE WHEN id_userrole = ? THEN 0 ELSE 1 END', [$idRol])
            ->get();

        $funcionesUnicas = $funciones->unique('id_menu')->values();

        if ($funcionesUnicas->count() !== count(array_unique($codigosFunciones))) {
            throw ValidationException::withMessages([
                'funciones' => 'Una o varias funciones seleccionadas no existen o están inactivas.',
            ]);
        }

        DB::transaction(function () use ($idUsuario, $idRol, $funcionesUnicas): void {
            DB::table('seguridad.cpu_userfunction')->where('id_users', $idUsuario)->delete();

            foreach ($funcionesUnicas as $funcion) {
                DB::table('seguridad.cpu_userfunction')->insert([
                    'id_users' => $idUsuario,
                    'id_userrole' => $idRol,
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
        });

        DB::afterCommit(fn () => $this->tiempoReal->emitir($idUsuario, 'MENU_ACTUALIZADO', [
            'usuario_id' => $idUsuario,
        ]));
    }

    public function actualizarAccesos(User $usuario, int $idRol, array $codigosFunciones): void
    {
        DB::transaction(function () use ($usuario, $idRol, $codigosFunciones): void {
            $usuario->update(['usr_tipo' => $idRol]);
            $this->guardarFuncionesUsuario($usuario->id, $idRol, $codigosFunciones);
        });
    }

    private function resolverNombreCompleto(array $datos, ?User $usuario = null): string
    {
        $nombres = trim((string) ($datos['nombres'] ?? $usuario?->nombres ?? ''));
        $apellidos = trim((string) ($datos['apellidos'] ?? $usuario?->apellidos ?? ''));
        $nombreCompleto = trim("{$nombres} {$apellidos}");

        return $nombreCompleto !== '' ? $nombreCompleto : (string) ($datos['name'] ?? $usuario?->name ?? '');
    }
}
