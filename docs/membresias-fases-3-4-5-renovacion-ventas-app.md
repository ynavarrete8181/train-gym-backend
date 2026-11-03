# REVIVE SPORTS — Fases 3, 4 y 5: renovación, Ventas/Cartera y aplicación móvil

## Arquitectura y alcance

Ramas `dev-revive`. Se conserva el contrato de membresía existente y se crean nuevos registros únicamente en `membresia_periodos`, `membresia_cuotas` e historial comercial. No se genera una nueva membresía por cada mensualidad. Laravel valida las operaciones y Expo/React presentan respuestas del backend.

Documentación consultada: `docs/membresias-renovacion-manual-y-regreso-anticipado.md`, `docs/membresias-fases-1-2-consolidacion-ciclo-e-historial.md`, `docs/membresias-vacaciones-programadas-fase-3.md`.

## Fase 3 — Renovación manual sobre el mismo contrato

- Entrada: **Clientes → Perfil integral → Membresías → Renovar membresía**, con `DialogRenovacionMembresia`, Vista previa y Guardar.
- `GET /api/base/gimnasio/membresias/{id}/renovacion-programada/preview`: valida tipo fijo, contrato ACTIVA, sin congelación vigente/programada, condiciones originales renovables, coherencia entre fecha final y último período, y todas las cuotas anteriores completamente pagadas.
- `POST /api/base/gimnasio/membresias/{id}/renovacion-programada`: vuelve a validar dentro de transacción y bloqueo de membresía/períodos/cuotas. Inserta un período nuevo `renovacion_manual=true`, su cuota `PROGRAMADA`, actualiza el total de períodos y la nueva `fecha_fin` vigente, y audita `RENOVACION_MANUAL`. No crea venta ni pago.
- **Corrección adicional:** `condiciones_contratadas` es un **snapshot inmutable** de contratación original. NO se actualizan su cantidad de períodos ni su total al renovar. La evolución contractual se consulta en períodos y en el historial. Si existen registros de renovaciones generados antes de esta corrección, revisar snapshots afectados antes de producción.

## Fase 4 — Emisión, pagos y acceso deportivo

- La cuota programada del nuevo período se emite con `EmisionCuotasServicio` en la fecha planificada. Idempotencia por bloqueo y `venta_id`, vinculada a la misma membresía y cuota.
- Ventas guarda el comprobante y `CarteraServicio::sincronizarVenta` calcula sus obligaciones. Los pagos confirmados se suman a la venta; pagos parciales NO bastan para considerarla PAGADA.
- Al iniciar un período `renovacion_manual=true`, el emisor marca la membresía `PENDIENTE_PAGO` si todavía no existe el pago requerido; `ActualizarEstadosMembresiaServicio` actúa como respaldo para períodos renovados sin pagar.
- **Corrección adicional de Ventas:** al confirmar cualquier venta, `VentaServicio::actualizarEstadoVenta` no debe cambiar a ACTIVA una membresía PENDIENTE_PAGO si existe **cualquier** período renovado iniciado con venta pendiente, parcial o ausente. Evita que el pago de una venta antigua rehabilite indebidamente el acceso al nuevo período.
- Regla conservadora: si alguna cuota anterior está sin emitir, parcial, pendiente o anulada, no se permite renovar hasta conciliar. La deuda emitida sigue perteneciendo a Ventas/Cartera.
- Persisten restricciones del alcance de sede. Se conserva la responsabilidad de Laravel y de la programación de tareas existentes; no habilitar facturación fiscal automática ni cobros nuevos desde Expo.

## Fase 5 — App móvil React Native / Expo, solo lectura

Se utiliza `GET /api/app/mis-membresias`, que identifica al deportista por la cuenta autenticada. No expone endpoints de administración al atleta.

El backend presenta:
- `fecha_fin_original` procedente de la versión `CONTRATACION` del historial; `null` si no existe fuente histórica.
- `fecha_fin_vigente` del contrato actualizado.
- `periodos_renovados` filtrados por marca `renovacion_manual`.
- `total_pagado_confirmado` desde pagos confirmados de las cuotas y `saldo_ventas_emitidas` desde ventas emitidas no anuladas. Las cuotas futuras sin emitir NO son saldos emitidos.
- `periodos`, `cuotas`, `suspensiones` y `reprogramaciones` ya existentes.

