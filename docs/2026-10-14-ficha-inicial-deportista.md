# Ficha inicial del deportista — 2026-10-14

## Alcance
La ficha se integra en Clientes, después de Datos del cliente. La navegación es vertical dentro de la pestaña, conforme al patrón de Roles. Secciones: Antecedentes de salud, Evaluación física, Objetivos deportivos y Valoración profesional. Guardar permanece en el extremo derecho.

## Persistencia
Migración `clientes.fichas_iniciales` relacionada 1:1 con `clientes.deportistas`. Conserva cada sección en JSONB, autor del registro, estado borrador y fechas. No depende de contratos de membresía. No altera las tablas ni datos existentes.

## Roles
`ADMINISTRADOR`: lectura/escritura de las fichas de cualquier cliente.
`ENTRENADOR`: lectura/escritura solo si existe una asignación activa del entrenador autenticado al deportista en `entrenamiento.asignaciones_entrenador_cliente`.
Otros roles: 403. Nunca confiar en el rol mostrado en React. El coach puede acceder a las rutas API con el cliente asignado, pero habilitar la navegación a la ficha desde «Mis deportistas» es una tarea complementaria.

## API
GET /api/base/gimnasio/clientes/{id}/ficha-inicial
PUT /api/base/gimnasio/clientes/{id}/ficha-inicial
Payload PUT: `{ "seccion": "antecedentes|evaluacion|objetivos|valoracion", "datos": {...} }`
Validaciones y autorización en Laravel. No existe envío de contraseña ni información sensible en URLs.

## Diferencia con Progreso
Los campos antropométricos existentes en `entrenamiento.progresos_corporales` siguen disponibles en la pestaña Progreso. La ficha registra una fotografía inicial separada; una sincronización bidireccional con Progreso queda pendiente de diseño para evitar registros duplicados. No debe considerarse todavía un registro automático de progreso.

## Límites actuales
Primera versión en estado BORRADOR; la transición a COMPLETADA, historial de versiones y validación de firma profesional requieren fase adicional antes de utilizarse como ficha cerrada. La información de salud debe tener acceso restringido y auditoría de consultas. No desplegar hasta validar migración, pruebas API, roles y navegación con datos reales.
