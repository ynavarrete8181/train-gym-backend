# REVIVE SPORTS · Fase 3 · Escenario 1: vacaciones (TIEMPO)

## Base consultada
- `docs/membresias-ciclo-vida-politica-c-fase-1.md` (modelo, auditoría y reglas)
- `docs/membresias-ciclo-vida-politica-c-fase-3.md` (conciliación y cuotas)
- `app/Services/Gimnasio/CicloVidaMembresiaServicio.php`
- Componentes web existentes: `GestionToolbar`, `TablaGestion`, `BotonGuardar`, `BotonVolver`, `formStyles` y `uiTokens`.

El sistema base web REVIVE utiliza componentes Material UI, tabla compacta y colores negro/amarillo del archivo `src/styles/uiTokens.js`; el diseño Figma es una guía de composición, no sustituye estos tokens.

## Política implementada

**TIEMPO (vacaciones)** conserva calendario original de cobros, cuotas, pagos confirmados, descuentos y Cartera. No genera venta nueva por la compensación de días; la vigencia contractual y tramos deportivos se ajustan al reactivar.

1. Administrador con permiso `GIMNASIO-MEMBRESIAS` y contexto operativo activo en la sede del contrato abre el perfil del deportista → Membresías → icono de congelación.
2. Vista muestra tabla de congelaciones por contrato, búsqueda, estados, detalle y botón Añadir. No duplica registros: usa `membresia_suspensiones`, `membresia_movimientos`, `membresia_reprogramaciones`.
3. Añadir: tipo `TIEMPO`, fechas inclusivas, motivo (mínimo ocho caracteres), previsualización del backend.
4. Si el inicio es hoy y la validación comercial/financiera es favorable, `POST /api/base/gimnasio/membresias/{id}/congelacion` congela inmediatamente.
5. Si el inicio es futuro y tipo `TIEMPO`, `POST /api/base/gimnasio/membresias/{id}/congelacion/programar` crea movimiento `PROGRAMADO` y suspensión `PROGRAMADA`. **No** cambia aún `membresias.estado`, `membresias.fecha_fin`, cuotas ni acceso.
6. Scheduler `revive:procesar-congelaciones-programadas` corre diariamente a las 00:02 **America/Guayaquil**. En fecha de inicio, adquiere bloqueo de contrato y suspensión, vuelve a validar estado, vigencia, deudas emitidas y cobro recurrente; cambia a `CONGELADA` solo si corresponde. Si el scheduler no llega en la fecha exacta, registra `NO_APLICADA` sin reconocer períodos retroactivos.
7. Las cuotas futuras `PROGRAMADA` conservan sus fechas. Si una cuota emitida está pendiente de pago al iniciar las vacaciones, la programación queda `NO_APLICADA`; la deuda sigue en Cartera. Nunca se perdona ni reprograma un pago solo por ausencia.
8. Una solicitud aún `PROGRAMADA` se puede cancelar con motivo auditado; conserva movimiento y suspensión en estado `CANCELADA`.
9. Al reactivar, se compensan exclusivamente los días completos reales de ausencia hasta el día anterior al regreso; si no hay regreso informado, el scheduler finaliza automáticamente al día siguiente del último día autorizado, **sin crear una segunda congelación**. Incluso con cron demorado usa solo la fecha aprobada para no sumar días de más. Una ausencia posterior no extiende automáticamente el contrato ni cancela obligaciones.

**No se admite en esta fase** programación anticipada de `FACTURACION`, congelación de cuotas ya emitidas, deudas con saldo ni contratos con cobro automático recurrente sin política financiera específica. Para estos casos Laravel devuelve bloqueo claro.

## Ejemplos de fechas

Contrato con fin original **31/12/2026** y pagos confirmados:

- **15/12/2026 a 29/12/2026 (ambos incluidos)** = 15 días, regreso 30/12/2026, nueva vigencia teórica **15/01/2027**.
- **15/12/2026 a 31/12/2026 (ambos incluidos)** = 17 días, regreso 01/01/2027, nueva vigencia teórica **17/01/2027**.

La fecha comercial de pago sigue según calendario. Las fechas teóricas son simulación mientras el movimiento está `PROGRAMADO` o `VIGENTE`; no se aplican hasta la reactivación.

## Archivos y rutas

