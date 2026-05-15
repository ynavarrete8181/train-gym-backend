<?php

namespace App\Services\Audit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AuditService
{
    private const MODULE_MAP = [
        'categoria_servicios' => 'servicios',
        'tipos_servicios' => 'servicios',
        'horarios_gym' => 'horarios',
        'horarios_gym_dias' => 'horarios',
        'reservas_gym' => 'horarios',
        'productos' => 'inventarios',
        'categorias_producto' => 'inventarios',
        'producto_stock_sede' => 'inventarios',
        'producto_lotes' => 'inventarios',
        'producto_precios' => 'inventarios',
        'auth_usuarios' => 'auth',
        'auth_usuario_roles' => 'auth',
        'auth_roles' => 'auth',
        'auth_permisos' => 'auth',
        'auth_menu_items' => 'auth',
        'auth_tokens_acceso' => 'auth',
        'auth_sesion' => 'auth',
    ];

    public function created(Request $request, string $table, int|string|null $recordId, mixed $after, array $extra = []): void
    {
        $this->log($request, array_merge($extra, [
            'operacion' => 'I',
            'tabla' => $table,
            'accion' => $extra['accion'] ?? 'crear',
            'registro_id' => $recordId,
            'datos_despues' => $after,
        ]));
    }

    public function updated(
        Request $request,
        string $table,
        int|string|null $recordId,
        mixed $before,
        mixed $after,
        array $extra = []
    ): void {
        $this->log($request, array_merge($extra, [
            'operacion' => 'U',
            'tabla' => $table,
            'accion' => $extra['accion'] ?? 'actualizar',
            'registro_id' => $recordId,
            'datos_antes' => $before,
            'datos_despues' => $after,
        ]));
    }

    public function deleted(
        Request $request,
        string $table,
        int|string|null $recordId,
        mixed $before,
        mixed $after = null,
        array $extra = []
    ): void {
        $this->log($request, array_merge($extra, [
            'operacion' => 'D',
            'tabla' => $table,
            'accion' => $extra['accion'] ?? 'eliminar',
            'registro_id' => $recordId,
            'datos_antes' => $before,
            'datos_despues' => $after,
        ]));
    }

    public function activity(Request $request, string $module, string $action, array $extra = []): void
    {
        $this->log($request, array_merge($extra, [
            'modulo' => $module,
            'accion' => $action,
            'tabla' => $extra['tabla'] ?? 'sistema',
        ]));
    }

