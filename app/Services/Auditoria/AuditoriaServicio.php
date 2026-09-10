<?php

namespace App\Services\Auditoria;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AuditoriaServicio
{
    /**
     * Escribe un evento de auditoría (creación/actualización/eliminación de un dato).
     * Nunca debe romper la operación principal: cualquier fallo se registra en el log técnico y se ignora.
     */
    public function registrar(array $datos): void
    {
        try {
            $usuario = auth()->user();
            $request = request();

            DB::table('auditoria.eventos')->insert([
                'usuario_id' => $usuario?->id,
                'usuario_nombre' => $usuario?->name,
                'rol' => $this->rolActual($usuario?->id),
                'modulo' => $datos['modulo'],
                'tabla' => $datos['tabla'] ?? null,
                'registro_id' => isset($datos['registro_id']) ? (string) $datos['registro_id'] : null,
                'accion' => $datos['accion'],
                'descripcion' => $datos['descripcion'] ?? null,
                'datos_antes' => $this->jsonColumn($datos['datos_antes'] ?? null),
                'datos_despues' => $this->jsonColumn($datos['datos_despues'] ?? null),
                'ip' => $request?->ip(),
                'user_agent' => $request ? substr((string) $request->userAgent(), 0, 255) : null,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('No se pudo registrar auditoría', ['error' => $e->getMessage(), 'datos' => $datos]);
        }
    }

    public function registrarAcceso(?int $usuarioId, ?string $email, string $tipo, ?string $motivo = null): void
    {
        try {
            $request = request();

            DB::table('auditoria.accesos')->insert([
                'usuario_id' => $usuarioId,
                'email' => $email,
                'tipo' => $tipo,
                'motivo' => $motivo,
                'ip' => $request?->ip(),
                'user_agent' => $request ? substr((string) $request->userAgent(), 0, 255) : null,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('No se pudo registrar acceso al sistema', ['error' => $e->getMessage()]);
        }
    }

    public function listarEventos(array $filtros)
    {
        $query = DB::table('auditoria.eventos');

        $this->filtrarTexto($query, 'modulo', $filtros['modulo'] ?? null);
        $this->filtrarTexto($query, 'accion', $filtros['accion'] ?? null);
        $this->filtrarTexto($query, 'usuario_nombre', $filtros['usuario'] ?? null);
        $this->filtrarTexto($query, 'rol', $filtros['rol'] ?? null);

        if (! empty($filtros['busqueda'])) {
            $texto = mb_strtolower($filtros['busqueda']);
            $query->where(function ($q) use ($texto): void {
                $q->orWhereRaw('LOWER(modulo) LIKE ?', ["%{$texto}%"])
                    ->orWhereRaw('LOWER(tabla) LIKE ?', ["%{$texto}%"])
                    ->orWhereRaw('LOWER(accion) LIKE ?', ["%{$texto}%"])
                    ->orWhereRaw('LOWER(usuario_nombre) LIKE ?', ["%{$texto}%"])
                    ->orWhereRaw('LOWER(descripcion) LIKE ?', ["%{$texto}%"]);
            });
        }

        if (! empty($filtros['fecha_desde'])) {
            $query->where('created_at', '>=', $filtros['fecha_desde'] . ' 00:00:00');
        }
        if (! empty($filtros['fecha_hasta'])) {
            $query->where('created_at', '<=', $filtros['fecha_hasta'] . ' 23:59:59');
        }

        return $query->orderByDesc('created_at')->paginate($filtros['per_page'] ?? 10, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function listarAccesos(array $filtros)
    {
        $query = DB::table('auditoria.accesos');

        $this->filtrarTexto($query, 'tipo', $filtros['tipo'] ?? null);
        $this->filtrarTexto($query, 'email', $filtros['email'] ?? null);

        if (! empty($filtros['busqueda'])) {
            $texto = mb_strtolower($filtros['busqueda']);
            $query->where(function ($q) use ($texto): void {
                $q->orWhereRaw('LOWER(email) LIKE ?', ["%{$texto}%"])
                    ->orWhereRaw('LOWER(tipo) LIKE ?', ["%{$texto}%"])
                    ->orWhereRaw('LOWER(motivo) LIKE ?', ["%{$texto}%"]);
            });
        }

        return $query->orderByDesc('created_at')->paginate($filtros['per_page'] ?? 10, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function resumen(array $filtros): array
    {
        $query = DB::table('auditoria.eventos');
        if (! empty($filtros['fecha_desde'])) {
            $query->where('created_at', '>=', $filtros['fecha_desde'] . ' 00:00:00');
        }
        if (! empty($filtros['fecha_hasta'])) {
            $query->where('created_at', '<=', $filtros['fecha_hasta'] . ' 23:59:59');
        }

        $eventos = (clone $query)->get(['modulo', 'accion', 'usuario_nombre']);

        $agrupar = fn (string $campo) => $eventos
            ->groupBy(fn ($fila) => $fila->{$campo} ?: 'Sin dato')
            ->map(fn ($grupo, $clave) => ['clave' => $clave, 'total' => $grupo->count()])
            ->sortByDesc('total')
            ->values();

        return [
            'total_eventos' => $eventos->count(),
            'total_accesos_hoy' => DB::table('auditoria.accesos')->whereDate('created_at', now()->toDateString())->count(),
            'accesos_fallidos_hoy' => DB::table('auditoria.accesos')->where('tipo', 'LOGIN_FALLIDO')->whereDate('created_at', now()->toDateString())->count(),
            'por_modulo' => $agrupar('modulo'),
            'por_accion' => $agrupar('accion'),
            'por_usuario' => $agrupar('usuario_nombre'),
        ];
    }

    public function opcionesFiltro(): array
    {
        return [
            'modulo' => DB::table('auditoria.eventos')->distinct()->orderBy('modulo')->pluck('modulo')->values(),
            'accion' => DB::table('auditoria.eventos')->distinct()->orderBy('accion')->pluck('accion')->values(),
            'usuario' => DB::table('auditoria.eventos')->distinct()->orderBy('usuario_nombre')->pluck('usuario_nombre')->filter()->values(),
            'rol' => DB::table('auditoria.eventos')->distinct()->orderBy('rol')->pluck('rol')->filter()->values(),
            'tipo_acceso' => ['LOGIN', 'LOGOUT', 'LOGIN_FALLIDO'],
        ];
    }

    private function rolActual(?int $usuarioId): ?string
    {
        if (! $usuarioId) {
            return null;
        }

        return DB::table('seguridad.users')
            ->join('seguridad.cpu_userrole', 'seguridad.cpu_userrole.id_userrole', '=', 'seguridad.users.usr_tipo')
            ->where('seguridad.users.id', $usuarioId)
            ->value('seguridad.cpu_userrole.role');
    }

    private function jsonColumn(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        if (is_string($valor)) {
            return $valor;
        }
        return json_encode($valor, JSON_UNESCAPED_UNICODE);
    }

    private function filtrarTexto($query, string $columna, mixed $valor): void
    {
        if (empty($valor)) return;
        is_array($valor) ? $query->whereIn($columna, array_filter($valor)) : $query->whereRaw("LOWER({$columna}) LIKE ?", ['%' . mb_strtolower($valor) . '%']);
    }
}
