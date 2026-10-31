<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Importacion comercial unica y transaccional de REVIVE.
     * Nunca importa IDs locales, existencias, lotes ni movimientos.
     * El rollback automatico de PostgreSQL revierte todas las inserciones si algo falla.
     */
    public function up(): void
    {
        $archivo = database_path('seed-data/revive_catalogo_2026_10_31.json');
        if (! is_file($archivo)) {
            throw new RuntimeException('No se encontro el catalogo REVIVE para produccion.');
        }

        $datos = json_decode(file_get_contents($archivo), true, 512, JSON_THROW_ON_ERROR);
        $productos = $datos['productos'] ?? null;
        $precios = $datos['precios_por_sede'] ?? null;

        if (($datos['formato'] ?? null) !== 'revive_catalogo_produccion_2026_10_31'
            || ! is_array($productos) || count($productos) !== 12
            || ! is_array($precios) || count($precios) !== 24) {
            throw new RuntimeException('Archivo de catalogo inesperado; se cancela la importacion.');
        }

        DB::transaction(function () use ($productos, $precios): void {
            // Protege el mapeo contra cambios simultaneos y claves duplicadas.
            DB::statement('LOCK TABLE inventario.categorias_producto, inventario.productos, inventario.producto_precios_sede IN SHARE ROW EXCLUSIVE MODE');

            $normalizar = fn (string $valor): string => mb_strtolower(trim($valor), 'UTF-8');
            $catalogo = [];
            $categoriasOrigen = [];

            foreach ($productos as $p) {
                $codigo = trim((string) ($p['codigo'] ?? ''));
                $nombre = trim((string) ($p['nombre'] ?? ''));
                $categoria = trim((string) ($p['categoria'] ?? ''));
                $precio = $p['precio_venta'] ?? null;
                if ($codigo === '' || strlen($codigo) > 60 || $nombre === '' || mb_strlen($nombre) > 160
                    || $categoria === '' || ! is_numeric($precio) || $precio < 0 || $precio > 9999999999.99) {
                    throw new RuntimeException('Producto o precio base invalido en el catalogo REVIVE.');
                }
                $clave = $normalizar($codigo);
                if (isset($catalogo[$clave])) {
                    throw new RuntimeException('Codigo duplicado en el catalogo de origen: ' . $codigo);
                }
                $catalogo[$clave] = $p;
                $categoriasOrigen[$normalizar($categoria)] = $categoria;
            }

            $categoriasDestino = [];
            foreach (DB::table('inventario.categorias_producto')->get(['id', 'nombre']) as $c) {
                $clave = $normalizar((string) $c->nombre);
                if (isset($categoriasDestino[$clave])) {
                    throw new RuntimeException('Categorias ambiguas en la base de produccion.');
                }
                $categoriasDestino[$clave] = (int) $c->id;
            }

            $sedesDestino = [];
            foreach (DB::table('institucional.sedes')->get(['id_sede', 'nombre', 'activo', 'maneja_inventario']) as $s) {
                $clave = $normalizar((string) $s->nombre);
                if (isset($sedesDestino[$clave])) {
                    throw new RuntimeException('Nombres de sedes ambiguos en la base de produccion.');
                }
                $sedesDestino[$clave] = $s;
            }

            $productosDestino = [];
            foreach (DB::table('inventario.productos')->get(['id', 'codigo', 'nombre']) as $p) {
                $clave = $normalizar((string) $p->codigo);
                if (isset($productosDestino[$clave])) {
                    throw new RuntimeException('Codigos de productos ambiguos en produccion.');
                }
                $productosDestino[$clave] = $p;
            }

            // Preflight completo ANTES de insertar: nombres, sedes y precios validos.
            $clavesPrecios = [];
            foreach ($catalogo as $codigo => $p) {
                if (isset($productosDestino[$codigo]) &&
                    $normalizar((string) $productosDestino[$codigo]->nombre) !== $normalizar((string) $p['nombre'])) {
                    throw new RuntimeException('Conflicto de codigo de producto en produccion: ' . $p['codigo']);
                }
            }
            foreach ($precios as $precio) {
                $codigo = $normalizar((string) ($precio['producto_codigo'] ?? ''));
                $sede = $normalizar((string) ($precio['sede_nombre'] ?? ''));
                $monto = $precio['precio'] ?? null;
                if (! isset($catalogo[$codigo], $sedesDestino[$sede]) ||
                    ! $sedesDestino[$sede]->activo || ! $sedesDestino[$sede]->maneja_inventario ||
                    ! is_numeric($monto) || $monto < 0 || $monto > 9999999999.99) {
                    throw new RuntimeException('Precio o equivalencia de sede invalida; no se modifico produccion.');
                }
                $clave = $codigo . '|' . $sede;
                if (isset($clavesPrecios[$clave])) {
                    throw new RuntimeException('Tarifa repetida por producto y sede en el origen.');
                }
                $clavesPrecios[$clave] = true;
            }

            // Solo inserta categorias realmente faltantes; no altera las ya existentes.
            foreach ($categoriasOrigen as $clave => $nombre) {
                if (! isset($categoriasDestino[$clave])) {
                    $categoriasDestino[$clave] = (int) DB::table('inventario.categorias_producto')->insertGetId([
                        'nombre' => $nombre,
                        'descripcion' => 'Categoria comercial REVIVE.',
                        'activo' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // IDs generados por PostgreSQL. No se inserta stock por sede ni lotes.
            foreach ($catalogo as $codigo => $p) {
                if (isset($productosDestino[$codigo])) {
                    continue; // Nunca sobrescribir un producto que ya existe en produccion.
                }
                $imagen = $p['imagen_url'] ?? null;
                $imagen = is_string($imagen) && (
                    str_starts_with($imagen, 'data:image/jpeg;base64,') ||
                    str_starts_with($imagen, 'data:image/png;base64,') ||
                    str_starts_with($imagen, 'data:image/webp;base64,') ||
                    str_starts_with($imagen, 'https://')
                ) ? $imagen : null;

                $nuevoId = DB::table('inventario.productos')->insertGetId([
                    'categoria_id' => $categoriasDestino[$normalizar($p['categoria'])],
                    'proveedor_id' => null,
                    'codigo' => $p['codigo'],
                    'nombre' => $p['nombre'],
                    'descripcion' => $p['descripcion'] ?? null,
                    'marca' => $p['marca'] ?? null,
                    'imagen_url' => $imagen,
                    'unidad_medida' => $p['unidad_medida'] ?? 'UNIDAD',
                    'precio_costo' => 0,
                    'precio_venta' => $p['precio_venta'],
                    'stock_actual' => 0,
                    'stock_minimo' => 0,
                    'controla_stock' => true,
                    'maneja_lotes' => true,
                    'activo' => (bool) ($p['activo'] ?? true),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $productosDestino[$codigo] = (object) ['id' => $nuevoId, 'codigo' => $p['codigo'], 'nombre' => $p['nombre']];
            }

            // No actualiza tarifas previamente configuradas en produccion.
            foreach ($precios as $precio) {
                $idProducto = (int) $productosDestino[$normalizar($precio['producto_codigo'])]->id;
                $idSede = (int) $sedesDestino[$normalizar($precio['sede_nombre'])]->id_sede;
                $existe = DB::table('inventario.producto_precios_sede')
                    ->where('producto_id', $idProducto)->where('sede_id', $idSede)->exists();
                if (! $existe) {
                    DB::table('inventario.producto_precios_sede')->insert([
                        'producto_id' => $idProducto,
                        'sede_id' => $idSede,
                        'precio' => $precio['precio'],
                        'activo' => (bool) ($precio['activo'] ?? true),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            foreach ($precios as $precio) {
                $idProducto = (int) $productosDestino[$normalizar($precio['producto_codigo'])]->id;
                $idSede = (int) $sedesDestino[$normalizar($precio['sede_nombre'])]->id_sede;
                if (! DB::table('inventario.producto_precios_sede')
                    ->where('producto_id', $idProducto)->where('sede_id', $idSede)->exists()) {
                    throw new RuntimeException('Fallo la verificacion posterior de precios; se revierte la transaccion.');
                }
            }
        });
    }

    public function down(): void
    {
        // Intencional: no eliminar productos comerciales que podrian tener ventas o FK.
        // Una reversión debe conciliarse manualmente con auditoría y respaldo.
    }
};
