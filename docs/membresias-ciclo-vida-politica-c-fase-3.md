# REVIVE SPORTS · Fase 3/4 · Congelación financiera y Cartera

## Estado de implementación

Implementación **en dev-revive**, sin migrar ni desplegar a producción. Esta fase utiliza las tablas de la Fase 1:
`membresias.membresia_movimientos`, `membresias.membresia_suspensiones`,
`membresias.membresia_reprogramaciones`. Estas tablas deben estar creadas
en la base de desarrollo antes de invocar endpoints del ciclo de vida.

### Flujo activo

1. En la web: Clientes → Perfil integral → Membresía → Editar → Gestionar congelación.
2. Seleccionar TIEMPO (conserva cuotas originales) o FACTURACION (pospone cuotas futuras sin emitir).
3. Fechas y motivo; **Previsualizar impacto** usa Laravel, sin escritura.
4. La simulación muestra pagos confirmados, saldo de ventas emitidas y cuotas
   proyectadas con fechas originales y nuevas. Solicitudes futuras pueden simularse,
   pero **no confirmarse** hasta la fecha de inicio.
5. La fecha operativa (inicio/fin y scheduler) se interpreta en la zona
   **America/Guayaquil**, independientemente de la zona UTC general del backend.
   La fecha efectiva no se calcula usando la fecha UTC del servidor.
6. La revisión financiera también incluye ventas adicionales vinculadas al
   contrato que no estén enlazadas a `membresia_cuotas`. Sus saldos pendientes
   bloquean la aprobación y siempre permanecen en Cartera.
7. Si FACTURACION tiene calendario contractual de cuotas fijas, sin cobros
   automáticos/recurrentes, sin saldos de ventas emitidas, y al menos una
   cuota futura PROGRAMADA sin venta cuyo período de emisión no ha comenzado,
   se puede confirmar **únicamente el día de inicio de la congelación**.
6. La operación registra movimiento autorizado y suspensión VIGENTE
   y cambia el estado a CONGELADA dentro de una transacción. La venta ya
   pagada y todas las cuotas quedan intactas mientras dure la congelación.
7. EmisionCuotasServicio evita nuevas ventas si hay suspensión VIGENTE de
   FACTURACION. CobroProgramadoMembresiaServicio excluye toda membresía
   CONGELADA del cobro recurrente. Ambos revalidan condiciones tras bloquear.
8. Cuando el administrador reactiva dentro del límite previsto, Laravel calcula
   los días **efectivamente utilizados** (hasta el día anterior al regreso).
   Dentro de una transacción, ajusta los períodos deportivos afectados,
   la vigencia, **solo las cuotas PROGRAMADA sin venta**, y registra
   una fila en `membresia_reprogramaciones` por cada cuota modificada.
9. La app móvil puede leer congelaciones y nuevas fechas de vencimiento;
   no puede aprobar ni modificar acuerdos.

### Ejemplo: contrato dos cuotas

- 1 nov–30 nov 2026: cuota 1 de $60 ya pagada (venta, comprobante, pago conservados).
- 1 dic–31 dic 2026: cuota 2 de $60 aún PROGRAMADA, sin venta.
- Simular congelación 15 nov–31 dic (47 días): nueva vigencia teórica 16 feb 2027;
  emisión cuota 2 proyectada 17 ene 2027; vencimiento cuota 2 proyectado 20 ene 2027.
- Esta fecha futura solo se **simula** hasta 15 nov. Para confirmar ese día,
  se revalida el estado financiero completo en transacción.
- El calendario definitivo **no** se mueve al congelar: se mueve al reactivar
  en función de los días reales. Mientras permanezca congelado, el scheduler
  no emite las cuotas de congelación financiera.
- El día de pago originalmente contratado queda preservado en el snapshot.
  Cualquier cambio negociado de día fijo requiere un acuerdo versionado
  independiente; la compensación de suspensión desplaza las fechas reales.

### Bloqueos deliberados

- Fecha de congelación futura: simulación exclusivamente.
- Fecha final superior a la vigencia original: requiere proceso separado.
- Congelación retroactiva: no permitida ordinariamente.
- Contrato sin calendario de cuotas fijo / cobros recurrentes: bloqueado para
  FACTURACION hasta establecer política e idempotencia financiera específica.
- Venta emitida con saldo: la obligación se conserva en Cartera, no hay
  anulación automática ni nueva emisión hasta revisión.
- Cuota PROGRAMADA cuya emisión estaba prevista antes del inicio:
  regularizar previo al acuerdo.
- Solicitudes simultáneas o suspensión ya vigente: rechazadas.
- Reactivación el mismo día de la congelación: rechazada.
- Reactivación posterior al fin previsto: exige revisión administrativa,
  sin extensión automática no autorizada.
- Cambio concurrente de fecha/estado/venta de cuota: abortar la transacción;
  la reprogramación nunca modifica cuotas EMITIDA ni ANULADA.
- No se alteran `ventas.pagos`, `ventas.ventas`, `cuentas_cobrar.cuentas`
  ni comprobantes durante congelar/reactivar. Solo una venta nueva
  futura se emitirá contra la cuota reprogramada cuando llegue su fecha.

### Pruebas y validación

- `php artisan test tests/Unit/Gimnasio/PoliticaCicloVidaMembresiaTest.php tests/Unit/Gimnasio/ImpactoFinancieroCongelacionServicioTest.php`.
- `php artisan migrate:status | grep ciclo_vida` debe mostrar **Ran**.
- En base **local** validar un contrato con primera cuota PAGADA
  y segunda PROGRAMADA. Primero preview sin modificaciones de datos.
- Para probar confirmación y reactivación crear contrato de prueba aislado;
  no experimentar sobre pagos reales. Congelación dentro de día actual,
  reactivación otro día. Verificar `movimientos`, `suspensiones`,
  `reprogramaciones`, calendario y comportamiento del scheduler.
- Comprobar con dos procesos concurrentes que el emisor no use fechas
  anteriores tras reactivación. No ejecutar `migrate:fresh` en bases con datos.
- Revisar integración Agenda/Acceso y automatización de reactivaciones antes
  de liberar a producción: un estado CONGELADA no reemplaza autorización
  deportiva, revocación de reservas ni política de ingreso.
- La migración comercial `2026_10_31_160000_import_revive_catalogo_comercial_produccion`
  sigue pendiente/errores en desarrollo y se evalúa por separado.
