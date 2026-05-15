<?php

namespace App\Services\Inventarios;

use App\Queries\Inventarios\ProductoQuery;
use App\Services\Audit\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductoService
{
    private const PRODUCTO_LOCK = 6201;
    private const PRODUCTO_PRECIO_LOCK = 6202;
    private const PRODUCTO_STOCK_LOCK = 6203;

    public function __construct(
        private AuditService $auditService,
        private ProductoQuery $productoQuery
    )
    {
    }

    public function all(): array
    {
        return $this->productoQuery->all();
    }

    public function find(int $id): ?array
    {
        return $this->productoQuery->find($id);
    }

    public function create(array $input, Request $request): array
    {
        return DB::transaction(function () use ($input, $request) {
            $payload = $this->normalizePayload($input);
            $userId = (int) ($request->user()?->id ?? 0);

            $productoId = $this->nextId('train_gimnasio.productos', self::PRODUCTO_LOCK);
            $codigo = $payload['codigo'] !== '' ? $payload['codigo'] : $this->generateCodigo($productoId);
            $imageUrl = $this->resolveImageUrl($payload, $request);

            $this->ensureCodigoIsUnique($codigo);

            DB::table('train_gimnasio.productos')->insert([
                'id' => $productoId,
                'codigo' => $codigo,
                'nombre' => $payload['nombre'],
                'descripcion' => $payload['descripcion'],
                'categoria_id' => $payload['categoria_id'],
                'marca' => $payload['marca'],
                'modelo' => $payload['modelo'],
                'sku' => $payload['sku'],
                'codigo_barras' => $payload['codigo_barras'],
                'unidad_medida' => $payload['unidad_medida'],
                'controla_stock' => $payload['controla_stock'],
                'permite_decimales' => $payload['permite_decimales'],
                'maneja_lotes' => $payload['maneja_lotes'],
                'maneja_vencimiento' => $payload['maneja_vencimiento'],
                'stock_minimo' => $payload['stock_minimo'],
                'stock_maximo' => $payload['stock_maximo'],
                'estado' => $payload['estado'],
                'imagen_url' => $imageUrl,
                'created_by' => $userId,
                'updated_by' => $userId ?: null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->syncActivePrice($productoId, 'COSTO', $payload['precio_costo'], $userId);
            $this->syncActivePrice($productoId, 'VENTA', $payload['precio_venta'], $userId);
            $this->syncPrincipalStock($productoId, $payload, $userId);

            $created = $this->find($productoId);

            $this->auditService->created($request, 'productos', $productoId, $created);

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

            $payload = $this->normalizePayload($input, $before);
            $codigo = $payload['codigo'] !== '' ? $payload['codigo'] : ($before['codigo'] ?? $this->generateCodigo($id));
            $imageUrl = $this->resolveImageUrl($payload, $request, $before['imagen_url'] ?? null);

            $this->ensureCodigoIsUnique($codigo, $id);

            $userId = (int) ($request->user()?->id ?? 0);

            DB::table('train_gimnasio.productos')
                ->where('id', $id)
                ->update([
                    'codigo' => $codigo,
                    'nombre' => $payload['nombre'],
                    'descripcion' => $payload['descripcion'],
                    'categoria_id' => $payload['categoria_id'],
                    'marca' => $payload['marca'],
                    'modelo' => $payload['modelo'],
                    'sku' => $payload['sku'],
                    'codigo_barras' => $payload['codigo_barras'],
                    'unidad_medida' => $payload['unidad_medida'],
                    'controla_stock' => $payload['controla_stock'],
                    'permite_decimales' => $payload['permite_decimales'],
                    'maneja_lotes' => $payload['maneja_lotes'],
                    'maneja_vencimiento' => $payload['maneja_vencimiento'],
                    'stock_minimo' => $payload['stock_minimo'],
                    'stock_maximo' => $payload['stock_maximo'],
                    'estado' => $payload['estado'],
                    'imagen_url' => $imageUrl,
                    'updated_by' => $userId ?: null,
                    'updated_at' => now(),
                ]);

            $this->syncActivePrice($id, 'COSTO', $payload['precio_costo'], $userId);
            $this->syncActivePrice($id, 'VENTA', $payload['precio_venta'], $userId);
            $this->syncPrincipalStock($id, $payload, $userId);

            $after = $this->find($id);

            $this->auditService->updated($request, 'productos', $id, $before, $after);

            return $after;
        });
    }

    public function delete(int $id, Request $request): ?array
    {
        $before = $this->find($id);
        if (!$before) {
            return null;
        }

        DB::table('train_gimnasio.productos')
            ->where('id', $id)
            ->update([
                'estado' => 0,
                'updated_by' => $request->user()?->id,
                'updated_at' => now(),
            ]);

        $after = $this->find($id);

        $this->auditService->deleted($request, 'productos', $id, $before, $after);

        return $after;
    }

    public function categories(): array
    {
        return $this->productoQuery->categories();
    }

    public function sedes(): array
    {
        return $this->productoQuery->sedes();
    }

    private function normalizePayload(array $input, ?array $before = null): array
    {
        return [
            'codigo' => trim((string) ($input['codigo'] ?? '')),
            'nombre' => trim((string) ($input['nombre'] ?? '')),
            'descripcion' => $this->nullableString($input['descripcion'] ?? null),
            'categoria_id' => (int) ($input['categoria_id'] ?? 0),
            'marca' => $this->nullableString($input['marca'] ?? null),
            'modelo' => $this->nullableString($input['modelo'] ?? null),
            'sku' => $this->nullableString($input['sku'] ?? null),
            'codigo_barras' => $this->nullableString($input['codigo_barras'] ?? null),
            'unidad_medida' => trim((string) ($input['unidad_medida'] ?? 'unidad')) ?: 'unidad',
            'controla_stock' => $this->toBool($input['controla_stock'] ?? true),
            'permite_decimales' => $this->toBool($input['permite_decimales'] ?? false),
            'maneja_lotes' => $this->toBool($input['maneja_lotes'] ?? false),
            'maneja_vencimiento' => $this->toBool($input['maneja_vencimiento'] ?? false),
            'stock_minimo' => $this->toDecimal($input['stock_minimo'] ?? 0),
            'stock_maximo' => $this->nullableDecimal($input['stock_maximo'] ?? null),
            'estado' => $this->toBool($input['estado'] ?? ($before['estado'] ?? 1)) ? 1 : 0,
            'imagen_url' => $this->nullableString($input['imagen_url'] ?? null),
            'remove_imagen' => $this->toBool($input['remove_imagen'] ?? false),
            'precio_costo' => $this->toDecimal($input['precio_costo'] ?? 0),
            'precio_venta' => $this->toDecimal($input['precio_venta'] ?? 0),
            'sede_id' => isset($input['sede_id']) && $input['sede_id'] !== '' ? (int) $input['sede_id'] : null,
            'stock_inicial' => $this->toDecimal($input['stock_inicial'] ?? 0),
            'stock_minimo_sede' => $this->toDecimal($input['stock_minimo_sede'] ?? ($input['stock_minimo'] ?? 0)),
            'ubicacion' => $this->nullableString($input['ubicacion'] ?? 'ALMACEN PRINCIPAL') ?? 'ALMACEN PRINCIPAL',
        ];
    }

    private function syncPrincipalStock(int $productoId, array $payload, int $userId): void
    {
        if (!$payload['sede_id']) {
            return;
        }

        $existing = DB::table('train_gimnasio.producto_stock_sede')
            ->where('producto_id', $productoId)
            ->where('sede_id', $payload['sede_id'])
            ->where('estado', 1)
            ->first();

        if ($existing) {
            DB::table('train_gimnasio.producto_stock_sede')
                ->where('id', $existing->id)
                ->update([
                    'stock_minimo' => $payload['stock_minimo_sede'],
                    'ubicacion' => $payload['ubicacion'],
                    'updated_by' => $userId ?: null,
                    'updated_at' => now(),
                ]);

            return;
        }

        $stockId = $this->nextId('train_gimnasio.producto_stock_sede', self::PRODUCTO_STOCK_LOCK);

        DB::table('train_gimnasio.producto_stock_sede')->insert([
            'id' => $stockId,
            'producto_id' => $productoId,
            'sede_id' => $payload['sede_id'],
            'stock_actual' => 0,
            'stock_reservado' => 0,
            'stock_disponible' => 0,
            'stock_minimo' => $payload['stock_minimo_sede'],
            'ubicacion' => $payload['ubicacion'],
            'estado' => 1,
            'created_by' => $userId,
            'updated_by' => $userId ?: null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function syncActivePrice(int $productoId, string $tipoPrecio, float $monto, int $userId): void
    {
        $current = DB::table('train_gimnasio.producto_precios')
            ->where('producto_id', $productoId)
            ->where('tipo_precio', $tipoPrecio)
            ->where('estado', 1)
            ->where(function ($query) {
                $query->whereNull('vigencia_fin')
                    ->orWhere('vigencia_fin', '>=', now());
            })
            ->orderByDesc('vigencia_inicio')
            ->orderByDesc('id')
            ->first();

        if ($current && (float) $current->monto === (float) $monto) {
            return;
        }

        if ($current) {
            DB::table('train_gimnasio.producto_precios')
                ->where('id', $current->id)
                ->update([
                    'vigencia_fin' => now(),
                    'updated_by' => $userId ?: null,
                    'updated_at' => now(),
                ]);
        }

        $priceId = $this->nextId('train_gimnasio.producto_precios', self::PRODUCTO_PRECIO_LOCK);

        DB::table('train_gimnasio.producto_precios')->insert([
            'id' => $priceId,
            'producto_id' => $productoId,
            'sede_id' => null,
            'tipo_precio' => $tipoPrecio,
            'moneda' => 'USD',
            'monto' => $monto,
            'vigencia_inicio' => now(),
            'vigencia_fin' => null,
            'estado' => 1,
            'created_by' => $userId,
            'updated_by' => $userId ?: null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensureCodigoIsUnique(string $codigo, ?int $exceptId = null): void
    {
        $query = DB::table('train_gimnasio.productos')->where('codigo', $codigo);

        if ($exceptId) {
            $query->where('id', '<>', $exceptId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'codigo' => 'Ya existe un producto con ese código.',
            ]);
        }
    }

    private function generateCodigo(int $id): string
    {
        return sprintf('PROD-%04d', $id);
    }

    private function resolveImageUrl(array $payload, Request $request, ?string $currentUrl = null): ?string
    {
        if ($request->hasFile('imagen')) {
            $uploadedFile = $request->file('imagen');
            $directory = public_path('uploads/productos');
            File::ensureDirectoryExists($directory);

            $filename = now()->format('YmdHis') . '_' . Str::random(12) . '.' . $uploadedFile->getClientOriginalExtension();
            $uploadedFile->move($directory, $filename);

            if ($currentUrl) {
                $this->deleteLocalImage($currentUrl);
            }

            return $this->publicUploadUrl($filename, $request);
        }

        if ($payload['remove_imagen']) {
            if ($currentUrl) {
                $this->deleteLocalImage($currentUrl);
            }

            return null;
        }

        if ($payload['imagen_url']) {
            if ($currentUrl && $currentUrl !== $payload['imagen_url']) {
                $this->deleteLocalImage($currentUrl);
            }

            return $payload['imagen_url'];
        }

        return $currentUrl;
    }

    private function publicUploadUrl(string $filename, Request $request): string
    {
        $baseUrl = rtrim(config('app.url') ?: $request->getSchemeAndHttpHost(), '/');

        return "{$baseUrl}/uploads/productos/{$filename}";
    }

    private function deleteLocalImage(string $url): void
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (!$path || !str_contains($path, '/uploads/productos/')) {
            return;
        }

        $fullPath = public_path(ltrim($path, '/'));

        if (File::exists($fullPath)) {
            File::delete($fullPath);
        }
    }

    private function nextId(string $table, int $lockKey): int
    {
        DB::select('SELECT pg_advisory_xact_lock(?)', [$lockKey]);
        $row = DB::selectOne("SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM {$table}");

        return (int) ($row->next_id ?? 1);
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? null : $normalized;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return in_array(mb_strtolower(trim((string) $value)), ['1', 'true', 't', 'si', 'sí', 'on'], true);
    }

    private function toDecimal(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return (float) $value;
    }

    private function nullableDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }
}