La aplicación presenta renovaciones y vencimiento comparado en la tarjeta del ciclo de vida y en la pantalla de detalle. El Home identifica correctamente ACTIVA, CONGELADA, PENDIENTE_PAGO, VENCIDA y CANCELADA. Los días de una congelación PROGRAMADA/VIGENTE no se presentan erróneamente como cero días reconocidos finales.

## Migración obligatoria en desarrollo

La migración `2026_11_03_120000_mark_renewed_membership_periods.php` agrega `renovacion_manual` a `membresias.membresia_periodos`. Es necesaria para `MembresiaServicio::periodos`, la emisión y el cierre automático, por lo que **backend actualizado sin esa migración producirá errores**.

```bash
cd train-gym-backend
php artisan optimize:clear
php artisan migrate --pretend --path=database/migrations/2026_11_03_120000_mark_renewed_membership_periods.php
# Tras verificar conexión de desarrollo y tener respaldo:
php artisan migrate --path=database/migrations/2026_11_03_120000_mark_renewed_membership_periods.php
php artisan test tests/Unit/Gimnasio/RenovacionContratoProgramadoTest.php
php artisan test tests/Unit/Gimnasio/PoliticaCicloVidaMembresiaTest.php
php artisan route:list --path=membresias
php artisan schedule:list
```

```bash
cd train-gym-web
npm run build
```

```bash
cd train-gym-app
npx expo start -c
```

## Matriz mínima de pruebas en PostgreSQL de desarrollo (antes de producción)

| Escenario | Resultado exigido |
| --- | --- |
| Contrato 2 cuotas, 1 pagada y 1 programada | Renovación rechazada; no se inserta período/cuota |
| Ambas cuotas completamente PAGADA, contrato renovable y en ventana | Vista previa válida |
| Guardar renovación una vez | Un solo período N+1 y cuota N+1 PROGRAMADA |
| Doble Guardar, dos usuarios simultáneos | Sin duplicados, sin traslape; segundo intento vuelve a validar |
| Venta de renovación antes de fecha de emisión | No existe; no deuda anticipada |
| Emisión en fecha de inicio | Una sola venta/cuota EMITIDA y cuenta en Cartera |
| Procesar emisor dos veces | No duplica ventas, recibos o cuotas |
| Pago parcial de renovación | Venta PARCIAL; membresía no concede acceso por el nuevo período |
| Pagar venta antigua mientras renovación permanece pendiente | No reactiva la membresía |
| Pago completo de renovación, sin otras renovaciones iniciadas impagas | Membresía ACTIVA y período ACTIVO |
| Scheduler omitido el día de inicio | Respaldo de estados identifica renovación impaga |
| Congelación vigente/programada | Bloquea renovación hasta resolver la suspensión |
| Fecha final ajustada por reactivación | Período N+1 comienza al día siguiente del último período actual |
| Acceso físico / turnos / reservas el primer día renovado sin pago | Denegado; verificar los puntos de entrada reales |
| Acceso tras pago completo y elegibilidad de servicios | Permitido según servicio/sede/horarios vigentes |
| App con contrato congelado | Estado CONGELADA; fecha y cobros sin escrituras |
| App con renovación pendiente | Período visible y saldo emitido únicamente si hay venta |
| App con renovación pagada | Pagado confirmado y estado de cuota/venta coherentes |
| Autenticación de otro deportista | No puede consultar contratos ajenos |

## Limitaciones de validación actual

Los archivos y commits fueron verificados en GitHub, **pero no se ejecutaron pruebas PHPUnit, un build React/Expo, ni una prueba integral con PostgreSQL desde este entorno**. La regla de acceso requiere además validar los endpoints reales de agenda/ingreso (no basta con cambiar `membresias.estado`). Prohibido desplegar a `main` o Railway antes de superar las pruebas y contar con autorización explícita.
