# REVIVE SPORTS — Ciclo de vida de membresías · Política C · Fase 1 de 4

## Estado
Implementado **solo esquema + política de validación + pruebas** en `dev-revive`.
No habilitar rutas de ejecución, no modificar estados ni movimientos reales y **no desplegar migraciones a producción** sin revisión.

## Objetivo y modelo
Membresía es el contrato estable; no crear una nueva al congelar o reactivar.
Una recontratación después del cierre requiere un **nuevo contrato vinculado** al original (relación a incorporar en Fase 4).
Congelar facturación no anula automáticamente ventas, cuotas emitidas, pagos ni Cartera.
No borrar evidencia comercial ni reutilizar la misma venta.

Tablas existentes reutilizadas:
- `membresias.membresias`: contrato y estado actual.
- `membresias.membresia_periodos`: derechos deportivos y vigencia.
- `membresias.membresia_cuotas`: obligaciones contractuales programadas.
- `membresias.membresia_condiciones_historial`: versiones contractuales.
- `ventas.ventas`, `ventas.pagos`, `cuentas_cobrar.cuentas`: evidencia de pagos y saldos.

Nuevas entidades **sin datos preexistentes modificados**:
- `membresias.membresia_movimientos`: evento de negocio con tipo, fecha efectiva, motivo, creador, aprobador y movimiento anterior vinculado. Estado CONFIRMADO se utilizará en fases posteriores; NO insertar eventos hasta contar con servicio transaccional.
- `membresias.membresia_suspensiones`: una suspensión, tipo (TIEMPO / FACTURACION), inicio y fin previsto, fin real inclusivo, días reconocidos y vigencia anterior/ajustada. El FK `movimiento_inicio_id` identifica el evento que la originó.
- `membresias.membresia_reprogramaciones`: rastro de cambios propuestos/aprobados a fechas de emisión, vencimiento y fin de vigencia. Referencia a cuota/período y movimiento. No constituye un documento contable ni sustituye Cartera.

FK restrictivas para conservar trazabilidad. Fechas de suspensión son intervalos **inclusivos**. No se migran estados anteriores ni se altera el scheduler en Fase 1.

## Política C por operación

| Operación | Estado anterior permitido | Facturación | Efecto deportivo |
| --- | --- | --- | --- |
| Congelar tiempo | ACTIVA, sin otra suspensión pendiente/vigente | No cambia vencimientos por defecto | Pausar acceso y reconocer días al fin |
| Congelar facturación | ACTIVA, con cuotas por evaluar | Revisar cuotas futuras; no afectar pagos ya confirmados | Pausar acceso |
| Reactivar | CONGELADA y suspensión vigente | Aplicar solo calendario autorizado | Recuperar acceso |
| Cerrar anticipadamente | Contrato no cerrado, con motivo | Revisar venta emitida, saldo, devolución y cuotas futuras | Cerrar reservas y accesos |
| Reapertura excepcional | Contrato cerrado con aprobación especial | Revisión y compensación auditada | Excepción, no recontratación automática |
| Recontratación | Contrato anterior cerrado | Venta y obligaciones **nuevas** | Contrato nuevo, vínculo al anterior |

Estado `REACTIVADA` es **un movimiento**, no el estado permanente del contrato. Tras reactivar, el estado vuelve a `ACTIVA` si las reglas de acceso y pago lo permiten.

## Reglas de validación y contabilidad

