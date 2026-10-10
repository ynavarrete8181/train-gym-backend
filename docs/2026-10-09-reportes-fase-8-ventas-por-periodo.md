# Reportes · Fase 8 · Ventas por período

Fecha: 2026-10-09

## Objetivo

Incorporar un reporte transaccional independiente de ventas por rango de fechas, manteniendo aislamiento total respecto a Resumen comercial, Cartera vencida y Cobros por método.

## Navegación

Menú:

`Reportes > Ventas por período`

Permiso:

`REPORTES-VENTAS-PERIODO`

Roles:
- SUPERADMINISTRADOR
- ADMINISTRADOR
- SUPERVISOR DE VENTAS

## Arquitectura modular

Backend:
- `app/Http/Controllers/Api/Ventas/Reportes/VentasPeriodoControlador.php`
- `app/Services/Ventas/Reportes/VentasPeriodoServicio.php`
- `routes/base/ventas/reportes.php`

Frontend:
- `src/features/reportes/pages/VentasPeriodoPage.jsx`
- `src/features/reportes/services/ventas/ventasPeriodoServicio.js`

No se modifica la lógica de los otros reportes para implementar este reporte.

## Fuente de verdad

Se consulta directamente desde:
- `ventas.ventas`;
- `ventas.pagos`;
- `ventas.cajas`;
- `membresias.membresias`;
- `gimnasio.deportistas`;
- `personas.personas`;
- `seguridad.users`;
- `institucional.sedes`.

Saldo:

`venta.total - SUM(pagos confirmados)`

No se almacena saldo paralelo.

## Detalle

Cada fila representa una venta.

Columnas:
- Fecha
- Sede
- N.º de venta
- Cliente
- Tipo
- Estado
- Responsable comercial
- Total
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
- N.º de venta;
- cliente;
- tipo de venta;
- estado;
- responsable comercial;
- total;
- cobrado;
- saldo.

Todos se resuelven en backend.

## Resumen compacto

Se muestran:
- transacciones;
- total vendido;
- total cobrado;
- saldo pendiente.

El resumen respeta los mismos filtros aplicados al detalle.

## Alineación visual

Siguiendo el estándar global:
- texto descriptivo a la izquierda: Sede, Cliente, Responsable comercial;
- datos cortos/métricos centrados: Fecha, N.º de venta, Tipo, Estado, Total, Cobrado, Saldo.

## Seguridad

El servicio intersecta cualquier sede solicitada con el alcance autorizado por `AlcanceOperativoService`.

La ruta usa:
- `base.auth`;
- `base.permiso:REPORTES-VENTAS-PERIODO`.

## Siguiente reporte de Fase 8

Después de validar Ventas por período, continuar con **Ventas por responsable comercial** usando la misma regla de aislamiento.
