<?php

namespace App\Services\Inventarios;

use App\Queries\Inventarios\ProductoPrecioQuery;
use App\Queries\Inventarios\ProductoQuery;
use App\Services\Audit\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProductoPrecioService
{
    private const ALLOWED_TYPES = ['COSTO', 'VENTA', 'SOCIO', 'PROMOCION'];

    public function __construct(
        private ProductoPrecioQuery $productoPrecioQuery,
        private ProductoQuery $productoQuery,
        private AuditService $auditService
    ) {
    }

    public function byProducto(int $productoId): array
    {
        return $this->productoPrecioQuery->byProducto($productoId);
    }

    public function create(int $productoId, array $input, Request $request): array
    {
        $producto = $this->productoQuery->find($productoId);

        if (!$producto) {
          throw new RuntimeException('Producto no encontrado');
        }

        $payload = $this->normalizePayload($input);
        $userId = (int) ($request->user()?->id ?? 0);

        return DB::transaction(function () use ($productoId, $payload, $request, $userId) {
            $this->closeCurrentActivePrice($productoId, $payload['tipo_precio'], $payload['sede_id'], $payload['vigencia_inicio'], $userId);

            $priceId = (int) DB::table('inventario.producto_precios')->insertGetId([
                'producto_id' => $productoId,
                'sede_id' => $payload['sede_id'],
                'tipo_precio' => $payload['tipo_precio'],
                'moneda' => 'USD',
                'monto' => $payload['monto'],
                'vigencia_inicio' => $payload['vigencia_inicio'],
                'vigencia_fin' => $payload['vigencia_fin'],
                'estado' => $payload['estado'],
                'created_by' => $userId,
                'updated_by' => $userId ?: null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $created = $this->productoPrecioQuery->find($priceId);
            $this->auditService->created($request, 'producto_precios', $priceId, $created, [
                'modulo' => 'inventarios',
                'accion' => 'registrar_precio_producto',
                'sede_id' => $payload['sede_id'],
            ]);

            return $created;
        });
    }

    public function update(int $id, array $input, Request $request): ?array
    {
        $before = $this->productoPrecioQuery->find($id);

        if (!$before) {
            return null;
        }

        $payload = $this->normalizePayload($input);
        $userId = (int) ($request->user()?->id ?? 0);

        return DB::transaction(function () use ($id, $before, $payload, $request, $userId) {
            DB::table('inventario.producto_precios')
                ->where('id', $id)
                ->update([
                    'vigencia_fin' => now(),
                    'estado' => 0,
                    'updated_by' => $userId ?: null,
                    'updated_at' => now(),
                ]);

            $this->closeCurrentActivePrice($before['producto_id'], $payload['tipo_precio'], $payload['sede_id'], $payload['vigencia_inicio'], $userId);

            $newId = (int) DB::table('inventario.producto_precios')->insertGetId([
                'producto_id' => $before['producto_id'],
                'sede_id' => $payload['sede_id'],
                'tipo_precio' => $payload['tipo_precio'],
                'moneda' => 'USD',
                'monto' => $payload['monto'],
                'vigencia_inicio' => $payload['vigencia_inicio'],
                'vigencia_fin' => $payload['vigencia_fin'],
                'estado' => $payload['estado'],
                'created_by' => $userId,
                'updated_by' => $userId ?: null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $after = $this->productoPrecioQuery->find($newId);
            $this->auditService->updated($request, 'producto_precios', $newId, $before, $after, [
                'modulo' => 'inventarios',
                'accion' => 'versionar_precio_producto',
                'sede_id' => $payload['sede_id'],
            ]);

            return $after;
        });
    }

    public function delete(int $id, Request $request): ?array
    {
        $before = $this->productoPrecioQuery->find($id);

        if (!$before) {
            return null;
        }

        DB::table('inventario.producto_precios')
            ->where('id', $id)
            ->update([
                'estado' => 0,
                'vigencia_fin' => now(),
                'updated_by' => $request->user()?->id,
                'updated_at' => now(),
            ]);

        $after = $this->productoPrecioQuery->find($id);

        $this->auditService->deleted($request, 'producto_precios', $id, $before, $after, [
            'modulo' => 'inventarios',
            'accion' => 'desactivar_precio_producto',
            'sede_id' => $before['sede_id'],
        ]);

        return $after;
    }

    private function normalizePayload(array $input): array
    {
        $tipo = strtoupper(trim((string) ($input['tipo_precio'] ?? '')));

        if (!in_array($tipo, self::ALLOWED_TYPES, true)) {
            throw new RuntimeException('Tipo de precio no soportado');
        }

        $vigenciaInicio = !empty($input['vigencia_inicio']) ? (string) $input['vigencia_inicio'] : now()->toDateString();
        $vigenciaFin = !empty($input['vigencia_fin']) ? (string) $input['vigencia_fin'] : null;

        if ($vigenciaFin && $vigenciaFin < $vigenciaInicio) {
            throw new RuntimeException('La vigencia final no puede ser menor a la vigencia inicial');
        }

        if ($tipo === 'PROMOCION' && !$vigenciaFin) {
            throw new RuntimeException('La promoción debe tener fecha fin');
        }

        return [
            'tipo_precio' => $tipo,
            'sede_id' => !empty($input['sede_id']) ? (int) $input['sede_id'] : null,
            'monto' => (float) ($input['monto'] ?? 0),
            'vigencia_inicio' => $vigenciaInicio,
            'vigencia_fin' => $vigenciaFin,
            'estado' => isset($input['estado']) && (int) $input['estado'] === 0 ? 0 : 1,
        ];
    }

    private function closeCurrentActivePrice(int $productoId, string $tipo, ?int $sedeId, string $vigenciaInicio, int $userId): void
    {
        $query = DB::table('inventario.producto_precios')
            ->where('producto_id', $productoId)
            ->where('tipo_precio', $tipo)
            ->where('estado', 1);

        if ($sedeId) {
            $query->where('sede_id', $sedeId);
        } else {
            $query->whereNull('sede_id');
        }

        $current = $query
            ->orderByDesc('vigencia_inicio')
            ->orderByDesc('id')
            ->first();

        if (!$current) {
            return;
        }

        DB::table('inventario.producto_precios')
            ->where('id', $current->id)
            ->update([
                'vigencia_fin' => $vigenciaInicio,
                'estado' => 0,
                'updated_by' => $userId ?: null,
                'updated_at' => now(),
            ]);
    }

}
