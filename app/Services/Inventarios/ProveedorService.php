<?php

namespace App\Services\Inventarios;

use App\Queries\Inventarios\ProveedorQuery;
use App\Services\Audit\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProveedorService
{
    public function __construct(
        private ProveedorQuery $proveedorQuery,
        private AuditService $auditService
    )
    {
    }

    public function all(): array
    {
        return $this->proveedorQuery->all();
    }

    public function find(int $id): ?array
    {
        return $this->proveedorQuery->find($id);
    }

    public function create(array $input, Request $request): array
    {
        return DB::transaction(function () use ($input, $request) {
            $payload = $this->normalizePayload($input);
            $userId = (int) ($request->user()?->id ?? 0);

            $id = DB::table('inventario.proveedores')->insertGetId([
                'prov_ruc' => $payload['ruc'],
                'prov_nombre' => $payload['nombre'],
                'prov_direccion' => $payload['direccion'],
                'prov_telefono' => $payload['telefono'],
                'prov_correo' => $payload['correo'],
                'prov_estado' => $payload['estado'] ?? 1,
                'prov_id_usuario' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ], 'prov_id');

            $created = $this->find($id);

            $this->auditService->created($request, 'proveedores', $id, $created);

            return $created;
        });
    }

    public function update(int $id, array $input, Request $request): ?array
    {
        return DB::transaction(function () use ($id, $input, $request) {
            $before = $this->find($id);
            if (!$before) {
                return null;
            }

            $payload = $this->normalizePayload($input);
            $userId = (int) ($request->user()?->id ?? 0);

            DB::table('inventario.proveedores')->where('prov_id', $id)->update([
                'prov_ruc' => $payload['ruc'],
                'prov_nombre' => $payload['nombre'],
                'prov_direccion' => $payload['direccion'],
                'prov_telefono' => $payload['telefono'],
                'prov_correo' => $payload['correo'],
                'prov_estado' => $payload['estado'] ?? 1,
                'updated_at' => now(),
            ]);

            $after = $this->find($id);

            $this->auditService->updated($request, 'proveedores', $id, $before, $after);

            return $after;
        });
    }

    public function delete(int $id, Request $request): ?array
    {
        $before = $this->find($id);
        if (!$before) {
            return null;
        }

        DB::table('inventario.proveedores')->where('prov_id', $id)->update([
            'prov_estado' => 0,
            'updated_at' => now(),
        ]);

        $after = $this->find($id);

        $this->auditService->deleted($request, 'proveedores', $id, $before, $after);

        return $after;
    }

    private function normalizePayload(array $input): array
    {
        return [
            'ruc' => $this->nullableString($input, 'ruc'),
            'nombre' => $this->nullableString($input, 'nombre'),
            'direccion' => $this->nullableString($input, 'direccion'),
            'telefono' => $this->nullableString($input, 'telefono'),
            'correo' => $this->nullableString($input, 'correo'),
            'estado' => isset($input['estado']) ? (int) $input['estado'] : 1,
        ];
    }

    private function nullableString(array $input, string $key): ?string
    {
        return isset($input[$key]) && trim($input[$key]) !== '' ? trim($input[$key]) : null;
    }
}
