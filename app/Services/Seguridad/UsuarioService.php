<?php

namespace App\Services\Seguridad;

use App\Queries\Seguridad\UsuarioQuery;
use App\Services\Audit\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UsuarioService
{
    public function __construct(
        private AuditService $auditService,
        private UsuarioQuery $usuarioQuery
    ) {
    }

    public function crear(Request $request, array $data): array
    {
        $this->assertPersonaDisponible($data['persona_id'] ?? null);

        $user = $request->user();
        $cedula = $this->resolveCedula($data);

        $usuarioId = DB::transaction(function () use ($data, $user, $cedula) {
            $password = $data['password'] ?? Str::password(10, true, true, false, false);

            $usuarioId = DB::table('seguridad.usuarios')->insertGetId([
                'gimnasio_id' => $data['gimnasio_id'] ?? $user?->gimnasio_id,
                'persona_id' => $data['persona_id'] ?? null,
                'cedula' => $cedula,
                'email' => trim((string) $data['email']),
                'password_hash' => Hash::make($password),
                'estado' => $data['estado'] ?? 'ACTIVO',
                'fecha_baja' => ($data['estado'] ?? 'ACTIVO') === 'ACTIVO' ? null : now(),
                'requiere_cambio_password' => (bool) ($data['requiere_cambio_password'] ?? true),
                'password_temporal_generada_at' => now(),
                'email_credenciales' => $data['email_credenciales'] ?? null,
                'created_id_user' => $user?->id,
                'updated_id_user' => $user?->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (array_key_exists('roles', $data)) {
                $this->syncRoles($usuarioId, $data['roles'] ?? []);
            }

            if (array_key_exists('sedes', $data)) {
                $this->syncSedes($usuarioId, $data['sedes'] ?? []);
            }

            return $usuarioId;
        });

        $created = $this->usuarioQuery->obtenerPorId($usuarioId);

        $this->auditService->created($request, 'seguridad_usuarios', $usuarioId, $created, [
            'esquema' => 'seguridad',
            'modulo' => 'seguridad',
            'accion' => 'crear_usuario',
        ]);

        return $created ?? [];
    }

    public function actualizar(Request $request, int $id, array $data): array
    {
        $before = $this->usuarioQuery->obtenerPorId($id);

        if (!$before) {
            throw ValidationException::withMessages([
                'usuario' => 'No se encontró el usuario a actualizar.',
            ]);
        }

        $this->assertPersonaDisponible($data['persona_id'] ?? null, $id);

        $user = $request->user();
        $cedula = $this->resolveCedula($data);

        DB::transaction(function () use ($id, $data, $user, $cedula) {
            $payload = [
                'gimnasio_id' => $data['gimnasio_id'] ?? $user?->gimnasio_id,
                'persona_id' => $data['persona_id'] ?? null,
                'cedula' => $cedula,
                'email' => trim((string) $data['email']),
                'estado' => $data['estado'] ?? 'ACTIVO',
                'fecha_baja' => ($data['estado'] ?? 'ACTIVO') === 'ACTIVO' ? null : now(),
                'updated_id_user' => $user?->id,
                'updated_at' => now(),
            ];

            if (!empty($data['password'])) {
                $payload['password_hash'] = Hash::make($data['password']);
                $payload['requiere_cambio_password'] = (bool) ($data['requiere_cambio_password'] ?? true);
                $payload['password_temporal_generada_at'] = now();
            }

            if (array_key_exists('email_credenciales', $data)) {
                $payload['email_credenciales'] = $data['email_credenciales'];
            }

            DB::table('seguridad.usuarios')
                ->where('id', $id)
                ->update($payload);

            if (array_key_exists('roles', $data)) {
                $this->syncRoles($id, $data['roles'] ?? []);
            }

            if (array_key_exists('sedes', $data)) {
                $this->syncSedes($id, $data['sedes'] ?? []);
            }
        });

        $after = $this->usuarioQuery->obtenerPorId($id);

        $this->auditService->updated($request, 'seguridad_usuarios', $id, $before, $after, [
            'esquema' => 'seguridad',
            'modulo' => 'seguridad',
            'accion' => 'actualizar_usuario',
        ]);

        return $after ?? [];
    }

    public function actualizarAccesos(Request $request, int $id, array $data): array
    {
        $before = $this->usuarioQuery->obtenerPorId($id);

        if (!$before) {
            throw ValidationException::withMessages([
                'usuario' => 'No se encontró el usuario solicitado.',
            ]);
        }

        DB::transaction(function () use ($id, $data) {
            $this->syncRoles($id, $data['roles'] ?? []);
            $this->syncSedes($id, $data['sedes'] ?? []);
        });

        $after = $this->usuarioQuery->obtenerPorId($id);

        $this->auditService->updated($request, 'seguridad_usuarios_accesos', $id, $before, $after, [
            'esquema' => 'seguridad',
            'modulo' => 'seguridad',
            'accion' => 'actualizar_accesos_usuario',
        ]);

        return $after ?? [];
    }

    public function cambiarEstado(Request $request, int $id, string $estado): array
    {
        $before = $this->usuarioQuery->obtenerPorId($id);

        if (!$before) {
            throw ValidationException::withMessages([
                'usuario' => 'No se encontró el usuario solicitado.',
            ]);
        }

        DB::table('seguridad.usuarios')
            ->where('id', $id)
            ->update([
                'estado' => $estado,
                'fecha_baja' => $estado === 'ACTIVO' ? null : now(),
                'updated_id_user' => $request->user()?->id,
                'updated_at' => now(),
            ]);

        $after = $this->usuarioQuery->obtenerPorId($id);

        $this->auditService->updated($request, 'seguridad_usuarios', $id, $before, $after, [
            'esquema' => 'seguridad',
            'modulo' => 'seguridad',
            'accion' => 'cambiar_estado_usuario',
        ]);

        return $after ?? [];
    }

    private function assertPersonaDisponible(?int $personaId, ?int $ignoreUserId = null): void
    {
        if (!$personaId) {
            return;
        }

        $query = DB::table('seguridad.usuarios')
            ->where('persona_id', $personaId);

        if ($ignoreUserId) {
            $query->where('id', '!=', $ignoreUserId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'persona_id' => 'La persona seleccionada ya está asociada a otro usuario.',
            ]);
        }
    }

    private function resolveCedula(array $data): ?string
    {
        $personaId = Arr::get($data, 'persona_id');

        if ($personaId) {
            $cedula = DB::table('core.personas')
                ->where('id', $personaId)
                ->value('numero_identificacion');

            if (!$cedula) {
                throw ValidationException::withMessages([
                    'persona_id' => 'La persona seleccionada no tiene cédula registrada.',
                ]);
            }

            return trim((string) $cedula);
        }

        $cedula = trim((string) Arr::get($data, 'cedula', ''));

        return $cedula !== '' ? $cedula : null;
    }

    private function syncRoles(int $usuarioId, array $roles): void
    {
        $roleIds = collect($roles)
            ->filter(fn ($rol) => $rol !== null && $rol !== '')
            ->map(fn ($rol) => (int) $rol)
            ->unique()
            ->values();

        DB::table('seguridad.usuario_roles')->where('usuario_id', $usuarioId)->delete();

        if ($roleIds->isEmpty()) {
            return;
        }

        $rows = $roleIds->map(fn ($rolId) => [
            'usuario_id' => $usuarioId,
            'rol_id' => $rolId,
            'created_at' => now(),
        ])->all();

        DB::table('seguridad.usuario_roles')->insert($rows);
    }

    private function syncSedes(int $usuarioId, array $sedes): void
    {
        $sedeIds = collect($sedes)
            ->filter(fn ($sede) => $sede !== null && $sede !== '')
            ->map(fn ($sede) => (int) $sede)
            ->unique()
            ->values();

        DB::table('seguridad.usuario_sedes')->where('usuario_id', $usuarioId)->delete();

        if ($sedeIds->isEmpty()) {
            return;
        }

        $rows = $sedeIds->map(fn ($sedeId) => [
            'usuario_id' => $usuarioId,
            'sede_id' => $sedeId,
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        DB::table('seguridad.usuario_sedes')->insert($rows);
    }
}