    public function log(Request $request, array $payload): void
    {
        try {
            $user = $request->user();
            $userAgent = (string) $request->userAgent();
            $device = $this->detectDeviceType($userAgent);
            $table = $payload['tabla'] ?? null;
            $module = $payload['modulo'] ?? $this->resolveModule($table);

            DB::table('train_gimnasio.aud_cambios')->insert([
                'gimnasio_id' => $payload['gimnasio_id'] ?? $user?->gimnasio_id,
                'sede_id' => $payload['sede_id'] ?? $request->input('sede_id'),
                'actor_usuario_id' => $payload['actor_usuario_id'] ?? $user?->id,
                'actor_rol_id' => $payload['actor_rol_id'] ?? $this->resolveActorRoleId($user?->id),
                'actor_persona_id' => $payload['actor_persona_id'] ?? $this->resolveActorPersonaId($user?->id),
                'operacion' => Str::upper(Str::substr((string) ($payload['operacion'] ?? 'U'), 0, 1)),
                'esquema' => $payload['esquema'] ?? 'train_gimnasio',
                'tabla' => $table,
                'modulo' => $module,
                'accion' => $payload['accion'] ?? null,
                'registro_id' => $payload['registro_id'] ?? null,
                'datos_antes' => $this->toJsonValue($payload['datos_antes'] ?? null),
                'datos_despues' => $this->toJsonValue($payload['datos_despues'] ?? null),
                'campos_cambiados' => $this->toJsonValue(
                    $payload['campos_cambiados'] ?? $this->calculateChanges(
                        $payload['datos_antes'] ?? null,
                        $payload['datos_despues'] ?? null
                    )
                ),
                'request_id' => $payload['request_id'] ?? $request->headers->get('X-Request-ID') ?? (string) Str::uuid(),
                'ip' => $request->ip(),
                'user_agent' => $userAgent,
                'created_at' => now(),
                'registro_pk' => $this->toJsonValue($payload['registro_pk'] ?? ['id' => $payload['registro_id'] ?? null]),
                'ip_publica' => null,
                'ip_forwarded_for' => $request->headers->get('X-Forwarded-For'),
                'proxy_headers' => $this->toJsonValue([
                    'x_forwarded_for' => $request->headers->get('X-Forwarded-For'),
                    'x_real_ip' => $request->headers->get('X-Real-IP'),
                    'cf_connecting_ip' => $request->headers->get('CF-Connecting-IP'),
                ]),
                'tipo_dispositivo' => $device,
                'sistema_operativo' => $this->detectOperatingSystem($userAgent),
                'navegador' => $this->detectBrowser($userAgent),
                'equipo_nombre' => $request->headers->get('X-Client-Host'),
                'equipo_usuario' => $request->headers->get('X-Client-User'),
                'ip_bd' => null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('No se pudo registrar auditoría', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function toJsonValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value) || is_object($value)) {
            return json_decode(json_encode($value, JSON_UNESCAPED_UNICODE), true);
        }

        return $value;
    }

    private function calculateChanges(mixed $before, mixed $after): array
    {
        $beforeArray = $this->normalizeArray($before);
        $afterArray = $this->normalizeArray($after);
        $keys = array_unique(array_merge(array_keys($beforeArray), array_keys($afterArray)));

        $changes = [];
        foreach ($keys as $key) {
            $beforeValue = $beforeArray[$key] ?? null;
            $afterValue = $afterArray[$key] ?? null;

            if ($beforeValue !== $afterValue) {
                $changes[$key] = [
                    'antes' => $beforeValue,
                    'despues' => $afterValue,
                ];
            }
        }

        return $changes;
    }

    private function normalizeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return json_decode(json_encode($value, JSON_UNESCAPED_UNICODE), true) ?? [];
        }

        return [];
    }

    private function resolveActorRoleId(?int $userId): ?int
    {
        if (!$userId) {
            return null;
        }

        return DB::table('train_gimnasio.auth_usuario_roles')
            ->where('usuario_id', $userId)
            ->orderBy('id')
            ->value('rol_id');
    }

    private function resolveActorPersonaId(?int $userId): ?int
    {
        if (!$userId) {
            return null;
        }

        return DB::table('train_gimnasio.auth_usuarios')
            ->where('id', $userId)
            ->value('persona_id');
    }

    private function resolveModule(?string $table): string
    {
        if (!$table) {
            return 'general';
        }

        return self::MODULE_MAP[$table] ?? 'general';
    }

    private function detectDeviceType(string $userAgent): string
    {
        $agent = Str::lower($userAgent);

        if (str_contains($agent, 'mobile') || str_contains($agent, 'android') || str_contains($agent, 'iphone')) {
            return 'mobile';
        }

        if (str_contains($agent, 'ipad') || str_contains($agent, 'tablet')) {
            return 'tablet';
        }

        return 'desktop';
    }

    private function detectOperatingSystem(string $userAgent): string
    {
        $agent = Str::lower($userAgent);

        return match (true) {
            str_contains($agent, 'windows') => 'Windows',
            str_contains($agent, 'mac os') || str_contains($agent, 'macintosh') => 'macOS',
            str_contains($agent, 'android') => 'Android',
            str_contains($agent, 'iphone') || str_contains($agent, 'ipad') || str_contains($agent, 'ios') => 'iOS',
            str_contains($agent, 'linux') => 'Linux',
            default => 'Desconocido',
        };
    }

    private function detectBrowser(string $userAgent): string
    {
        $agent = Str::lower($userAgent);

        return match (true) {
            str_contains($agent, 'edg') => 'Edge',
            str_contains($agent, 'chrome') && !str_contains($agent, 'edg') => 'Chrome',
            str_contains($agent, 'firefox') => 'Firefox',
            str_contains($agent, 'safari') && !str_contains($agent, 'chrome') => 'Safari',
            default => 'Desconocido',
        };
    }
}
