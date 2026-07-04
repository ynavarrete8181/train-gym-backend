<?php

namespace App\Services\Ventas;

use App\Queries\Ventas\PuntoVentaBorradorQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PuntoVentaBorradorService
{
    public function __construct(
        private PuntoVentaBorradorQuery $borradorQuery,
        private PuntoVentaService $puntoVentaService
    ) {
    }

    public function listByUser(int $userId): array
    {
        return $this->borradorQuery->listByUser($userId);
    }

    public function save(array $input, Request $request): array
    {
        $userId = $request->user()?->id ?? 1;
        $payload = $this->normalizePayload($input);

        if (!DB::getSchemaBuilder()->hasTable('ventas.punto_venta_borradores')) {
            throw new RuntimeException('La tabla de borradores del punto de venta no esta disponible');
        }

        if (!empty($payload['id'])) {
            $existing = $this->borradorQuery->findById($payload['id'], $userId);
            if (!$existing) {
                throw new RuntimeException('No se encontro el borrador solicitado');
            }

            DB::table('ventas.punto_venta_borradores')
                ->where('id', $payload['id'])
                ->where('usuario_id', $userId)
                ->update([
                    'sede_id' => $payload['sede_id'],
                    'persona_id' => $payload['persona_id'],
                    'membresia_id' => $payload['membresia_id'],
                    'referencia' => $payload['referencia'],
                    'observacion' => $payload['observacion'],
                    'forma_pago' => $payload['forma_pago'],
                    'estado_pago' => 'BORRADOR',
                    'tipo_venta' => $payload['tipo_venta'],
                    'subtotal' => $payload['subtotal'],
                    'iva' => $payload['iva'],
                    'total' => $payload['total'],
                    'items' => json_encode($payload['items']),
                    'metadata' => json_encode($payload['metadata']),
                    'updated_at' => now(),
                ]);

            return $this->borradorQuery->findById($payload['id'], $userId) ?? $existing;
        }

        $draftId = DB::table('ventas.punto_venta_borradores')->insertGetId([
            'usuario_id' => $userId,
            'sede_id' => $payload['sede_id'],
            'persona_id' => $payload['persona_id'],
            'membresia_id' => $payload['membresia_id'],
            'referencia' => $payload['referencia'],
            'observacion' => $payload['observacion'],
            'forma_pago' => $payload['forma_pago'],
            'estado_pago' => 'BORRADOR',
            'tipo_venta' => $payload['tipo_venta'],
            'subtotal' => $payload['subtotal'],
            'iva' => $payload['iva'],
            'total' => $payload['total'],
            'items' => json_encode($payload['items']),
            'metadata' => json_encode($payload['metadata']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->borradorQuery->findById((int) $draftId, $userId) ?? [];
    }

    public function confirm(int $draftId, Request $request): array
    {
        $userId = $request->user()?->id ?? 1;
        $draft = $this->borradorQuery->findById($draftId, $userId);

        if (!$draft) {
            throw new RuntimeException('No se encontro el borrador solicitado');
        }

        $resultado = $this->puntoVentaService->procesar([
            'sede_id' => $draft['sede_id'],
            'persona_id' => $draft['persona_id'],
            'forma_pago' => $draft['forma_pago'],
            'estado_pago' => $draft['metadata']['estado_pago_final'] ?? 'PAGADO',
            'membresia_id' => $draft['membresia_id'],
            'referencia' => $draft['referencia'],
            'observacion' => $draft['observacion'],
            'items' => $draft['items'],
        ], $request);

        DB::table('ventas.punto_venta_borradores')
            ->where('id', $draftId)
            ->where('usuario_id', $userId)
            ->delete();

        return $resultado;
    }

    public function delete(int $draftId, Request $request): void
    {
        $userId = $request->user()?->id ?? 1;

        DB::table('ventas.punto_venta_borradores')
            ->where('id', $draftId)
            ->where('usuario_id', $userId)
            ->delete();
    }

    private function normalizePayload(array $input): array
    {
        $items = array_values(array_filter($input['items'] ?? [], fn ($row) => is_array($row)));
        $subtotal = 0;

        $items = array_map(function (array $row) use (&$subtotal) {
            $cantidad = (float) ($row['cantidad'] ?? 0);
            $precio = array_key_exists('precio_unitario', $row) && $row['precio_unitario'] !== null
                ? (float) $row['precio_unitario']
                : 0;
            $subtotal += round($cantidad * $precio, 2);

            return [
                'producto_id' => (int) ($row['producto_id'] ?? 0),
                'cantidad' => $cantidad,
                'precio_unitario' => $precio,
                'costo_unitario' => array_key_exists('costo_unitario', $row) && $row['costo_unitario'] !== null
                    ? (float) $row['costo_unitario']
                    : null,
                'tipo_precio' => !empty($row['tipo_precio']) ? strtoupper((string) $row['tipo_precio']) : null,
            ];
        }, $items);

        $membresiaPrecio = (float) ($input['membresia_precio'] ?? 0);
        $subtotal = round($subtotal + $membresiaPrecio, 2);

        return [
            'id' => !empty($input['id']) ? (int) $input['id'] : null,
            'sede_id' => (int) ($input['sede_id'] ?? 0),
            'persona_id' => !empty($input['persona_id']) ? (int) $input['persona_id'] : null,
            'membresia_id' => !empty($input['membresia_id']) ? (int) $input['membresia_id'] : null,
            'referencia' => trim((string) ($input['referencia'] ?? 'POS-' . now()->format('YmdHis'))),
            'observacion' => trim((string) ($input['observacion'] ?? 'Venta POS')),
            'forma_pago' => strtoupper(trim((string) ($input['forma_pago'] ?? 'EFECTIVO'))),
            'tipo_venta' => !empty($input['membresia_id']) && !empty($items) ? 'COMPUESTA' : (!empty($input['membresia_id']) ? 'MEMBRESIA' : 'CONSUMO'),
            'subtotal' => $subtotal,
            'iva' => 0,
            'total' => $subtotal,
            'items' => $items,
            'metadata' => [
                'estado_pago_final' => strtoupper(trim((string) ($input['estado_pago'] ?? 'PAGADO'))),
            ],
        ];
    }
}
