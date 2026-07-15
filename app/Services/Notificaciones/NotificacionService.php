<?php

namespace App\Services\Notificaciones;

use Illuminate\Support\Facades\DB;

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

            return $this->obtener($notificacionId);
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

    private function hasNotificationTables(): bool
    {
        $row = DB::selectOne("SELECT to_regclass('notificaciones.destinatarios') as destinatarios");

        return !empty($row?->destinatarios);
    }
}
