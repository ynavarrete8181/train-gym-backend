# Entrenador - autogestión segura

Fecha: 2026-09-15

## Objetivo

Separar el trabajo diario del entrenador de los módulos administrativos del gimnasio.

El rol `ENTRENADOR` no debe recibir permisos generales de `GIMNASIO-DEPORTISTAS` ni `GIMNASIO-HORARIOS`, porque esos códigos actualmente incluyen operaciones de escritura administrativa.

## Matriz base

La migración `2026_09_15_060000_configure_trainer_permissions.php` deja como base:

- `DASHBOARD`
- `ENTRENAMIENTO-EJERCICIOS`
- `ENTRENAMIENTO-PLANES`
- `ENTRENAMIENTO-RUTINAS`
- `ENTRENAMIENTO-RM`
- `ENTRENAMIENTO-PROGRESO`

La migración `2026_09_15_070000_add_trainer_self_service_permissions.php` agrega:

- `ENTRENADOR-MIS-DEPORTISTAS`
- `ENTRENADOR-MI-AGENDA`

## Seguridad de alcance

Se agregan endpoints específicos:

- `GET /base/gimnasio/mi-entrenamiento/deportistas`
- `GET /base/gimnasio/mi-entrenamiento/agenda`

Ambos resuelven el perfil de entrenador a partir de `request->user()->id` y de `gimnasio.entrenadores.usuario_id`.

No se recibe un `entrenador_id` desde frontend. Esto evita que un entrenador consulte información de otro entrenador modificando parámetros de la solicitud.

`Mis deportistas` reutiliza `AsignacionEntrenadorClienteServicio::listarPorEntrenador()` y devuelve únicamente asignaciones activas del entrenador autenticado.

`Mi agenda` reutiliza `EntrenadorServicio::listarTurnos()` y devuelve únicamente los horarios activos asignados al entrenador autenticado.

## Navegación

Se registran dos páginas del Sistema Base:

- `MisDeportistasPage` → `/mis-deportistas`
- `MiAgendaEntrenadorPage` → `/mi-agenda`

Ambas se ubican bajo el menú existente de Entrenamiento y se sincronizan con los usuarios que tengan rol `ENTRENADOR`.

## Criterio de arquitectura

El frontend solo representa lo autorizado por backend. No existe lógica de autorización basada únicamente en el nombre del rol en las páginas nuevas. La protección real está en `base.auth`, `base.permiso` y en la resolución del entrenador autenticado en backend.

## Prueba de referencia

Usuario de prueba: Daniel Palma (`daniel.palma@revive.local`).

Después de ejecutar migraciones y volver a iniciar sesión, debe visualizar su matriz deportiva y los accesos `Mis deportistas` y `Mi agenda`, sin acceso administrativo a Servicios y Agenda, Acceso, Resultados, Ventas, Inventario o Seguridad.
