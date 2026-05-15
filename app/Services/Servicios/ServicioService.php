<?php

namespace App\Services\Servicios;

use App\Queries\Servicios\ServicioQuery;
use App\Services\Audit\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ServicioService
{
    public function __construct(
        private AuditService $auditService,
        private ServicioQuery $servicioQuery
    )
    {
    }

    public function all(): array
    {
        return $this->servicioQuery->all();
    }

    public function find(int $id): ?array
    {
        return $this->servicioQuery->find($id);
    }

    public function create(array $input, Request $request): array
    {
        $payload = $this->normalizePayload($input);
        $userId = $request->user()?->id;

        $id = DB::table('train_gimnasio.tipos_servicios')->insertGetId([
            'nombre' => $payload['nombre'],
            'descripcion' => $payload['descripcion'],
            'breve_desc' => $payload['breve_desc'],
            'categoria_id' => $payload['categoria_id'],
            'estado_id' => $payload['estado_id'],
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $created = $this->find($id);

        $this->auditService->created($request, 'tipos_servicios', $id, $created);

        return $created;
    }

    public function update(int $id, array $input, Request $request): ?array
    {
        $before = $this->find($id);
        if (!$before) {
            return null;
        }

        $payload = $this->normalizePayload($input);

        DB::table('train_gimnasio.tipos_servicios')
            ->where('id', $id)
            ->update([
                'nombre' => $payload['nombre'],
                'descripcion' => $payload['descripcion'],
                'breve_desc' => $payload['breve_desc'],
                'categoria_id' => $payload['categoria_id'],
                'estado_id' => $payload['estado_id'],
                'user_id' => $request->user()?->id,
                'updated_at' => now(),
            ]);

        $after = $this->find($id);

        $this->auditService->updated($request, 'tipos_servicios', $id, $before, $after);

        return $after;
    }

    public function delete(int $id, Request $request): ?array
    {
        $before = $this->find($id);
        if (!$before) {
            return null;
        }

        DB::table('train_gimnasio.tipos_servicios')
            ->where('id', $id)
            ->update([
                'estado_id' => 0,
                'updated_at' => now(),
            ]);

        $after = $this->find($id);

        $this->auditService->deleted($request, 'tipos_servicios', $id, $before, $after);

        return $after;
    }

    private function normalizePayload(array $input): array
    {
        $nombre = trim((string) ($input['nombre'] ?? $input['txt_nombre'] ?? ''));
        $descripcion = trim((string) ($input['descripcion'] ?? $input['txt_descripcion'] ?? ''));
        $categoriaId = (int) ($input['categoria_id'] ?? $input['select_id_categoria'] ?? 0);
        $estado = $input['estado_id'] ?? $input['select_id_estado'] ?? ($input['activo'] ?? true);
        $isActive = in_array($estado, [true, 1, '1', 8, '8'], true);

        return [
            'nombre' => $nombre,
            'descripcion' => $descripcion,
            'breve_desc' => trim((string) ($input['breve_desc'] ?? ($nombre !== '' ? mb_substr($nombre, 0, 120) : ''))),
            'categoria_id' => $categoriaId,
            'estado_id' => $isActive ? 1 : 0,
        ];
    }
}
