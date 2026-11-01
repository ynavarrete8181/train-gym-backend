# REVIVE SPORTS — Condiciones comerciales históricas del contrato

## Objetivo

La configuración de Planes y Modalidades es **vigente**, no histórica. Un contrato firmado conserva sus condiciones aunque el catálogo cambie. Un cambio posterior solo afecta futuras contrataciones y renovaciones nuevas.

## Migración nueva

`2026_11_01_010000_add_condiciones_contratadas_snapshot_to_membresias.php`

Agrega `membresias.membresias.condiciones_contratadas` (JSONB, nullable). **No ejecutar en producción sin comparar esquemas y autorizar el despliegue.** No modifica ni reconstruye contratos anteriores.

## Snapshot generado al contratar

`ContratoCuotasServicio::programar()` captura la configuración justo antes de generar las cuotas: identificadores y nombres de plan y modalidad, tipo de producto, reglas comerciales, sede, tipo y cantidad de duración, frecuencia, servicios incluidos (ID y nombre), precio por período, cantidad de períodos, total bruto referencial, fecha de inicio y primer pago, versión y fecha de captura.

El snapshot representa condiciones originales del contrato. Los precios netos de cada venta y descuentos aplicados se siguen consultando en Ventas, períodos y cuotas; el total bruto referencial **no sustituye** al saldo financiero.

## Auditoría

`PlanServicio` audita modificaciones del plan con versiones antes/después que incluyen modalidades, servicios y precios por sede. `guardarModalidad()` registra antes/después de la modalidad y sus precios por sede en `auditoria.eventos`. `ContratoCuotasServicio` audita `FIJAR_CONDICIONES_CONTRATADAS` con el snapshot contractual.

No se crean tablas adicionales de historial.

## Lecturas

`MembresiaServicio::obtenerMembresiaConRelaciones()` y `DeportistaControlador::show()` exponen `condiciones_contratadas` decodificado, priorizan nombres y duración histórica y muestran cuotas. El frontend del Perfil integral usa el precio histórico en edición, evitando mostrar nuevas tarifas como antiguas.

## Compatibilidad y precauciones

- Los contratos anteriores a esta migración seguirán con `condiciones_contratadas = NULL`. No inferir el precio histórico desde el catálogo de hoy.
- La asignación usa el precio vigente en ese momento y copia ese valor a períodos/cuotas. Posteriores modificaciones de `plan_modalidades` o precios por sede **no alteran períodos/cuotas ya creados**.
- Los ajustes contractuales posteriores (cambio de modalidad, duración, precio, descuentos, cancelaciones o reembolsos) requerirán un flujo específico auditado, no una edición silenciosa del snapshot.
- No se han realizado pruebas locales ni despliegue de esta iteración.

## Pruebas que se deben ejecutar

1. Ejecutar migración nueva solo en PostgreSQL local.
2. Crear modalidad de 4 semanas y valor 60, contratar 5 períodos, confirmar snapshot, cinco períodos y cinco cuotas por 60.
3. Modificar precio de modalidad y precio por sede a 75, así como su duración a 1 mes; confirmar evento de auditoría antes/después.
4. Consultar contrato anterior y comprobar que mantiene 4 semanas, cinco períodos, 60 por cuota y fechas originales.
5. Crear contrato nuevo y confirmar uso de duración/precio modificados.
6. Probar cambio de nombre de modalidad y servicios incluidos; confirmar que la ficha anterior muestra el snapshot histórico.
7. Comprobar que el build del frontend y `php -l` terminan sin errores.
8. Probar pago parcial, descuentos y cartera en operaciones de integración antes de habilitar cobros en producción.
