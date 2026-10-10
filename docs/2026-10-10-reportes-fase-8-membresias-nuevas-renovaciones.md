# Reportes · Fase 8 · Membresías nuevas y renovaciones

Fecha: 2026-10-10

## Objetivo

Incorporar un reporte independiente para distinguir altas nuevas de renovaciones de membresía, utilizando como fuente de verdad los períodos reales de cada membresía.

## Navegación

Menú:

`Reportes > Membresías nuevas y renovaciones`

Permiso:

`REPORTES-MEMBRESIAS-NUEVAS-RENOVACIONES`

Roles:
- SUPERADMINISTRADOR
- ADMINISTRADOR
- SUPERVISOR DE VENTAS

## Regla de clasificación

Cada registro de `membresias.membresia_periodos` representa un movimiento de membresía:

- `numero_periodo = 1` → **Nueva**
- `numero_periodo > 1` → **Renovación**

No se infiere la renovación desde fechas ni desde ventas aisladas.

## Arquitectura modular

Backend:
- `app/Http/Controllers/Api/Ventas/Reportes/MembresiasNuevasRenovacionesControlador.php`
- `app/Services/Ventas/Reportes/MembresiasNuevasRenovacionesServicio.php`
- `routes/base/ventas/reportes.php`

Frontend:
- `src/features/reportes/pages/MembresiasNuevasRenovacionesPage.jsx`
- `src/features/reportes/services/ventas/membresiasNuevasRenovacionesServicio.js`

## Fuente de verdad

Se consulta:
- `membresias.membresia_periodos`;
- `membresias.membresias`;
- `membresias.planes`;
- `membresias.plan_modalidades`;
- `clientes.deportistas`;
- `personas.personas`;
- `ventas.ventas`;
- `ventas.pagos`;
- `institucional.sedes`.

Cobrado:
`SUM(ventas.pagos.monto WHERE estado = 'CONFIRMADO')`

Saldo:
`venta.total - cobrado`

Si el período no tiene venta asociada todavía, se toma el precio del período como base para el saldo comercial.

## Columnas

- Fecha
- Sede
- Movimiento
- Contrato
- Cliente
- Plan
- Modalidad
- Período
- Estado
- Precio
- Cobrado
- Saldo

## Resumen compacto

- Nuevas
- Renovaciones
- Facturado
- Cobrado
- Saldo

## Filtros

Filtros generales:
- búsqueda;
- desde;
- hasta.

Filtros por columna:
- fecha;
- sede;
- movimiento;
- contrato;
- cliente;
- plan;
- modalidad;
- número de período;
- estado;
- precio;
- cobrado;
- saldo.

Todos se resuelven en backend.

## Seguridad

Toda sede solicitada se intersecta con las sedes autorizadas por `AlcanceOperativoService`.

La ruta está protegida por:
- `base.auth`;
- `base.permiso:REPORTES-MEMBRESIAS-NUEVAS-RENOVACIONES`.

## Aislamiento

Este reporte no modifica la lógica de:
- Resumen comercial;
- Cartera vencida;
- Cobros por método;
- Ventas por período;
- Ventas por responsable.

Cualquier cambio futuro debe quedar dentro de su propio controlador, servicio, página y servicio frontend.

## Siguiente reporte

El siguiente reporte propuesto de la Fase 8 es **Membresías por vencer**, orientado al seguimiento operativo y comercial de renovaciones próximas.
