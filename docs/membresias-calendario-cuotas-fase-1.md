# REVIVE SPORTS — Contratos con cuotas programadas (fase 1)

## Alcance
Migración: `2026_10_31_230000_create_membresia_cuotas_and_contract_period_count.php`

**Solo estructura.** No crea ni cobra ventas, no emite comprobantes, no modifica estados de acceso, no genera cuotas retroactivas y no cambia la lógica actual del scheduler. No ejecutar en producción sin revisión y autorización.

## Entidades y responsabilidades

- `membresias.membresias`: contrato; `cantidad_periodos_contratados` es NULL en registros históricos para no asumir que una renovación previa fue un contrato multitrimestre.
- `membresias.membresia_periodos`: vigencia, fechas y derecho deportivo, independiente de la fecha financiera.
- `membresias.membresia_cuotas`: calendario de obligaciones contratadas. Incluye `numero_cuota`, `monto`, `fecha_programada_emision`, `fecha_vencimiento`, `estado` (`PROGRAMADA`, `EMITIDA`, `ANULADA`), referencias opcionales a período y venta.
- `ventas.ventas`, `ventas.pagos`, `cuentas_cobrar.cuentas`: única fuente de verdad de ventas, cobros efectivos, saldos y mora; **no duplicar el importe pagado** en cuotas.
- `cuentas_cobrar.compromisos_pago`: acuerdos posteriores; no sustituye el calendario contractual.

## Reglas a implementar en fases posteriores

1. En Asignar membresía, seleccionar modalidad, sede, fecha de inicio, cantidad de períodos y forma de cobro; calcular precio congelado y fechas en Laravel. Cuatro semanas son 28 días, no un mes calendario.
2. Guardar contrato, períodos y cuotas en una transacción; detectar solapamientos y evitar doble asignación. La primera cuota se programa para emisión inmediata; las demás según la periodicidad acordada.
3. Implementar emisión idempotente por cuota con bloqueo transaccional (`lockForUpdate`) y vínculo a `ventas.ventas`; impedir ventas duplicadas en ejecuciones simultáneas del scheduler y desde caja.
4. Para pago anticipado de varias cuotas, asegurar una operación única de caja con aplicaciones auditables a obligaciones individuales; definir diseño según el flujo existente de `ventas.pagos`, que actualmente referencia una única venta.
5. El saldo de cada cuota debe derivarse de pagos confirmados y sus aplicaciones. `PAGADA` y `VENCIDA` son estados financieros derivados, no estados persistidos de `membresia_cuotas`.
6. Corregir `CarteraServicio::resolverFechaVencimiento`: no usar `dias_gracia` de acceso como día de vencimiento de deuda; usar `fecha_vencimiento` de cuota.
7. Separar `VENCIDA` por fin natural de contrato de `SUSPENDIDA_POR_MORA` por impago. Un día de atraso puede impedir acceso según política, **no cancelar automáticamente el contrato**.
8. Definir el comportamiento de cancelación anticipada, descuentos, reembolsos, pago total y cambios de sede/modalidad sin alterar ventas/comprobantes históricos.

## Pruebas obligatorias antes de integrar

- Alta de cinco períodos y cinco cuotas sin duplicados.
- Primera venta pendiente disponible de inmediato en el POS; cuotas futuras programadas, no morosas.
- Pago de dos cuotas y luego tres; pago total inicial; pago parcial; pago anticipado.
- Reejecución del scheduler y dos solicitudes simultáneas sin duplicar ventas.
- Meses calendario versus cuatro semanas y fin de mes (febrero).
- Cancelación, reactivación, suspensión por mora y cómputo de días de atraso.
- Migración sobre base local con datos previos, rollback y verificación de FK/índices.
- Comparación de la estructura del dump local con el esquema de Railway antes de desplegar.

## Nota
La migración de fase 1 **no se ha ejecutado** en una base de datos. La aplicación actual no usa todavía `membresia_cuotas`.
