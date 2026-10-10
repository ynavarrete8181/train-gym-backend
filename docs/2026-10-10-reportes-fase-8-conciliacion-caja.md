# Reportes · Fase 8 · Conciliación de caja

Fecha: 2026-10-10

## Objetivo

Incorporar un reporte independiente para consultar cierres de caja, efectivo esperado, efectivo contado, diferencias y distribución de cobros no efectivos.

## Navegación

Menú:

`Reportes > Conciliación de caja`

Permiso:

`REPORTES-CONCILIACION-CAJA`

Roles:
- SUPERADMINISTRADOR
- ADMINISTRADOR
- SUPERVISOR DE VENTAS

## Regla financiera

El efectivo esperado se conserva como snapshot del cierre:

`EFECTIVO ESPERADO = saldo inicial + cobros confirmados en EFECTIVO`

Transferencias, tarjetas, depósitos y otros métodos se muestran por separado y no forman parte del efectivo físico esperado.

## Fuente histórica

El reporte consulta los valores persistidos en `ventas.turnos_caja` al momento del cierre.

No recalcula cierres históricos desde `ventas.pagos`, evitando que modificaciones posteriores alteren una conciliación ya registrada.

## Arquitectura modular

Backend:
- `app/Http/Controllers/Api/Ventas/Reportes/ConciliacionCajaControlador.php`
- `app/Services/Ventas/Reportes/ConciliacionCajaServicio.php`
- `routes/base/ventas/reportes.php`

Frontend:
- `src/features/reportes/pages/ConciliacionCajaPage.jsx`
- `src/features/reportes/services/ventas/conciliacionCajaServicio.js`

## Columnas

- Fecha
- Sede
- Caja
- Cajero
- Apertura
- Cierre
- Saldo inicial
- Efectivo cobrado
- Efectivo esperado
- Efectivo contado
- Diferencia
- Transferencias
- Tarjetas
- Depósitos
- Otros
- Tipo cierre
- Conciliación

## Resumen compacto

- Turnos cerrados
- Efectivo esperado
- Efectivo contado
- Diferencia total
- Cobrado no efectivo
- Pendientes de conciliación

## Filtros

Filtros generales:
- búsqueda;
- desde;
- hasta.

Filtros por columna:
- fecha;
- sede;
- caja;
- cajero;
- apertura;
- cierre;
- saldo inicial;
- efectivo cobrado;
- efectivo esperado;
- efectivo contado;
- diferencia;
- transferencia;
- tarjeta;
- depósito;
- otros;
- tipo de cierre;
- estado de conciliación.

Todos se resuelven en backend.

## Seguridad

Toda sede solicitada se intersecta con las sedes autorizadas por `AlcanceOperativoService`.

La ruta está protegida por:
- `base.auth`;
- `base.permiso:REPORTES-CONCILIACION-CAJA`.

## Aislamiento

Este reporte no modifica la lógica de otros reportes ni del flujo operativo de cierre de caja.

Cualquier cambio futuro debe mantenerse dentro de su propio controlador, servicio, página y servicio frontend.

## Siguiente reporte

El siguiente reporte comercial propuesto es **Productos y servicios vendidos**.
