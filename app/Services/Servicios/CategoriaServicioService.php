<?php

namespace App\Services\Servicios;

use App\Queries\Servicios\CategoriaServicioQuery;
use App\Services\Audit\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CategoriaServicioService
{
    public function __construct(
        private AuditService $auditService,
        private CategoriaServicioQuery $categoriaServicioQuery
    )
    {
    }

    public function all(): array
    {
        return $this->categoriaServicioQuery->all();
    }

    public function find(int $id): ?array
    {
        return $this->categoriaServicioQuery->find($id);
    }

    public function create(array $input, Request $request): array
    {
        $payload = $this->normalizePayload($input);
        $userId = $request->user()?->id;

        $id = DB::table('train_gimnasio.categoria_servicios')->insertGetId([
            'nombre' => $payload['nombre'],
            'descripcion' => $payload['descripcion'],
            'estado_id' => $payload['estado_id'],
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $created = $this->find($id);

        $this->auditService->created($request, 'categoria_servicios', $id, $created);

        return $created;
    }

    public function update(int $id, array $input, Request $request): ?array
    {
        $before = $this->find($id);
        if (!$before) {
            return null;
        }

        $payload = $this->normalizePayload($input);

        DB::table('train_gimnasio.categoria_servicios')
            ->where('id', $id)
            ->update([
                'nombre' => $payload['nombre'],
                'descripcion' => $payload['descripcion'],
                'estado_id' => $payload['estado_id'],
                'user_id' => $request->user()?->id,
                'updated_at' => now(),
            ]);

        $after = $this->find($id);

        $this->auditService->updated($request, 'categoria_servicios', $id, $before, $after);

        return $after;
    }

    public function delete(int $id, Request $request): ?array
    {
        $before = $this->find($id);
        if (!$before) {
            return null;
        }

        DB::table('train_gimnasio.categoria_servicios')
            ->where('id', $id)
            ->update([
                'estado_id' => 9,
                'updated_at' => now(),
            ]);

        $after = $this->find($id);

        $this->auditService->deleted($request, 'categoria_servicios', $id, $before, $after);

        return $after;
    }

    public function serviciosByCategoria(int $categoriaId): array
    {
        return $this->categoriaServicioQuery->serviciosByCategoria($categoriaId);
    }

    private function normalizePayload(array $input): array
    {
        $estado = $input['estado_id'] ?? $input['select_id_estado'] ?? ($input['activo'] ?? true);
        $isActive = in_array($estado, [true, 1, '1', 8, '8'], true);

        return [
            'nombre' => trim((string) ($input['nombre'] ?? $input['txt_nombre'] ?? '')),
            'descripcion' => trim((string) ($input['descripcion'] ?? $input['txt_descripcion'] ?? '')),
            'estado_id' => $isActive ? 8 : 9,
        ];
    }
}
