# REVIVE SPORTS — Historial comercial por módulo (Fase 4)

## Separación de responsabilidades

- **membresias**: hechos comerciales, contratos, condiciones congeladas, versiones del catálogo, períodos y cuotas.
- **auditoria.eventos**: operación transversal (quién, cuándo, antes/después), mediante el servicio `RegistraAuditoria` existente.
- **logs.eventos / logs.excepciones**: errores y diagnóstico técnico mediante `LogSistemaService` existente. No se crean tablas de logs alternativas.

## Tablas nuevas (migración 2026_11_01_020000)

- `membresias.plan_modalidad_versiones`: ID de modalidad y plan, número de versión único por modalidad, acción, JSONB anterior y nuevo, usuario y fecha.
- `membresias.membresia_condiciones_historial`: ID de membresía, versión única, acción, condiciones JSONB, usuario, motivo y fecha.

Las tablas nuevas son **historial de negocio**, no auditoría técnica. Cada cambio se escribe en la **misma transacción** que modifica el catálogo o programa el contrato. Si falla, se revierte todo; el reporte técnico de excepciones debe mantenerse en el mecanismo global de Logs.

`HistorialComercialMembresiaServicio` vive en `app/Services/Gimnasio`, siguiendo la arquitectura de servicios del sistema base. Usa bloqueo por entidad (`lockForUpdate`) y versiones correlativas para los cambios concurrentes. Las filas históricas no se actualizan en operaciones normales.

## Comportamiento

- Crear o cambiar una modalidad y sus precios por sede registra su versión comercial. Evita registrar versiones cuando el contenido comercial no cambió.
- La operación `eliminarModalidad` **desactiva** las modalidades con historial aunque no estén contratadas. La eliminación física de catálogos históricos ya no es apropiada.
- `ContratoCuotasServicio::programar()` guarda la versión inicial de condiciones pactadas, y continúa guardando `condiciones_contratadas` en el encabezado como snapshot para consultas rápidas.
- Cambiar un precio o duración en el catálogo no altera las filas ya generadas en `membresia_periodos` ni en `membresia_cuotas`.
- Contratos heredados no se reescriben ni se les inventan condiciones históricas.

## Precaución con la migración local

Primero aplicar `2026_11_01_010000_add_condiciones_contratadas_snapshot_to_membresias.php` si todavía no se ejecutó. Después aplicar `2026_11_01_020000_create_membresias_business_history.php`. No ejecutar migraciones genéricas pendientes sin revisar su alcance y sin copia de seguridad.

## Pruebas pendientes, obligatorias antes de producción

1. Validar migraciones con PostgreSQL local.
2. Crear modalidad, verificar versión 1; cambiar precio por sede, versión 2; repetir mismo valor, no crear versión espuria.
3. Crear contrato por cinco períodos, verificar primer snapshot contractual y cuotas.
4. Cambiar la duración de la modalidad; contrato existente mantiene fechas y tarifas.
5. Desactivar modalidad y verificar conservación de versiones y comportamiento de nuevas contrataciones.
6. Confirmar eventos en `auditoria.eventos` y excepciones en `logs.eventos` / `logs.excepciones`.
7. Ejecutar suite de pruebas existentes y probar flujo real del Perfil integral.
