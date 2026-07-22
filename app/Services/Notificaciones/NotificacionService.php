<?php

namespace App\Services\Notificaciones;

use App\Events\Notificaciones\NotificacionCreada;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificacionService
{
    public function crear(array $data, ?int $createdByUserId = null): array
    {
        if (!$this->hasNotificationTables()) {
            return [];
        }

        return DB::transaction(function () use ($data, $createdByUserId) {
            $notificacionId = DB::table('notificaciones.notificaciones')->insertGetId([
                'tipo' => $data['tipo'] ?? 'GENERAL',
                'titulo' => $data['titulo'],
                'mensaje' => $data['mensaje'],
                'data' => json_encode($data['data'] ?? []),
                'canal_default' => $data['canal'] ?? 'APP',
                'prioridad' => $data['prioridad'] ?? 'NORMAL',
                'programada_para' => $data['programada_para'] ?? null,
                'created_by_usuario_id' => $createdByUserId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $usuarios = collect($data['usuarios'] ?? [])->filter()->unique()->values();
            $personas = collect($data['personas'] ?? [])->filter()->unique()->values();
            $canal = $data['canal'] ?? 'APP';
            $rows = [];

            foreach ($usuarios as $usuarioId) {
                $personaId = DB::table('seguridad.usuarios')->where('id', (int) $usuarioId)->value('persona_id');
                $rows[] = [
                    'notificacion_id' => $notificacionId,
                    'usuario_id' => (int) $usuarioId,
                    'persona_id' => $personaId ? (int) $personaId : null,
                    'canal' => $canal,
                    'estado' => 'PENDIENTE',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            foreach ($personas as $personaId) {
                $usuarioId = DB::table('seguridad.usuarios')->where('persona_id', (int) $personaId)->value('id');
                $rows[] = [
                    'notificacion_id' => $notificacionId,
                    'usuario_id' => $usuarioId ? (int) $usuarioId : null,
                    'persona_id' => (int) $personaId,
                    'canal' => $canal,
                    'estado' => 'PENDIENTE',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if (!empty($rows)) {
                DB::table('notificaciones.destinatarios')->insert($rows);
            }

            $notificacion = $this->obtener($notificacionId);
            $this->broadcastNotificacion($notificacion, $rows);

            return $notificacion;
        });
    }

    public function listarParaUsuario(?int $usuarioId, ?int $personaId, int $limit = 30): array
    {
        if (!$this->hasNotificationTables()) {
            return [];
        }

        return DB::table('notificaciones.destinatarios as d')
            ->join('notificaciones.notificaciones as n', 'n.id', '=', 'd.notificacion_id')
            ->where(function ($query) use ($usuarioId, $personaId) {
                if ($usuarioId) {
                    $query->orWhere('d.usuario_id', $usuarioId);
                }
                if ($personaId) {
                    $query->orWhere('d.persona_id', $personaId);
                }
            })
            ->selectRaw("
                d.id as destinatario_id,
                n.id,
                n.tipo,
                n.titulo,
                n.mensaje,
                n.data,
                n.prioridad,
                d.canal,
                d.estado,
                d.leida_at,
                n.created_at
            ")
            ->orderByRaw('CASE WHEN d.leida_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('n.created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($item) => $this->mapNotificacion($item))
            ->all();
    }

    public function contarNoLeidas(?int $usuarioId, ?int $personaId): int
    {
        if (!$this->hasNotificationTables()) {
            return 0;
        }

        return (int) DB::table('notificaciones.destinatarios')
            ->whereNull('leida_at')
            ->where(function ($query) use ($usuarioId, $personaId) {
                if ($usuarioId) {
                    $query->orWhere('usuario_id', $usuarioId);
                }
                if ($personaId) {
                    $query->orWhere('persona_id', $personaId);
                }
            })
            ->count();
    }

    public function marcarLeida(int $destinatarioId, ?int $usuarioId, ?int $personaId): bool
    {
        if (!$this->hasNotificationTables()) {
            return false;
        }

        $updated = DB::table('notificaciones.destinatarios')
            ->where('id', $destinatarioId)
            ->where(function ($query) use ($usuarioId, $personaId) {
                if ($usuarioId) {
                    $query->orWhere('usuario_id', $usuarioId);
                }
                if ($personaId) {
                    $query->orWhere('persona_id', $personaId);
                }
            })
            ->update([
                'estado' => 'LEIDA',
                'leida_at' => now(),
                'updated_at' => now(),
            ]);

        return $updated > 0;
    }

    public function marcarTodasLeidas(?int $usuarioId, ?int $personaId): int
    {
        if (!$this->hasNotificationTables() || (!$usuarioId && !$personaId)) {
            return 0;
        }

        return DB::table('notificaciones.destinatarios')
            ->whereNull('leida_at')
            ->where(function ($query) use ($usuarioId, $personaId) {
                if ($usuarioId) {
                    $query->orWhere('usuario_id', $usuarioId);
                }
                if ($personaId) {
                    $query->orWhere('persona_id', $personaId);
                }
            })
            ->update([
                'estado' => 'LEIDA',
                'leida_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function registrarDispositivo(?int $usuarioId, ?int $personaId, string $plataforma, string $token): void
    {
        if (!$this->hasNotificationTables()) {
            return;
        }

        DB::table('notificaciones.dispositivos_push')->updateOrInsert(
            ['token' => $token],
            [
                'usuario_id' => $usuarioId,
                'persona_id' => $personaId,
                'plataforma' => $plataforma,
                'activo' => true,
                'last_seen_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function despacharPushPendientes(int $limit = 100): array
    {
        if (!$this->hasNotificationTables() || !config('services.expo_push.enabled', true)) {
            return ['enviadas' => 0, 'errores' => 0, 'sin_dispositivo' => 0];
        }

        $destinatarios = DB::table('notificaciones.destinatarios as d')
            ->join('notificaciones.notificaciones as n', 'n.id', '=', 'd.notificacion_id')
            ->where('d.estado', 'PENDIENTE')
            ->whereIn('d.canal', ['APP', 'PUSH'])
            ->where(function ($query) {
                $query->whereNull('n.programada_para')
                    ->orWhere('n.programada_para', '<=', now());
            })
            ->selectRaw('
                d.id as destinatario_id,
                d.usuario_id,
                d.persona_id,
                n.id as notificacion_id,
                n.tipo,
                n.titulo,
                n.mensaje,
                n.data,
                n.prioridad
            ')
            ->orderBy('d.id')
            ->limit($limit)
            ->get();

        $resultado = ['enviadas' => 0, 'errores' => 0, 'sin_dispositivo' => 0];

        foreach ($destinatarios as $destinatario) {
            $tokens = $this->tokensActivos($destinatario->usuario_id ? (int) $destinatario->usuario_id : null, $destinatario->persona_id ? (int) $destinatario->persona_id : null);

            if ($tokens->isEmpty()) {
                DB::table('notificaciones.destinatarios')
                    ->where('id', (int) $destinatario->destinatario_id)
                    ->update([
                        'estado' => 'SIN_DISPOSITIVO',
                        'error' => 'No existe un dispositivo push activo para el destinatario.',
                        'updated_at' => now(),
                    ]);
                $resultado['sin_dispositivo']++;
                continue;
            }

            $payload = $tokens->map(fn (string $token) => [
                'to' => $token,
                'sound' => 'default',
                'title' => $destinatario->titulo,
                'body' => $destinatario->mensaje,
                'priority' => $destinatario->prioridad === 'ALTA' ? 'high' : 'default',
                'data' => [
                    'notificacion_id' => (int) $destinatario->notificacion_id,
                    'destinatario_id' => (int) $destinatario->destinatario_id,
                    'tipo' => $destinatario->tipo,
                    ...($this->decodeJson($destinatario->data) ?: []),
                ],
            ])->values()->all();

            try {
                $response = Http::timeout(10)->post(config('services.expo_push.url'), count($payload) === 1 ? $payload[0] : $payload);

                if (!$response->successful()) {
                    throw new \RuntimeException($response->body());
                }

                DB::table('notificaciones.destinatarios')
                    ->where('id', (int) $destinatario->destinatario_id)
                    ->update([
                        'estado' => 'ENVIADA',
                        'entregada_at' => now(),
                        'error' => null,
                        'updated_at' => now(),
                    ]);

                DB::table('notificaciones.notificaciones')
                    ->where('id', (int) $destinatario->notificacion_id)
                    ->whereNull('enviada_en')
                    ->update([
                        'enviada_en' => now(),
                        'updated_at' => now(),
                    ]);

                $resultado['enviadas']++;
            } catch (\Throwable $exception) {
                DB::table('notificaciones.destinatarios')
                    ->where('id', (int) $destinatario->destinatario_id)
                    ->update([
                        'estado' => 'ERROR',
                        'error' => mb_substr($exception->getMessage(), 0, 900),
                        'updated_at' => now(),
                    ]);

                Log::warning('No se pudo despachar push Expo.', [
                    'destinatario_id' => (int) $destinatario->destinatario_id,
                    'error' => $exception->getMessage(),
                ]);

                $resultado['errores']++;
            }
        }

        return $resultado;
    }

    public function existeDedupeKey(string $dedupeKey): bool
    {
        if (!$this->hasNotificationTables()) {
            return false;
        }

        return DB::table('notificaciones.notificaciones')
            ->whereRaw("data->>'dedupe_key' = ?", [$dedupeKey])
            ->exists();
    }

    private function obtener(int $id): array
    {
        if (!$this->hasNotificationTables()) {
            return [];
        }

        $item = DB::table('notificaciones.notificaciones')->where('id', $id)->first();

        return $item ? [
            'id' => (int) $item->id,
            'tipo' => $item->tipo,
            'titulo' => $item->titulo,
            'mensaje' => $item->mensaje,
            'data' => json_decode($item->data ?? '{}', true) ?: [],
            'prioridad' => $item->prioridad,
            'created_at' => $item->created_at,
        ] : [];
    }

    private function broadcastNotificacion(array $notificacion, array $destinatarios): void
    {
        if (empty($notificacion) || config('broadcasting.default') === 'null') {
            return;
        }

        $destinatariosPersistidos = DB::table('notificaciones.destinatarios')
            ->where('notificacion_id', $notificacion['id'])
            ->get(['id', 'usuario_id', 'persona_id', 'canal', 'estado', 'leida_at'])
            ->map(fn ($row) => [
                'destinatario_id' => (int) $row->id,
                'usuario_id' => $row->usuario_id ? (int) $row->usuario_id : null,
                'persona_id' => $row->persona_id ? (int) $row->persona_id : null,
                'canal' => $row->canal,
                'estado' => $row->estado,
                'leida' => !empty($row->leida_at),
                'leida_at' => $row->leida_at,
            ])
            ->all();

        foreach ($destinatariosPersistidos ?: $destinatarios as $destinatario) {
            try {
                broadcast(new NotificacionCreada(
                    array_merge($notificacion, [
                        'destinatario_id' => $destinatario['destinatario_id'] ?? null,
                        'canal' => $destinatario['canal'] ?? null,
                        'estado' => $destinatario['estado'] ?? null,
                        'leida' => (bool) ($destinatario['leida'] ?? false),
                        'leida_at' => $destinatario['leida_at'] ?? null,
                    ]),
                    $destinatario['usuario_id'] ? (int) $destinatario['usuario_id'] : null,
                    $destinatario['usuario_id'] ? null : ($destinatario['persona_id'] ? (int) $destinatario['persona_id'] : null),
                ));
            } catch (\Throwable $exception) {
                Log::warning('No se pudo emitir notificacion por Reverb.', [
                    'notificacion_id' => $notificacion['id'] ?? null,
                    'usuario_id' => $destinatario['usuario_id'] ?? null,
                    'persona_id' => $destinatario['persona_id'] ?? null,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function mapNotificacion(object $item): array
    {
        return [
            'destinatario_id' => (int) $item->destinatario_id,
            'id' => (int) $item->id,
            'tipo' => $item->tipo,
            'titulo' => $item->titulo,
            'mensaje' => $item->mensaje,
            'data' => json_decode($item->data ?? '{}', true) ?: [],
            'prioridad' => $item->prioridad,
            'canal' => $item->canal,
            'estado' => $item->estado,
            'leida' => !empty($item->leida_at),
            'leida_at' => $item->leida_at,
            'created_at' => $item->created_at,
        ];
    }

    private function tokensActivos(?int $usuarioId, ?int $personaId)
    {
        return DB::table('notificaciones.dispositivos_push')
            ->where('activo', true)
            ->where(function ($query) use ($usuarioId, $personaId) {
                if ($usuarioId) {
                    $query->orWhere('usuario_id', $usuarioId);
                }
                if ($personaId) {
                    $query->orWhere('persona_id', $personaId);
                }
            })
            ->pluck('token')
            ->filter()
            ->unique()
            ->values();
    }

    private function decodeJson(?string $json): array
    {
        if (!$json) {
            return [];
        }

        return json_decode($json, true) ?: [];
    }

    private function hasNotificationTables(): bool
    {
        $row = DB::selectOne("SELECT to_regclass('notificaciones.destinatarios') as destinatarios");

        return !empty($row?->destinatarios);
    }
}
