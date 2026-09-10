<?php

namespace App\Services\Logs;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Log técnico "visible": además del storage/logs/laravel.log de siempre,
 * guarda el evento en logs.eventos (y logs.excepciones si aplica) para
 * poder verlo en la interfaz (Auditoría > Errores del sistema) sin tener
 * que entrar al servidor a leer el archivo de log.
 */
class LogSistemaService
{
    public function info(?Request $request, string $modulo, string $accion, string $mensaje, array $contexto = []): ?int
    {
        return $this->registrar($request, 'INFO', 'BACKEND', $modulo, $accion, $mensaje, $contexto);
    }

    public function warning(?Request $request, string $modulo, string $accion, string $mensaje, array $contexto = []): ?int
    {
        return $this->registrar($request, 'WARNING', 'BACKEND', $modulo, $accion, $mensaje, $contexto);
    }

    public function error(?Request $request, string $modulo, string $accion, string $mensaje, array $contexto = []): ?int
    {
        return $this->registrar($request, 'ERROR', 'BACKEND', $modulo, $accion, $mensaje, $contexto);
    }

    public function excepcion(?Request $request, Throwable $exception, array $contexto = []): ?int
    {
        $logEventoId = $this->registrar(
            $request,
            'ERROR',
            $contexto['canal'] ?? 'BACKEND',
            $contexto['modulo'] ?? 'general',
            $contexto['accion'] ?? 'excepcion',
            $exception->getMessage() ?: 'Excepción no controlada',
            $contexto
        );

        if (! $logEventoId || ! $this->tablaExiste('logs.excepciones')) {
            return $logEventoId;
        }

        try {
            DB::table('logs.excepciones')->insert([
                'log_evento_id' => $logEventoId,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
                'archivo' => $exception->getFile(),
                'linea' => $exception->getLine(),
                'stack_trace' => $exception->getTraceAsString(),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('No se pudo registrar excepción técnica', ['message' => $e->getMessage()]);
        }

        return $logEventoId;
    }

    public function integracion(array $data): ?int
    {
        if (! $this->tablaExiste('logs.integraciones')) {
            return null;
        }

        try {
            return DB::table('logs.integraciones')->insertGetId([
                'request_id' => $data['request_id'] ?? request()?->attributes->get('request_id') ?? request()?->headers->get('X-Request-ID'),
                'proveedor' => $data['proveedor'],
                'tipo' => $data['tipo'],
                'direccion' => $data['direccion'],
                'endpoint' => $data['endpoint'] ?? null,
                'metodo' => $data['metodo'] ?? null,
                'status_code' => $data['status_code'] ?? null,
                'request_payload' => $this->jsonValue($data['request_payload'] ?? null),
                'response_payload' => $this->jsonValue($data['response_payload'] ?? null),
                'error' => $data['error'] ?? null,
                'duracion_ms' => $data['duracion_ms'] ?? null,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('No se pudo registrar log de integración', ['message' => $e->getMessage()]);
            return null;
        }
    }

    public function registrar(
        ?Request $request,
        string $nivel,
        string $canal,
        ?string $modulo,
        ?string $accion,
        string $mensaje,
        array $contexto = []
    ): ?int {
        if (! $this->tablaExiste('logs.eventos')) {
            return null;
        }

        try {
            $request = $request ?? request();
            $user = $request?->user();

            return DB::table('logs.eventos')->insertGetId([
                'request_id' => $contexto['request_id'] ?? $request?->attributes->get('request_id') ?? $request?->headers->get('X-Request-ID') ?? (string) Str::uuid(),
                'nivel' => Str::upper($nivel),
                'canal' => Str::upper($canal),
                'modulo' => $modulo,
                'accion' => $accion,
                'mensaje' => mb_substr($mensaje, 0, 4000),
                'usuario_id' => $contexto['usuario_id'] ?? $user?->id,
                'sede_id' => $contexto['sede_id'] ?? $request?->input('sede_id'),
                'ip' => $request?->ip(),
                'user_agent' => $request ? substr((string) $request->userAgent(), 0, 255) : null,
                'contexto' => $this->jsonValue($contexto),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('No se pudo registrar log técnico', ['message' => $e->getMessage()]);
            return null;
        }
    }

    public function listarEventos(array $filtros)
    {
        $query = DB::table('logs.eventos as e')
            ->leftJoin('logs.excepciones as x', 'x.log_evento_id', '=', 'e.id')
            ->leftJoin('seguridad.users as u', 'u.id', '=', 'e.usuario_id')
            ->select('e.*', 'u.name as usuario_nombre', 'x.exception_class', 'x.archivo', 'x.linea', 'x.stack_trace');

        $this->filtrarTexto($query, 'e.nivel', $filtros['nivel'] ?? null);
        $this->filtrarTexto($query, 'e.canal', $filtros['canal'] ?? null);
        $this->filtrarTexto($query, 'e.modulo', $filtros['modulo'] ?? null);

        if (! empty($filtros['busqueda'])) {
            $texto = mb_strtolower($filtros['busqueda']);
            $query->where(function ($q) use ($texto): void {
                $q->orWhereRaw('LOWER(e.mensaje) LIKE ?', ["%{$texto}%"])
                    ->orWhereRaw('LOWER(e.modulo) LIKE ?', ["%{$texto}%"])
                    ->orWhereRaw('LOWER(e.accion) LIKE ?', ["%{$texto}%"])
                    ->orWhereRaw('LOWER(x.exception_class) LIKE ?', ["%{$texto}%"]);
            });
        }

        if (! empty($filtros['fecha_desde'])) {
            $query->where('e.created_at', '>=', $filtros['fecha_desde'] . ' 00:00:00');
        }
        if (! empty($filtros['fecha_hasta'])) {
            $query->where('e.created_at', '<=', $filtros['fecha_hasta'] . ' 23:59:59');
        }

        return $query->orderByDesc('e.created_at')->paginate($filtros['per_page'] ?? 10, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function opcionesFiltro(): array
    {
        return [
            'nivel' => ['INFO', 'WARNING', 'ERROR'],
            'canal' => DB::table('logs.eventos')->distinct()->orderBy('canal')->pluck('canal')->values(),
            'modulo' => DB::table('logs.eventos')->distinct()->orderBy('modulo')->pluck('modulo')->filter()->values(),
        ];
    }

    private function jsonValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    private function filtrarTexto($query, string $columna, mixed $valor): void
    {
        if (empty($valor)) return;
        is_array($valor) ? $query->whereIn($columna, array_filter($valor)) : $query->whereRaw("LOWER({$columna}) LIKE ?", ['%' . mb_strtolower($valor) . '%']);
    }

    private function tablaExiste(string $tabla): bool
    {
        try {
            $row = DB::selectOne('SELECT to_regclass(?) AS table_name', [$tabla]);
            return ! empty($row?->table_name);
        } catch (Throwable) {
            return false;
        }
    }
}
