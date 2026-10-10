# Ventas · Fase 6 · Analítica comercial avanzada

Fecha: 2026-10-09

## Objetivo

Ampliar Reportes comerciales con analítica comparativa sin crear un módulo paralelo ni duplicar fuentes financieras.

## Acceso

Se reutiliza:

`Ventas > Reportes comerciales`

Permiso:

`VENTAS-REPORTES`

Roles:
- SUPERADMINISTRADOR
- ADMINISTRADOR
- SUPERVISOR DE VENTAS

## Analítica incorporada

### Comparativo de período
Se compara el rango seleccionado contra el período inmediatamente anterior de igual duración.

Indicadores:
- ventas del período;
- variación porcentual de ventas;
- cobrado del período;
- variación porcentual de cobros.

### Métodos de pago
Se consolida:
- método;
- número de operaciones;
- total confirmado.

Fuente:
`ventas.pagos`

Solo pagos con estado `CONFIRMADO`.

### Responsable comercial
Ranking por:
- responsable;
- número de ventas;
- total vendido;
- total cobrado.

Fuente:
`ventas.ventas.responsable_comercial_id`

### Membresías
Se distingue:
- membresías nuevas: `numero_periodo = 1`;
- renovaciones: `numero_periodo > 1`.

Fuente:
`gimnasio.membresia_periodos` relacionada con `ventas.ventas`.

### Cartera vencida
Se calcula desde:
- `cuentas_cobrar.cuentas.fecha_vencimiento`;
- `ventas.ventas.total`;
- pagos confirmados de `ventas.pagos`.

No se guarda un saldo alterno.

## Filtros

Toda la analítica respeta:
- fecha desde;
- fecha hasta;
- sedes autorizadas;
- sede seleccionada;
- tipo de venta.

La sede solicitada siempre se intersecta con el alcance autorizado del usuario.

## Frontend

Nuevo componente:
`src/features/ventas/components/reportes/AnaliticaComercialResumen.jsx`

La vista:
`src/features/ventas/pages/ReportesComercialesPage.jsx`

mantiene los indicadores compactos y evita tarjetas grandes cuando la información puede presentarse en chips.

## Backend

Se amplió:
`app/Services/Ventas/ReporteComercialServicio.php`

Nuevo endpoint:
`GET /base/ventas/reportes-comerciales/analitica`

Protección:
- `base.auth`
- `base.permiso:VENTAS-REPORTES`

## Fuente de verdad

Continúan siendo fuentes maestras:
- `ventas.ventas`;
- `ventas.pagos`;
- `cuentas_cobrar.*`;
- `membresias.*`;
- `institucional.sedes`.

No se crean tablas de agregados financieros.

## Siguiente evolución

Queda para una fase posterior:
- exportación Excel/PDF;
- conciliaciones históricas;
- comparativos gráficos;
- metas comerciales;
- análisis por producto/servicio.


## Corrección incremental de visibilidad del submenú

Se agregó la migración:

`database/migrations/2026_10_09_001300_ensure_sales_reports_menu_permissions.php`

Objetivo:
- garantizar que `VENTAS-REPORTES` esté registrado dentro del menú **Ventas**;
- registrar `ReportesComercialesPage` en `seguridad.cpu_pagina_sistema`;
- sincronizar el permiso a roles y usuarios existentes;
- limitar la visibilidad a:
  - SUPERADMINISTRADOR;
  - ADMINISTRADOR;
  - SUPERVISOR DE VENTAS;
- retirar cualquier asignación accidental a otros roles.

El menú se construye desde `seguridad.cpu_userfunction`, por lo que después de ejecutar la migración debe renovarse la sesión para reconstruir `base_menu`.
