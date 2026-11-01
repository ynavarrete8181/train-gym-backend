# REVIVE SPORTS — Cajas y cobros multisede

## Regla global

- Las ventas pendientes pueden crearse sin caja ni turno de cobro.
- Ningún usuario, ni administradores, puede **registrar pagos** sin su propio turno de caja **ABIERTA**, en una caja activa, con permiso de operar la sede.
- Cada usuario puede tener como máximo un turno abierto. La apertura utiliza la caja seleccionada, cuya sede se obtiene del registro de caja, no del frontend.
- Las sedes operables se determinan por `institucional.usuario_contexto` y `s.maneja_caja`, reutilizando `AlcanceOperativoService`. `ROOT`/`SUPERADMINISTRADOR` tienen alcance global activo según su servicio vigente.
- Cuando solo hay una sede habilitada, React la presenta como lectura automática; con varias, permite elegir antes de seleccionar una caja de la sede.
- La membresía puede habilitar ingreso en varias sedes (`membresias.membresia_sedes`). Una **única venta pendiente** puede cobrarse en cualquiera de estas sedes, siempre que quien cobre tenga allí una caja abierta.
- La sede financiera del cobro se obtiene del `turno_caja_id` y `caja_id` que **Laravel fija**, nunca de un identificador arbitrario del cliente. Se conserva trazabilidad de usuario, turno, caja, sede, importe y método.
- Los cobros de ventas ajenas a una membresía continúan sujetos a la sede de la venta. No se habilita transferir existencias ni facturar productos de inventario desde otras sedes.
- No duplicar ventas al consultar en dos sedes, ni abrir un turno por cada sede simultáneamente.

## Archivos

- `app/Services/Ventas/TurnoCajaServicio.php`: apertura y límite de turno propio (existente).
- `app/Services/Ventas/VentaServicio.php`: validación central de caja propia en `guardarPago`, verificación de sede autorizada de la membresía, y acceso al detalle desde un turno multisede.
- `app/Services/Ventas/VentaPosServicio.php`: visibilidad de cuentas pendientes de membresías habilitadas para la sede del turno.
- Frontend: `src/features/ventas/pages/TurnosCajaPage.jsx` en `train-gym-web`: selección de sede y filtrado de cajas.
- `app/Http/Controllers/Api/Ventas/VentaControlador.php`: ya derivaba automáticamente el turno en el endpoint directo de pagos (se refuerza en el servicio para todos los caminos, incluido POS).

## Verificaciones requeridas antes de producción

1. Andrea con dos sedes operables: elige cualquiera de las dos y abre una de sus cajas activas.
2. Cajero con una única sede: se muestra automáticamente; no puede operar una caja de otra.
3. Cajero con dos sedes: elige sede antes de abrir; segunda apertura concurrente bloqueada.
4. Ningún usuario puede registrar `ventas.pagos` sin turno propio abierto, aunque envíe manualmente `caja_id`/`turno_caja_id`.
5. Turno de otro usuario, caja desactivada y sede sin permiso: no se puede registrar el pago.
6. Jessael con membresía habilitada en Home y XPADEL: la venta pendiente única se consulta y abre desde una caja abierta de cualquiera; el pago queda en la caja efectiva.
7. Venta de Jessael con membresía habilitada solo en Home: el turno de XPADEL no puede cobrarla.
8. Venta de producto sin membresía: conserva restricción de sede e inventario.
9. Tras un cobro parcial en Home, el saldo restante puede cobrarse desde XPADEL sin duplicar venta y respetando saldo.
10. Verificar `ventas.turnos_caja`, `ventas.pagos`, `ventas.ventas`, `ventas.comprobantes`, `auditoria` y arqueo.

No modificar `main` ni producción hasta pruebas integrales con la BD local y autorización expresa.
