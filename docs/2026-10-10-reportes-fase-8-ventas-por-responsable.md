# Reportes · Fase 8 · Ventas por responsable comercial

Fecha: 2026-10-10

## Objetivo

Incorporar un reporte independiente para evaluar el desempeño comercial por responsable y sede, sin reutilizar ni modificar las consultas de otros reportes.

## Navegación

Menú:

`Reportes > Ventas por responsable`

Permiso:

`REPORTES-VENTAS-RESPONSABLE`

Roles:
- SUPERADMINISTRADOR
- ADMINISTRADOR
- SUPERVISOR DE VENTAS

## Arquitectura modular

Backend:
- `app/Http/Controllers/Api/Ventas/Reportes/VentasResponsableControlador.php`
- `app/Services/Ventas/Reportes/VentasResponsableServicio.php`
- `routes/base/ventas/reportes.php`

Frontend:
- `src/features/reportes/pages/VentasResponsablePage.jsx`
- `src/features/reportes/services/ventas/ventasResponsableServicio.js`

## Agrupación

Cada fila representa:

`Responsable comercial + Sede`

Esto evita inferir la sede desde frontend y permite que un mismo responsable aparezca correctamente separado cuando vende en distintas sedes.

## Indicadores por fila

- ventas;
- clientes distintos;
- total vendido;
- total cobrado;
- saldo pendiente;
- ticket promedio;
- porcentaje cobrado.

## Resumen compacto

Se muestran:
- responsables;
- ventas;
- total vendido;
- total cobrado;
- saldo pendiente.

## Fuente de verdad

Se consulta directamente desde:
- `ventas.ventas`;
- `ventas.pagos`;
- `ventas.cajas`;
- `membresias.membresias`;
- `seguridad.users`;
- `institucional.sedes`.

El saldo continúa derivándose desde:

`ventas.ventas.total - SUM(ventas.pagos.monto WHERE estado = 'CONFIRMADO')`

No se almacena un saldo paralelo.

## Filtros

Filtros generales:
- fecha desde;
- fecha hasta.

Filtros por columna:
- responsable;
- sede;
- ventas;
- clientes;
- total vendido;
- cobrado;
- saldo;
- ticket promedio;
- porcentaje cobrado.

Todos se resuelven en backend.

## Seguridad

La sede solicitada siempre se intersecta con las sedes permitidas por `AlcanceOperativoService`.

La ruta está protegida con:
- `base.auth`;
- `base.permiso:REPORTES-VENTAS-RESPONSABLE`.

## Aislamiento

Este reporte no modifica:
- Resumen comercial;
- Cartera vencida;
- Cobros por método;
- Ventas por período.

Cualquier cambio futuro en Ventas por responsable debe permanecer dentro de su propio controlador, servicio, página y servicio frontend.

## Siguiente reporte

Después de validar esta vista, la siguiente pieza de la Fase 8 será **Membresías nuevas y renovaciones**.