- Laravel: `CicloVidaMembresiaServicio`, `CicloVidaMembresiaControlador`, `PoliticaCicloVidaMembresia`.
- API: `routes/base/gimnasio.php` (GET ciclo vida, POST preview, POST programar, POST congelar, POST cancelar programación, POST reactivar).
- Scheduler: `routes/console.php`. Los cobros quedan a las 00:05 Ecuador y la expiración a las 00:15 Ecuador.
- React: `src/features/gimnasio/components/GestionCicloVidaMembresia.jsx` y `src/features/gimnasio/services/gimnasioServicio.js`.
- No se añadieron tablas ni migraciones nuevas por este escenario.

## Verificación requerida antes de utilizar contratos reales

En **base PostgreSQL de desarrollo**:

```bash
php artisan migrate:status
php artisan test tests/Unit/Gimnasio/PoliticaCicloVidaMembresiaTest.php
php artisan test tests/Unit/Gimnasio/ImpactoFinancieroCongelacionServicioTest.php
php artisan test tests/Unit/Gimnasio/EmisionCongelacionConcurrenciaTest.php
php artisan schedule:list
php artisan revive:procesar-congelaciones-programadas
```

En web: `npm run lint` y `npm run build`.

Casos de aceptación:
- Pagado y con cuotas posteriores PROGRAMADA: se puede registrar anticipadamente sin mover el cobro, pero la cuota emitida debe estar pagada al iniciar.
- Programar vacaciones futuras: sigue ACTIVA, aparece en historial, no modifica pagos, cuotas ni vigencia.
- Doble programación: 422 sin segunda suspensión.
- Cancelar futura: mantiene historial y deja contratar nueva ausencia.
- Activación en fecha: queda CONGELADA, no altera precio.
- Activación con deuda: NO_APLICADA, no congela.
- Reactivación anticipada: solo días realmente usados.
- Reactivación al fin previsto y corrida retrasada: compensación de días autorizados, ninguna adicional.
- No volver después de fecha prevista: no segunda congelación automática ni cancelación de deuda.
- Usuario sin contexto de la sede: error y bloqueo sin acceso a datos financieros de esa sede.

**Estado:** commits en `dev-revive`; NO se han ejecutado pruebas locales, migraciones ni despliegue a producción desde ChatGPT. La activación automática depende de que el scheduler Laravel existente esté realmente ejecutándose en el entorno. El acceso físico, reservas y reglas de agenda deben verificarse con pruebas integrales adicionales antes de producción.


## Edición de fechas de una solicitud futura

La interfaz operativa sigue exclusivamente en **Clientes → Perfil integral → Membresías → icono Gestionar ciclo de vida**. Se retiró el botón duplicado del formulario `Editar membresía`.

Para una suspensión **TIEMPO** con estado `PROGRAMADA` y `fecha_inicio` estrictamente posterior a hoy (zona `America/Guayaquil`), la tabla y el diálogo de detalle ofrecen **Editar fechas**.

1. La pantalla carga las fechas y motivo de la misma suspensión; no crea otra ni altera cobros.
2. `POST /api/base/gimnasio/membresias/{id}/congelacion/{suspensionId}/preview-edicion` valida que pertenezca al contrato, esté programada y aún no haya iniciado; simula los días y la fecha final ignorando exclusivamente la propia programación.
3. `PUT /api/base/gimnasio/membresias/{id}/congelacion/{suspensionId}/programacion` vuelve a validar y calcula bajo transacción y `lockForUpdate` del contrato y suspensión. Mantiene `suspension_id` y `movimiento_inicio_id`, actualiza fechas del mismo registro y genera movimiento `REPROGRAMAR_CONGELACION` con antes/después, usuario y motivo.
4. El movimiento original conserva su identidad y se sincronizan su fecha efectiva y detalle proyectado para la consulta y el scheduler; el movimiento nuevo conserva la evidencia de los valores previos.
5. No se alteran directamente membresía, períodos, facturación, cuotas, comprobantes, pagos ni Cartera.
6. Si la programación ya está `VIGENTE`, `FINALIZADA`, `CANCELADA` o el inicio es hoy/anterior, editar se bloquea. Los cambios posteriores requieren flujos independientes de reactivación, prórroga o resolución contractual.

**Comprobaciones pendientes en entorno de desarrollo:** editar dos veces una misma solicitud, nueva fecha fuera de vigencia, inicio igual al día actual, intento de cambiar una suspensión de otro contrato, concurrencia con scheduler, petición sin acceso operativo, carga del movimiento original tras varios cambios, y actualización del calendario tras guardar. Pruebas PHPUnit y build frontend deben ejecutarse localmente antes de producción.
