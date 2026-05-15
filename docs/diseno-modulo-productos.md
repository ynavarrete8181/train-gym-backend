# Diseño del módulo Productos e Inventario

## Objetivo

El módulo de productos debe soportar inventario comercial para artículos como:

- agua
- Gatorade
- energizantes
- snacks
- suplementos
- micheladas u otras bebidas preparadas

La lógica debe estar centrada en **movimientos de inventario**, no en cambios directos de stock.

## Idea base

Se usará el mismo principio que un módulo de insumos:

- catálogo de productos
- stock por sede
- lotes si aplica
- precios múltiples
- movimientos
- transferencias
- ventas
- bajas y mermas

La diferencia es que aquí el inventario está orientado a **venta comercial**, no solo a consumo interno.

## Regla principal

Ninguna pantalla ni servicio externo debe modificar `stock_actual` directamente.

Toda variación de stock debe pasar por un servicio central:

- `ProductoMovimientoService`

Ese servicio será la única puerta para:

- sumar stock
- restar stock
- ajustar stock
- transferir stock
- registrar bajas
- consumir stock por venta

## Estructura funcional

### 1. Catálogo de productos

Tabla base:

- `train_gimnasio.productos`

Responsabilidad:

- datos maestros del producto
- configuración de comportamiento de stock
- si maneja lotes
- si maneja vencimiento

Campos actuales útiles:

- `controla_stock`
- `permite_decimales`
- `maneja_lotes`
- `maneja_vencimiento`

## 2. Stock por sede

Tabla:

- `train_gimnasio.producto_stock_sede`

Responsabilidad:

- existencia consolidada por producto y sede
- stock disponible y reservado

## 3. Lotes

Tabla:

- `train_gimnasio.producto_lotes`

Responsabilidad:

- trazabilidad por lote
- fechas de elaboración y vencimiento
- stock por lote

## 4. Precios

Tabla:

- `train_gimnasio.producto_precios`

Responsabilidad:

- costo
- precio de venta
- precio promocional
- vigencias

## 5. Movimientos

Tabla:

- `train_gimnasio.movimientos_inventario`

Responsabilidad:

- bitácora operativa de entradas, salidas, ajustes y transferencias
- origen del cambio de stock

## 6. Transferencias

Tablas:

- `train_gimnasio.transferencias_inventario`
- `train_gimnasio.transferencia_detalle`

Responsabilidad:

- mover stock entre sedes

## 7. Ventas

Actualmente no existe la tabla de ventas en este módulo.

Se recomienda crear después:

- `ventas`
- `venta_detalle`

Cuando una venta se confirme:

- se registra la venta;
- se descuenta stock llamando a `ProductoMovimientoService`;
- se registra el movimiento con tipo `SALIDA` y motivo `VENTA`.

## Productos con lote y vencimiento

### Recomendación

Sí conviene mantener lote y vencimiento opcionales por producto.

No todos los productos lo necesitan, pero muchos sí:

- bebidas embotelladas
- energizantes
- suplementos
- alimentos
- productos refrigerados

### Regla

No se obliga lote o vencimiento a todos los productos.

Se controla con:

- `maneja_lotes`
- `maneja_vencimiento`

### Ejemplos

- Agua:
  - `maneja_lotes = true`
  - `maneja_vencimiento = true`
- Camiseta:
  - `maneja_lotes = false`
  - `maneja_vencimiento = false`
- Gatorade:
  - `maneja_lotes = true`
  - `maneja_vencimiento = true`

## Eventos de inventario

El sistema debe manejar estos eventos:

### Entradas

- compra
- ingreso inicial
- ajuste positivo
- devolución de cliente
- transferencia entrada

### Salidas

- venta
- ajuste negativo
- merma
- vencimiento
- baja
- transferencia salida

## Propuesta de clasificación

La tabla actual `movimientos_inventario` ya soporta bien esta lógica:

- `tipo_movimiento`
  - `ENTRADA`
  - `SALIDA`
  - `AJUSTE`
  - `TRANSFERENCIA_SALIDA`
  - `TRANSFERENCIA_ENTRADA`

- `motivo`
  - `COMPRA`
  - `VENTA`
  - `AJUSTE_POSITIVO`
  - `AJUSTE_NEGATIVO`
  - `MERMA`
  - `VENCIMIENTO`
  - `BAJA`
  - `DEVOLUCION_CLIENTE`
  - `TRANSFERENCIA`

