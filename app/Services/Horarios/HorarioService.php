<?php

namespace App\Services\Horarios;

use App\Queries\Horarios\HorarioQuery;
use App\Services\Audit\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HorarioService
{
    public function __construct(
        private AuditService $auditService,
        private HorarioQuery $horarioQuery
    )
    {
    }

    public function all(): array
    {
        return $this->horarioQuery->all();
    }

    public function find(int $id): ?array
    {
        return $this->horarioQuery->find($id);
    }

    public function create(array $input, Request $request): array
    {
        $payload = $this->normalizePayload($input);

        $id = DB::transaction(function () use ($payload, $request) {
            $horarioId = DB::table('train_gimnasio.horarios_gym')->insertGetId([
                'sede_id' => $payload['sede_id'],
                'tipo_servicio_id' => $payload['tipo_servicio_id'],
                'hora_apertura' => $payload['hora_apertura'],
                'hora_cierre' => $payload['hora_cierre'],
                'capacidad_maxima' => $payload['capacidad_maxima'],
                'tiempo_turno_min' => $payload['tiempo_turno_min'],
                'tipo_usuario' => $payload['tipo_usuario'],
                'activo' => $payload['activo'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->syncDias($horarioId, $payload['dias_laborables']);

            return $horarioId;
        });

        $created = $this->find($id);

        $this->auditService->created($request, 'horarios_gym', $id, $created, [
            'sede_id' => $payload['sede_id'],
        ]);

        return $created;
    }

    public function update(int $id, array $input, Request $request): ?array
    {
        $before = $this->find($id);
        if (!$before) {
            return null;
        }

        $payload = $this->normalizePayload($input);

        DB::transaction(function () use ($id, $payload) {
            DB::table('train_gimnasio.horarios_gym')
                ->where('id', $id)
                ->update([
                    'sede_id' => $payload['sede_id'],
                    'tipo_servicio_id' => $payload['tipo_servicio_id'],
                    'hora_apertura' => $payload['hora_apertura'],
                    'hora_cierre' => $payload['hora_cierre'],
                    'capacidad_maxima' => $payload['capacidad_maxima'],
                    'tiempo_turno_min' => $payload['tiempo_turno_min'],
                    'tipo_usuario' => $payload['tipo_usuario'],
                    'activo' => $payload['activo'],
                    'updated_at' => now(),
                ]);

            $this->syncDias($id, $payload['dias_laborables']);
        });

        $after = $this->find($id);

        $this->auditService->updated($request, 'horarios_gym', $id, $before, $after, [
            'sede_id' => $payload['sede_id'],
        ]);

        return $after;
    }

    public function delete(int $id, Request $request): ?array
    {
        $before = $this->find($id);
        if (!$before) {
            return null;
        }

        DB::table('train_gimnasio.horarios_gym')
            ->where('id', $id)
            ->update([
                'activo' => false,
                'updated_at' => now(),
            ]);

        $after = $this->find($id);

        $this->auditService->deleted($request, 'horarios_gym', $id, $before, $after, [
            'sede_id' => $before['sede_id'] ?? null,
        ]);

        return $after;
    }

    private function syncDias(int $horarioId, array $dias): void
    {
        DB::table('train_gimnasio.horarios_gym_dias')
            ->where('horario_id', $horarioId)
            ->delete();

        $rows = collect($dias)
            ->map(fn ($dia) => (int) $dia)
            ->filter(fn ($dia) => $dia >= 1 && $dia <= 7)
            ->unique()
            ->sort()
            ->values()
            ->map(fn ($dia) => [
                'horario_id' => $horarioId,
                'dia_semana' => $dia,
            ])
            ->all();

        if ($rows) {
            DB::table('train_gimnasio.horarios_gym_dias')->insert($rows);
        }
    }

    private function normalizePayload(array $input): array
    {
        $dias = $input['dias_laborables'] ?? $input['dias'] ?? $input['tg_json_dias_laborables'] ?? [];

        if (is_string($dias)) {
            $decoded = json_decode($dias, true);
            $dias = is_array($decoded) ? $decoded : [];
        }

        return [
            'sede_id' => (int) ($input['sede_id'] ?? 1),
            'tipo_servicio_id' => (int) ($input['tipo_servicio_id'] ?? $input['tg_tipo_servicio'] ?? 0),
            'hora_apertura' => $input['hora_apertura'] ?? $input['tg_hora_apertura'] ?? null,
            'hora_cierre' => $input['hora_cierre'] ?? $input['tg_hora_cierre'] ?? null,
            'capacidad_maxima' => (int) ($input['capacidad_maxima'] ?? $input['tg_capacidad_maxima'] ?? 0),
            'tiempo_turno_min' => (int) ($input['tiempo_turno_min'] ?? $input['tg_tiempo_turno'] ?? 0),
            'tipo_usuario' => (int) ($input['tipo_usuario'] ?? $input['tg_tipo_usuario'] ?? 0),
            'dias_laborables' => array_values(array_unique(array_map('intval', $dias))),
            'activo' => filter_var($input['activo'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
        ];
    }
}
