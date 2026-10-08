# Ventas — Fase 1: trazabilidad comercial y pagos mixtos

Fecha: 2026-10-08

## Objetivo

Separar correctamente el origen comercial de una venta del usuario que efectivamente recibe el pago, manteniendo compatibilidad con el módulo existente de Ventas y Caja.

## Reglas funcionales

- `ventas.ventas.usuario_id` representa al usuario que generó/originó la venta cuando el origen es humano.
- `ventas.ventas.generado_por_tipo` distingue `USUARIO` y `SISTEMA`.
- `ventas.ventas.origen_tipo` identifica el flujo de negocio que originó la venta.
- `ventas.ventas.origen_id` conserva la referencia funcional cuando existe.
- `ventas.ventas.responsable_comercial_id` queda disponible para la fase de visibilidad y seguimiento comercial.
- `ventas.pagos.usuario_id` representa al usuario que recibió/registró ese pago.
- Una venta puede tener múltiples registros en `ventas.pagos`.
- La suma de pagos confirmados determina el estado de la venta:
  - sin pagos: `PENDIENTE`
  - pago menor al total: `PARCIAL`
  - pago igual al total: `PAGADA`

## Orígenes iniciales

- `POS`: venta creada directamente desde punto de venta.
- `MEMBRESIA_ASIGNACION`: venta originada al asignar una membresía.
- `MEMBRESIA_RENOVACION`: renovación manual.
- `MEMBRESIA_RENOVACION_AUTOMATICA`: cobro programado generado por el sistema.

## Pagos mixtos

El POS acepta varios métodos en una misma operación. Ejemplo para una venta de USD 60:

- Transferencia: USD 40
- Efectivo: USD 20

Se crean dos registros de pago, ambos vinculados a la misma venta y al mismo turno de caja. El cierre de caja debe considerar únicamente los movimientos realmente cobrados durante el turno.

## Trazabilidad

Ejemplo esperado:

- Venta generada por: Andrea Administradora
- Origen: Membresía
- Cobro 1: Karol — Transferencia USD 40
- Cobro 2: Karol — Efectivo USD 20
- Estado final: PAGADA

El usuario que cobra no reemplaza al usuario que generó la venta.

## UI

Las vistas de Ventas deben seguir las convenciones globales del Sistema Base:

- `PageHeader`
- `dbanuStyles`
- acciones globales
- campos compactos
- sin duplicar estilos cuando existe un patrón global

La cuenta pendiente muestra el usuario generador. El formulario POS permite agregar o quitar métodos de pago y muestra saldo a cobrar, total ingresado y saldo restante.

## Fases siguientes

La Fase 2 implementará el alcance por rol y sede para Administrador, Supervisor de Ventas y Cajero. Las notificaciones, reportes y app del cliente se implementarán después de validar este flujo base.