## Servicio central

### `ProductoMovimientoService`

Será el núcleo del inventario.

Responsabilidades:

1. validar producto y sede
2. validar si controla stock
3. validar si requiere lote
4. validar stock suficiente
5. actualizar `producto_stock_sede`
6. actualizar `producto_lotes` cuando corresponda
7. insertar `movimientos_inventario`
8. auditar la operación

### Métodos propuestos

- `registrarEntradaCompra()`
- `registrarSalidaVenta()`
- `registrarAjustePositivo()`
- `registrarAjusteNegativo()`
- `registrarBaja()`
- `registrarMerma()`
- `registrarVencimiento()`
- `registrarTransferenciaSalida()`
- `registrarTransferenciaEntrada()`
- `registrarMovimiento()` como método interno base

## Reglas de negocio

### Regla 1

Si `controla_stock = false`, el movimiento puede registrarse sin afectar existencias.

### Regla 2

Si `maneja_lotes = true`, toda entrada y salida debe estar vinculada a un lote.

### Regla 3

Si `maneja_vencimiento = true`, no se debe vender lote vencido.

### Regla 4

Las ventas deben consumir stock disponible y no permitir negativos salvo que el negocio lo autorice explícitamente.

### Regla 5

La baja no elimina el producto; genera movimiento de salida con motivo `BAJA`.

### Regla 6

Los ajustes deben registrar observación obligatoria.

## Múltiples precios

La tabla `producto_precios` ya permite esto.

Se recomienda usar al menos:

- `COSTO`
- `VENTA`
- `PROMOCION`

Más adelante se puede ampliar a:

- `SOCIO`
- `MAYORISTA`
- `EVENTO`

## Patrón backend

Se mantiene:

- `Controller -> Service -> Query`

### Propuesta de clases

- `ProductoController`
- `ProductoService`
- `ProductoQuery`
- `ProductoMovimientoController`
- `ProductoMovimientoService`
- `ProductoMovimientoQuery`
- `TransferenciaInventarioController`
- `TransferenciaInventarioService`
- `TransferenciaInventarioQuery`

## Endpoints sugeridos

### Catálogo

- `GET /api/gimnasio/productos`
- `GET /api/gimnasio/productos/{id}`
- `POST /api/gimnasio/productos`
- `PUT /api/gimnasio/productos/{id}`
- `DELETE /api/gimnasio/productos/{id}`

### Precios

- `GET /api/gimnasio/productos/{id}/precios`
- `POST /api/gimnasio/productos/{id}/precios`

### Lotes

- `GET /api/gimnasio/productos/{id}/lotes`
- `POST /api/gimnasio/productos/{id}/lotes`

### Movimientos

- `GET /api/gimnasio/inventario/movimientos`
- `POST /api/gimnasio/inventario/movimientos/entrada`
- `POST /api/gimnasio/inventario/movimientos/salida`
- `POST /api/gimnasio/inventario/movimientos/ajuste`
- `POST /api/gimnasio/inventario/movimientos/baja`

### Transferencias

- `GET /api/gimnasio/inventario/transferencias`
- `POST /api/gimnasio/inventario/transferencias`
- `POST /api/gimnasio/inventario/transferencias/{id}/enviar`
- `POST /api/gimnasio/inventario/transferencias/{id}/recibir`

## Frontend sugerido

### Módulo

- `src/modules/inventario`

### Pantallas

- productos
- movimientos
- transferencias
- lotes
- precios
- bajas/mermas

## Orden recomendado de implementación

1. completar CRUD de productos
2. construir `ProductoMovimientoService`
3. crear pantalla de movimientos
4. habilitar entradas manuales y ajustes
5. habilitar bajas y mermas
6. habilitar transferencias
7. conectar ventas con descuento automático de stock

## Conclusión

El producto comercial del gimnasio sí debe manejarse como inventario por movimientos.

La base actual ya tiene una estructura buena.

Lo correcto ahora es:

- no tocar stock directo;
- centralizar movimientos en un service;
- usar lotes y vencimiento solo cuando el producto lo requiera;
- construir ventas como consumidor del servicio de movimientos.

