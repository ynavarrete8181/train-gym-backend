# Fase 10 — Auditoría, trazabilidad y logs técnicos

Fecha: 2026-10-10

## Principio global

Revive separa de forma estricta la trazabilidad funcional de los logs técnicos.

### Auditoría permanente

Esquema `auditoria`:

- `auditoria.eventos`: acciones funcionales relevantes del sistema.
- `auditoria.accesos`: login, logout e intentos fallidos.

La auditoría es permanente. Ningún proceso automático de retención elimina registros de este esquema.

Las acciones funcionales se registran desde servicios de negocio mediante `RegistraAuditoria` / `AuditoriaServicio`. Los controladores permanecen delgados.

### Logs técnicos rotativos

Esquema `logs`:

- `logs.eventos`
- `logs.excepciones`
- `logs.integraciones`

Las excepciones no controladas son capturadas globalmente desde `bootstrap/app.php` mediante `LogSistemaService`.

Política de retención:

- INFO: 30 días.
- WARNING: 60 días.
- ERROR: 90 días.
- Excepciones: 90 días.
- Integraciones correctas: 30 días.
- Integraciones con error o HTTP >= 400: 90 días.

La limpieza se ejecuta todos los días a las 02:30 mediante:

`php artisan revive:limpiar-logs`

La tarea nunca elimina información de `auditoria.*`.

## Trazabilidad comercial

Permiso:

`AUDITORIA-TRAZABILIDAD-COMERCIAL`

Menú:

`Auditoría > Trazabilidad comercial`

Fuente única:

`auditoria.eventos`

Procesos visibles:

- Ventas
- Pagos
- Caja
- Cartera
- Membresías

Incluye:

- fecha/hora
- sede
- proceso
- referencia
- acción
- usuario
- rol
- descripción
- IP
- datos antes
- datos después

El alcance por sede se valida en backend mediante `AlcanceOperativoService`.

Exportaciones:

- PDF global Revive
- Excel global Revive

## Integraciones

Permiso:

`AUDITORIA-INTEGRACIONES`

Menú:

`Auditoría > Integraciones`

Permite consultar proveedor, tipo, dirección, endpoint, estado, duración, request, response y error.

## Reportes de auditoría

Permiso:

`AUDITORIA-REPORTES`

Menú:

`Auditoría > Reportes de auditoría`

Reportes disponibles:

1. Actividad por usuario
2. Actividad por módulo
3. Cambios críticos
4. Accesos fallidos
5. Errores recurrentes

Todos usan la información existente de `auditoria.*` y `logs.*`. No se crean tablas duplicadas.

Los reportes soportan PDF y Excel con el estándar global de Revive.

## Renovaciones de membresía

Se completó la trazabilidad de:

- `RENOVAR_MEMBRESIA`
- `VINCULAR_VENTA`

sobre `membresias.membresia_periodos`.

## Regla de arquitectura

Auditoría registra operaciones válidas e importantes del negocio.

Logs registran fallos, advertencias y diagnóstico técnico.

No se auditan clics, paginación, búsquedas ni navegación sin cambio de estado.


## Cobertura transversal de todo Revive

Auditoría y logs son infraestructura global del sistema, no funcionalidades exclusivas de un módulo.

### Auditoría global de respaldo

El middleware `AuditarOperacionGlobal` se ejecuta sobre todo el grupo API.

Para toda operación exitosa:

- POST
- PUT
- PATCH
- DELETE

si ningún servicio de negocio registró una auditoría detallada durante el request, se crea automáticamente un evento `OPERACION_HTTP` en `auditoria.eventos`.

Esto garantiza cobertura para módulos actuales y futuros, entre ellos:

- Personas
- Clientes
- Membresías
- Ventas
- Pagos
- Caja
- Cartera
- Agenda
- Entrenadores
- Entrenamiento
- Servicios
- Inventario
- Seguridad
- Usuarios
- Roles y permisos
- Configuración
- Notificaciones
- Comunicaciones
- Resultados
- Reportes
- Accesos
- Integraciones

Cuando el servicio de negocio usa `RegistraAuditoria`, el middleware detecta la auditoría detallada y no crea un duplicado.

El evento global no persiste payloads de formularios, contraseñas, tokens ni datos sensibles. Solo conserva método, ruta, nombre de ruta y estado HTTP.

### Correlación técnica global

Todo request del API recibe un `request_id` mediante `AsignarRequestId`.

El identificador:

- se conserva si llega un `X-Request-ID` válido;
- se genera como UUID cuando no existe;
- se devuelve en la respuesta `X-Request-ID`;
- es reutilizado por `LogSistemaService`;
- permite relacionar request, error e integración.

### Regla permanente

`auditoria.*` no tiene proceso de borrado automático.

La retención automática aplica únicamente sobre `logs.*`.
