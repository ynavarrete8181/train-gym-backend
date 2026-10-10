# Reportes · Fase 7 · Arquitectura modular de reportes especializados

Fecha: 2026-10-09

## Objetivo

Separar los reportes comerciales en unidades funcionales independientes para evitar acoplamiento entre consultas, controladores, vistas y permisos.

La navegación se centraliza en el menú principal **Reportes**, pero cada reporte conserva su dominio técnico bajo Ventas.

## Regla arquitectónica

Cada reporte debe tener, como mínimo:
- controlador propio;
- servicio backend propio;
- endpoint propio;
- permiso propio;
- página frontend propia;
- servicio frontend propio.

No se colocan múltiples consultas de reportes distintos dentro de un único controlador o servicio monolítico.

Solo se reutilizan componentes y servicios transversales estables, por ejemplo:
- `AlcanceOperativoService`;
- `ApiResponse`;
- `PageHeader`;
- `GestionToolbar`;
- `TablaGestion`;
- `FilterHeaderCell`.

Las consultas SQL/Query Builder pertenecen al servicio específico de cada reporte.

## Backend

Estructura:

`app/Http/Controllers/Api/Ventas/Reportes/`
- `ResumenComercialControlador.php`
- `CarteraVencidaControlador.php`
- `CobrosMetodoPagoControlador.php`

`app/Services/Ventas/Reportes/`
- `ResumenComercialServicio.php`
- `CarteraVencidaServicio.php`
- `CobrosMetodoPagoServicio.php`

Rutas:

`routes/base/ventas/reportes.php`

El archivo `routes/base/ventas.php` únicamente incorpora el archivo modular de rutas.

## Frontend

`src/features/reportes/pages/`
- `ResumenComercialPage.jsx`
- `CarteraVencidaReportePage.jsx`
- `CobrosMetodoPagoPage.jsx`

`src/features/reportes/services/ventas/`
- `resumenComercialServicio.js`
- `carteraVencidaServicio.js`
- `cobrosMetodoPagoServicio.js`

Las páginas se mantienen directamente bajo `pages/` porque el cargador dinámico del sistema descubre `*Page.jsx` en ese nivel.

## Submenús

Dentro del menú principal **Reportes**:
- Resumen comercial
- Cartera vencida
- Cobros por método

Permisos:
- `REPORTES-COMERCIAL-RESUMEN`
- `REPORTES-CARTERA-VENCIDA`
- `REPORTES-COBROS-METODOS`

Roles iniciales:
- SUPERADMINISTRADOR
- ADMINISTRADOR
- SUPERVISOR DE VENTAS

## Retiro de implementación anterior

Se retira la implementación monolítica:
- `ReporteComercialControlador.php`
- `ReporteComercialServicio.php`
- `ReportesComercialesPage.jsx`
- `AnaliticaComercialResumen.jsx`
- `reporteComercialServicio.js`

También se retira del menú el permiso anterior `VENTAS-REPORTES`.

Las migraciones históricas se conservan por trazabilidad, pero la migración incremental de Fase 7 elimina su asignación activa.

## Aislamiento

Un cambio en **Cartera vencida** no debe modificar la consulta de **Cobros por método** ni la de **Resumen comercial**.

Cada servicio calcula únicamente su propio reporte y aplica:
- permisos;
- alcance por sede;
- filtros;
- paginación;
- agregaciones propias.

No se comparten builders de consultas entre reportes.

## Filtros

Se mantiene el estándar visual global:
- todas las columnas de datos filtrables usan `FilterHeaderCell`;
- los filtros relevantes se resuelven en backend;
- la columna Acciones, cuando exista, no lleva filtro;
- los indicadores compactos evitan duplicar información.

## Escalabilidad

Los próximos reportes deberán agregarse siguiendo exactamente la misma estructura, por ejemplo:
- Ventas por período;
- Ventas por responsable;
- Membresías nuevas y renovaciones;
- Conciliación de caja;
- Productos y servicios vendidos.

Cada uno debe incorporarse como módulo independiente, sin ampliar los servicios existentes con lógica ajena.