1. Las congelaciones ordinarias no son retroactivas; para fechas anteriores se requiere flujo excepcional (Fase 4).
2. No permitir períodos de congelación solapados, simultáneos ni duplicados. Validar con bloqueo de fila de membresía dentro de la transacción. En PostgreSQL evaluar una restricción de exclusión por rango para concurrencia.
3. La fecha fin de suspensión es inclusiva; `15/11/2026 - 29/11/2026` = 15 días; reactivación el 30/11/2026.
4. Congelación de tiempo aprobada de una membresía pagada: ajustar vigencia desde fin original + días reconocidos, conservar todos los importes y pagos.
5. Congelación de facturación: evaluar saldo, pago parcial, ventas ya emitidas y obligaciones programadas antes de confirmar. Una venta ya emitida requiere procedimiento formal de ajuste; **no anulación silenciosa**.
6. Cuotas PROGRAMADA sin venta asociada pueden reprogramarse, conservando `membresia_reprogramaciones` con antes/después. Al reactivar no emitir cuotas viejas de golpe.
7. Registro de pago confirmado siempre mantiene fecha, caja, sede, cajero, importe y referencia original. Cartera se deriva de venta y pagos efectivos.
8. Cierre anticipado detiene emisiones futuras solamente cuando el servicio transaccional confirme la decisión; mantiene obligaciones devengadas y posibles saldos a favor hasta resolución.
9. Los días de gracia para acceso no representan suspensión ni modifican la fecha legal de vencimiento de una deuda.
10. Permisos: solicitar no equivale a aprobar; el aprobador no debe inferirse del vendedor/cobrador. Historial con usuario/fecha/motivo y modificación previa/posterior.

## Matriz de escenarios de aceptación

| Escenario | Resultado esperado |
| --- | --- |
| Mes pagado, vacaciones 15 días | +15 días de servicio, sin nueva venta |
| Regresa 5 días antes | Días reconocidos ajustados a duración real, historial intacto |
| Cuotas no emitidas | Calendario revisado antes de reactivar |
| Cuota pendiente ya emitida | Cartera conservada hasta ajuste autorizado |
| Cuota pagada o parcial | No alterar pagos ni duplicar importes |
| Cierre con deuda | Mantener deuda devengada; no generar futuros cobros improcedentes |
| Congelación doble / solapada | 422 sin crear movimiento |
| Reapertura excepcional | Aprobación especial y vínculo al cierre |
| Recontratación | Contrato nuevo, sin sobreescribir histórico |
| Scheduler concurrente | No emite dos veces una misma cuota ni salta suspensión financiera |

## Secuencia de fases y criterios de paso

**Fase 1 (actual):** tablas históricas, reglas de validación puras, pruebas unitarias y este documento. Nada operativo habilitado.

**Fase 2:** endpoints autorizados congelar/reactivar + vistas internas MUI con botón Volver a la derecha, transacciones, lockForUpdate, períodos, estado de acceso, reservas/agenda y cron de inicio/fin de suspensión. Tests de integración PostgreSQL.

**Fase 3:** previsualización de impacto financiero, bloqueo y reprogramación idempotente en `EmisionCuotasServicio` y `CobroProgramadoMembresiaServicio`, modificaciones de Cartera / comprobantes y auditoría de ajustes. Ninguna cuota cancelada por cambiar simplemente `estado`.

**Fase 4:** cierre anticipado, autorización de reapertura excepcional, recontratación vinculada, reportes, casos de devoluciones/saldos y pruebas end-to-end.

## Comprobación antes de migrar

- Usar PostgreSQL de desarrollo con copia anónima de esquema; ejecutar `php artisan migrate --pretend` y después `php artisan migrate` en desarrollo solamente.
- Probar `php artisan test tests/Unit/Gimnasio/PoliticaCicloVidaMembresiaTest.php` y vencimientos mensuales.
- Validar FK y nombres de esquema en la base real de desarrollo.
- Probar rollback únicamente en base de pruebas (la reversión elimina las 3 tablas).
- Exigir aprobación expresa para cualquier deploy a Railway; este archivo NO es aprobación.

## Riesgos detectados en código actual

- `EmisionCuotasServicio::procesar` hoy solo descarta estado CANCELADA; no evalúa pausas de facturación.
- `CobroProgramadoMembresiaServicio::procesar` selecciona recurrentes no CANCELADA; congelar puede seguir generando venta.
- `ActualizarEstadosMembresiaServicio` excluye CONGELADA del vencimiento automático. No equivale a política completa de acceso y dinero.
- `MembresiaServicio::actualizar` no implementa procesos transaccionales de suspensiones. Una edición de estado no es una congelación formal.

Estos riesgos deben resolverse **antes de habilitar** acciones de congelación reales.
