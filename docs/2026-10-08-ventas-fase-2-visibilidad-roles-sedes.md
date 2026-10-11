# Ventas · Fase 2 · Visibilidad por rol y sede

Fecha: 2026-10-08

## Objetivo

Separar la visibilidad comercial de la operación de caja, manteniendo el backend como fuente de verdad.

## Reglas

- SUPERADMINISTRADOR: alcance global.
- ADMINISTRADOR: ve la operación comercial completa de sus sedes. Solo cobra si tiene un turno propio abierto.
- SUPERVISOR DE VENTAS: ve la operación comercial completa de sus sedes. Puede cobrar si tiene un turno propio abierto y supervisar cierres según permisos.
- CAJERO: con turno propio abierto puede cobrar cualquier cuenta pendiente/parcial de la sede del turno, aunque la haya generado otro usuario. En históricos solo ve ventas/cobros/comprobantes en los que participó como generador o cobrador. En turnos solo ve sus propios turnos.
- RECEPCIONISTA: no participa en el flujo comercial de cobro.

## Matriz de módulos

| Módulo | Superadmin | Administrador | Supervisor ventas | Cajero |
| --- | --- | --- | --- | --- |
| Cajas | Sí | Sí | No | No |
| Ventas | Sí | Sí | Sí | Sí |
| Turnos de caja | Sí | Sí | Sí | Sí |
| Cobros | Sí | Sí | Sí | Sí |
| Comprobantes | Sí | Sí | Sí | Sí |

## Reglas de caja

- Una caja física solo puede tener un turno abierto.
- El cobro requiere turno propio del usuario.
- El pago registra usuario, caja y turno.
- La venta conserva quién la generó.
- Un cajero puede cobrar una cuenta pendiente generada por otro usuario si corresponde a la sede de su turno.
- El historial administrativo no se mezcla con la responsabilidad individual del cajero.

## Seguridad

Las restricciones se aplican en consultas y validaciones backend. El frontend solo refleja el estado y habilita/deshabilita acciones.
