# Reportes · Fase 8 · Productos y servicios vendidos

Fecha: 2026-10-10

## Objetivo

Incorporar un reporte independiente para consolidar los ítems vendidos por tipo, sede, cantidad e ingresos, sin mezclar su lógica con otros reportes comerciales.

## Navegación

Menú:

`Reportes > Productos y servicios vendidos`

Permiso:

`REPORTES-PRODUCTOS-SERVICIOS`

Roles:
- SUPERADMINISTRADOR
- ADMINISTRADOR
- SUPERVISOR DE VENTAS

## Arquitectura modular

Backend:
- `app/Http/Controllers/Api/Ventas/Reportes/ProductosServiciosVendidosControlador.php`
- `app/Services/Ventas/Reportes/ProductosServiciosVendidosServicio.php`
- `routes/base/ventas/reportes.php`

Frontend:
- `src/features/reportes/pages/ProductosServiciosVendidosPage.jsx`
- `src/features/reportes/services/ventas/productosServiciosVendidosServicio.js`

## Fuente de verdad

El reporte parte de:
- `ventas.venta_detalles`;
- `ventas.ventas`;
- `ventas.cajas`;
- `membresias.membresias`;
- `inventario.productos`;
- `institucional.sedes`.

La clasificación del ítem prioriza:
1. `venta_detalles.tipo_item`;
2. producto asociado;
3. `ventas.tipo_venta`;
4. `OTRO` como último recurso.

La descripción prioriza el nombre del producto y, si no existe, utiliza la descripción persistida del detalle de venta.

## Agrupación

Cada fila representa:

`Sede + Tipo de ítem + Producto/Servicio`

## Columnas

- Sede
- Tipo
- Producto / servicio
- Ventas
- Cantidad
- Precio promedio
- Total vendido

`Ventas` representa cuántas transacciones distintas incluyeron ese ítem.

## Resumen compacto

Se muestran:
- ítems distintos;
- unidades vendidas;
- total vendido.

No se suma globalmente la columna `Ventas` entre grupos, porque una misma venta puede contener varios ítems y eso podría duplicar una transacción en el total general.

## Filtros

Filtros generales:
- búsqueda;
- desde;
- hasta.

Filtros por columna:
- sede;
- tipo;
- producto/servicio;
- ventas;
- cantidad;
- precio promedio;
- total vendido.

Todos se resuelven en backend.

## Seguridad

Toda sede solicitada se intersecta con las sedes autorizadas por `AlcanceOperativoService`.

La ruta está protegida por:
- `base.auth`;
- `base.permiso:REPORTES-PRODUCTOS-SERVICIOS`.

## Aislamiento

Este reporte no modifica la lógica de:
- Resumen comercial;
- Cartera vencida;
- Cobros por método;
- Ventas por período;
- Ventas por responsable;
- Membresías nuevas y renovaciones;
- Membresías por vencer;
- Conciliación de caja.

Cualquier cambio futuro debe mantenerse dentro de su propio controlador, servicio, página y servicio frontend.

## Cierre de Fase 8

Con este reporte queda cubierto el bloque principal de reportes comerciales especializados previsto para la Fase 8.

La siguiente fase recomendada es **Fase 9 — Exportación PDF y Excel por reporte**, manteniendo cada exportador asociado únicamente a su reporte.
