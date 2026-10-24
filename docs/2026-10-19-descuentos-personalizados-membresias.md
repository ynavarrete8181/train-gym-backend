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


## Fase 2 implementada (2026-10-19)
- Asignación inicial y renovación manual: `MembresiaControlador` calcula descuento al generar la venta (sin alterar precio base), conserva subtotal, descuento y total.
- Cobro programado: `CobroProgramadoMembresiaServicio` aplica el beneficio en la fecha del cobro, actualiza comprobante y registra referencia/importe de descuento en el período.
- Segunda migración: `membresias.membresia_periodos.descuento_id` y `descuento_importe`, para preservar el descuento aplicado aun si después se edita/desactiva el beneficio.
- Auditoría de creación, actualización y desactivación por el servicio existente `RegistraAuditoria`.
- Prevención de fechas superpuestas para el mismo deportista y alcance.
- Test unitario `DescuentoDeportistaServicioTest` con redondeo y límite del importe.
- Frontend `src/features/gimnasio/components/DescuentosDeportistaPanel.jsx` integrado en `MembresiasPage.jsx` (GET/POST/PUT protegidos).

## Antes del despliegue
**NO desplegado.** Railway solo presenta el entorno `production`.
Pendientes de validación:
1. Ejecutar php artisan migrate en un entorno de pruebas con esquema igual a producción y verificar llaves foráneas de ambos cambios.
2. Ejecutar tests PHP y build del frontend con dependencias reales; ejecutar smoke tests de autorización y endpoints GET/POST/PUT.
3. Verificar cálculo con y sin descuento en asignación inicial, renovación manual y cobro automático; revisar subtotal, comprobante, cartera y snapshot `descuento_importe`.
4. Verificar permisos administrativos de `GIMNASIO-PLANES`; el descuento no debe ser editable por cajero/entrenador.
5. Evitar despliegue de `dev-revive` completo sin revisar las diferencias con `main`, pues puede contener otros cambios no relacionados.
6. Usar una ventana de mantenimiento para migración y despliegue con respaldo y plan de rollback.

Regla: no se modifican valores históricos ni se aplican descuentos a ventas ya emitidas.
