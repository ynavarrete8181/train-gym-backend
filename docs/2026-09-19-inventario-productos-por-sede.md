# Inventario de productos por sede — 2026-09-19

## Alcance

Se refinó el módulo de Inventario de Revive para que el catálogo de productos siga siendo único, pero el precio comercial y el stock operativo se administren por sede.

La vista mantiene la estructura del Sistema Base: PageHeader como primer bloque y un único contenedor principal blanco para la gestión.

## Estructura

### inventario.productos

Se conservan los datos maestros del producto y se incorporan:

- imagen_url
- maneja_lotes

El precio_venta permanece como precio base o respaldo. El stock_actual se conserva como total consolidado de las sedes para compatibilidad.

### inventario.producto_precios_sede

Una fila por producto y sede:

- producto_id
- sede_id
- precio
- activo

El POS usa primero este precio para la sede de la caja/turno. Si no existe, usa precio_venta como respaldo.

### inventario.producto_stock_sede

Una fila por producto y sede:

- producto_id
- sede_id
- stock_actual
- stock_minimo

Los movimientos de inventario actualizan esta tabla. El stock global del producto se recalcula como suma de las sedes.

### inventario.lotes_producto

Uso opcional por producto mediante maneja_lotes.

Campos principales:

- producto_id
- sede_id
- codigo_lote
- fecha_vencimiento
- cantidad_inicial
- stock_actual
- costo_unitario
- activo

Los movimientos pueden asociar lote_id. El lote debe pertenecer al mismo producto y sede.

## Datos de desarrollo

Para facilitar pruebas, los productos existentes se inicializan con 20 unidades por cada sede activa que tenga maneja_inventario=true cuando el entorno no es production.

En production el stock inicial de estas filas es 0. La carga inicial real deberá hacerse mediante movimientos/ingresos de inventario, no mediante saldos ficticios.

## POS

El catálogo de Productos del POS resuelve:

- precio por sede
- stock por sede
- imagen_url
- controla_stock
- maneja_lotes

La sede proviene del turno de caja abierto.

## Lotes

La versión base del repositorio tenía Kardex/movimientos con sede_id, pero no tenía una tabla de lotes. Desde este refinamiento los lotes quedan soportados de forma opcional para productos que necesiten trazabilidad o vencimiento, por ejemplo bebidas y suplementos.

## Siguiente bloque operativo

Antes de considerar la venta de productos cerrada para producción, debe conectarse la confirmación del pago con la salida de inventario por sede y, cuando aplique, el consumo del lote correspondiente. También debe validarse el precio en backend al guardar la venta para no confiar en importes enviados por el frontend.
