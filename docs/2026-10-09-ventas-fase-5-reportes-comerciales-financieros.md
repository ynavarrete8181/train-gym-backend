# Ventas · Fase 5 · Reportes comerciales y financieros

Fecha: 2026-10-09

## Objetivo

Incorporar una vista comercial y financiera operativa dentro del módulo Ventas, reutilizando las fuentes transaccionales existentes y manteniendo el backend como fuente de verdad.

## Acceso

Submenú:

Ventas > Reportes comerciales

Permiso:

`VENTAS-REPORTES`

Roles:
- SUPERADMINISTRADOR
- ADMINISTRADOR
- SUPERVISOR DE VENTAS

El alcance se limita a las sedes autorizadas mediante `AlcanceOperativoService`.

## Fuentes de verdad

Los indicadores se calculan directamente desde:
- `ventas.ventas`
- `ventas.pagos`
- `ventas.cajas`
- `membresias.membresias`
- `cuentas_cobrar.cuentas`
- `institucional.sedes`

No se crean tablas de totales paralelas ni saldos duplicados.

## Indicadores iniciales

La primera versión presenta:
- total de ventas del período;
- número de transacciones;
- total cobrado confirmado;
- efectivo cobrado confirmado;
- saldo actual de cartera.

El saldo de cartera se deriva de:

`ventas.ventas.total - SUM(ventas.pagos.monto WHERE estado = 'CONFIRMADO')`

## Detalle

El reporte agrupa por:
- fecha;
- sede;
- tipo de venta.

Columnas:
- transacciones;
- total ventas;
- total cobrado;
- saldo pendiente.

## Filtros

Filtros disponibles:
- búsqueda general;
- fecha desde;
- fecha hasta;
- sede;
- tipo de venta;
- paginación global.

Los filtros de sede y tipo de venta utilizan `FilterHeaderCell`.
La búsqueda usa `GestionToolbar`.
La tabla y paginación usan `TablaGestion`.

Todos los filtros relevantes se envían al backend.

## Arquitectura

Backend:
- `app/Services/Ventas/ReporteComercialServicio.php`
- `app/Http/Controllers/Api/Ventas/ReporteComercialControlador.php`
- `routes/base/ventas.php`
- `database/migrations/2026_10_09_001200_add_sales_reports_menu.php`

Frontend:
- `src/features/ventas/pages/ReportesComercialesPage.jsx`
- `src/features/ventas/services/reporteComercialServicio.js`

La página se descubre mediante el catálogo global de páginas y se registra como `ReportesComercialesPage`.

## Seguridad

La ruta está protegida por:
- `base.auth`
- `base.permiso:VENTAS-REPORTES`

La selección de sede solicitada por frontend siempre se intersecta con las sedes autorizadas del usuario.

## Evolución

Esta fase deja preparada la base para añadir posteriormente:
- desglose por método de pago;
- análisis por responsable comercial;
- membresías vendidas y renovadas;
- cartera vencida por período;
- comparativos;
- exportación PDF/Excel;
- cierres y conciliaciones históricas.

Estas ampliaciones deben continuar usando las fuentes transaccionales existentes y no duplicar lógica financiera.
