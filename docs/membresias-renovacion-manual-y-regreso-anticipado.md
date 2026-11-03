# REVIVE SPORTS — Regreso anticipado y renovación manual de contratos con cuotas

## Fuentes internas
- `docs/membresias-vacaciones-programadas-fase-3.md`
- `docs/membresias-ciclo-vida-politica-c-fase-3.md`
- `app/Services/Gimnasio/CicloVidaMembresiaServicio.php`
- `app/Services/Gimnasio/ContratoCuotasServicio.php`
- `app/Services/Gimnasio/EmisionCuotasServicio.php`
- `app/Services/Gimnasio/HistorialComercialMembresiaServicio.php`
- Componentes web del sistema base: `BotonVistaPrevia`, `BotonGuardar`, `BotonCancelar`, `Dialog`, `formStyles`, `uiTokens`.

## 1. Regreso anticipado (ya existía en Laravel; vista mejorada)

`Clientes → Membresías → Gestionar ciclo de vida → Reactivar membresía`.

- Solo membresía `CONGELADA` con suspensión `VIGENTE`.
- El usuario ve la fecha final prevista y calcula con Vista previa el regreso efectivo **hoy en Ecuador**; no permite introducir una fecha futura o retroactiva manualmente.
- Los días reconocidos cuentan desde el inicio de la suspensión hasta **ayer**, inclusive. Debe existir al menos un día completo congelado.
- El fin contractual original se amplía únicamente por esos días y los tramos de períodos se desplazan según las reglas de Laravel.
- La modalidad `TIEMPO` conserva fechas de cobro, pagos y ventas. `FACTURACION` valida Cartera antes de reprogramar únicamente cuotas futuras sin emitir.
- Al guardar se crea movimiento `REACTIVAR` y la suspensión pasa a `FINALIZADA`. Nunca se crea una segunda membresía.

## 2. Renovación manual del contrato existente (nueva implementación)

`Clientes → Perfil integral → Membresías → Renovar membresía` (ícono de flechas circulares).

El endpoint anterior `POST /membresias/{id}/renovar` sigue sirviendo únicamente a contratos del modelo heredado. No se alteró su conducta.

El nuevo contrato con calendario explícito utiliza:
- `GET /api/base/gimnasio/membresias/{id}/renovacion-programada/preview`
- `POST /api/base/gimnasio/membresias/{id}/renovacion-programada`

En ambos se comprueba permiso de Membresías y alcance operativo de sede. Laravel exige:
1. Contrato `ACTIVA`, con snapshot de condiciones originales y `renovable=true`.
2. Sin suspensiones `VIGENTE` ni `PROGRAMADA`. Primero se resuelve la congelación, para tomar la fecha final efectiva que resulte de su compensación.
3. El período finaliza en los próximos 45 días (inclusive) y la membresía no ha vencido. Para contratos ya vencidos se mantiene el flujo de nueva contratación hasta implementar un proceso de recuperación excepcional.
4. Coherencia entre período final, cantidad de períodos, cuotas y `fecha_fin`.
5. Todas las ventas emitidas previamente deben estar `PAGADA`; ni ventas parciales ni cuotas cuya emisión ya esté atrasada.
6. Un máximo de 36 períodos por contrato.
7. Duración, día de pago y precio calculados desde las condiciones **históricas** pactadas, no desde un catálogo actual editable.

`Vista previa` no escribe datos. `Guardar` vuelve a validar dentro de una transacción y `lockForUpdate`, y agrega:
- Período N+1 en `membresia_periodos`.
- Cuota N+1 `PROGRAMADA` en `membresia_cuotas`, referenciando el nuevo período.
- Actualización de cantidad de períodos y fin del contrato, sin tocar fechas o ventas previas.
- Movimiento `RENOVACION_MANUAL` en `membresia_condiciones_historial` con usuario, condiciones anteriores implícitas en versiones y datos nuevos.

La cuota nueva **no emite venta** en el momento del registro: se emite con `revive:emitir-cuotas-membresias` cuando llega la fecha programada. La emisión mantiene idempotencia y vincula Ventas/Cartera.

**Control que requiere validación integral antes de producción:** comprobar que Control de Acceso, validación de servicios y el flujo de confirmación de pago **no permitan utilizar un período renovado no pagado**, incluso si el contrato ha ampliado su fecha final. El flujo de renovación actual guarda períodos/cuotas, pero no prueba por sí mismo la regla de acceso. No usar renovaciones reales en producción hasta validar este punto y la respuesta de pagos parciales.

## Ejemplos

- Sin congelación: fin 31/12/2026 → un mes renovado comienza 01/01/2027, termina 31/01/2027.
- Si la reactivación consolidó la vigencia hasta 13/02/2027 → siguiente mes comienza 14/02/2027 y termina 13/03/2027, sujeto a calendario financiero y día pactado.
- Si vuelve hoy durante una congelación: al reactivar se reconocen solo días completos hasta ayer. La renovación no está disponible hasta quedar `ACTIVA` y dentro de los 45 días finales.

## Verificación pendiente

```bash
php artisan test tests/Unit/Gimnasio/RenovacionContratoProgramadoTest.php
php artisan test tests/Unit/Gimnasio/PoliticaCicloVidaMembresiaTest.php
php artisan route:list --path=membresias
php artisan schedule:list
```

Web: `npm run lint` y `npm run build`.

Pruebas funcionales obligatorias en base de desarrollo: ingreso sin sede, plazo no renovable, cuota pagada versus parcial, congelación vigente, calendario vencido, concurrencia entre dos renovaciones, scheduler de cuotas, pago de renovación, acceso antes/después del pago, vigencia con compensación y secuencia de 36 períodos.

Cambios solo en `dev-revive`, sin deploy a `main`/Railway ni modificaciones en la app.
