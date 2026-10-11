# Reportes · Fase 8 · Membresías por vencer

Fecha: 2026-10-10

## Objetivo

Incorporar un reporte independiente para identificar membresías cuyo período vigente está próximo a finalizar y facilitar la gestión de renovación.

## Navegación

Menú:

`Reportes > Membresías por vencer`

Permiso:

`REPORTES-MEMBRESIAS-POR-VENCER`

Roles:
- SUPERADMINISTRADOR
- ADMINISTRADOR
- SUPERVISOR DE VENTAS

## Criterio funcional

El reporte toma únicamente el **último período existente de cada membresía**.

Una membresía no aparece si ya tiene un período posterior registrado.

Por defecto se consulta:
- vence desde: hoy;
- vence hasta: hoy + 30 días.

El rango puede modificarse desde frontend.

## Arquitectura modular

Backend:
- `app/Http/Controllers/Api/Ventas/Reportes/MembresiasPorVencerControlador.php`
- `app/Services/Ventas/Reportes/MembresiasPorVencerServicio.php`
- `routes/base/ventas/reportes.php`

Frontend:
- `src/features/reportes/pages/MembresiasPorVencerPage.jsx`
- `src/features/reportes/services/ventas/membresiasPorVencerServicio.js`

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

Saldo:
`venta.total - SUM(pagos confirmados)`

Si el período no tiene venta asociada, se usa el precio del período como base del saldo.

## Columnas

- Sede
- Contrato
- Cliente
- Plan
- Modalidad
- Período
- Vence
- Días restantes
- Renovable
- Estado
- Saldo

## Resumen compacto

- Total por vencer
- Vencen en 7 días
- Vencen en 15 días
- Renovables
- Saldo pendiente

## Filtros

Filtros generales:
- búsqueda;
- vence desde;
- vence hasta.

Filtros por columna:
- sede;
- contrato;
- cliente;
- plan;
- modalidad;
- período;
- fecha de vencimiento;
- días restantes;
- renovable;
- estado;
- saldo.

Todos se resuelven en backend.

## Seguridad

Toda sede solicitada se intersecta con las sedes autorizadas por `AlcanceOperativoService`.

La ruta está protegida por:
- `base.auth`;
- `base.permiso:REPORTES-MEMBRESIAS-POR-VENCER`.

## Aislamiento

Este reporte no modifica la lógica de otros reportes. Cualquier cambio futuro debe permanecer dentro de su propio controlador, servicio, página y servicio frontend.

## Siguiente reporte

El siguiente reporte propuesto de la Fase 8 es **Conciliación de caja**.
