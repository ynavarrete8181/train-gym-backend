# Descuentos individualizados de membresía (fase de persistencia)

Fecha: 2026-10-19. Rama: dev-revive. No desplegar a producción todavía.

## Decisiones
- La tarifa oficial permanece en `membresias.planes` y `membresias.plan_modalidades`.
- Un beneficio pertenece a un deportista y puede aplicar a todos los planes, a un plan o a una modalidad.
- Se admite PORCENTAJE (0–100) o FIJO (importe no negativo), con inicio obligatorio y fin opcional.
- No se acumulan automáticamente múltiples beneficios; prevalece modalidad específica, luego plan, luego general, y fecha de inicio más reciente.
- La evaluación para una renovación se realiza en su fecha de cobro, preservando el precio original en la venta y aplicando el descuento solo a operaciones nuevas.
- Solo se administra con permiso GIMNASIO-PLANES; validar rol administrativo de quien tiene este permiso antes de exponer la pantalla.

## Entregado
- Migración `membresias.descuentos_deportistas`.
- Servicio `DescuentoDeportistaServicio` con creación, actualización y resolución por vigencia.
- API GET/POST `/gimnasio/descuentos-deportistas` y PUT `/gimnasio/descuentos-deportistas/{id}`.

## Pendiente antes de activar
- Integración transaccional con asignación, renovación manual y cobro automático, incluidos los tres flujos de ventas, el desglose del descuento y la trazabilidad del precio original.
- Snapshot de descuento en período/venta, validación de no solapamientos, autorización exacta por rol, pruebas automáticas y UI de clientes/membresías.
- Pruebas de migración y despliegue escalonado backend -> frontend -> producción, con rollback de aplicaciones.
