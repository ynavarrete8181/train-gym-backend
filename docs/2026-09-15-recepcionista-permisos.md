# Matriz base del rol RECEPCIONISTA

Fecha: 2026-09-15

## Objetivo

Cerrar la matriz operativa de `RECEPCIONISTA` respetando el Sistema Base: autorización en backend, navegación derivada de permisos efectivos y sin vistas alternativas por rol.

## Permisos base

- `DASHBOARD`
- `GIMNASIO-DEPORTISTAS`
- `GIMNASIO-MEMBRESIAS`
- `GIMNASIO-SERVICIOS`
- `GIMNASIO-HORARIOS`
- `GIMNASIO-RESERVAS-DIA`

## Fuera de alcance del rol base

No se asignan por defecto:

- Seguridad
- Equipo / Entrenadores
- Ventas / Cajas / Pagos / Comprobantes
- Inventario
- Entrenamiento
- Acceso técnico
- Comunicaciones
- Reportes globales

Si una persona requiere funciones adicionales, deben concederse como permisos individuales desde `Seguridad > Permisos de usuarios`, sin alterar la matriz base del rol.

## Implementación

Migración: `2026_09_15_050000_configure_receptionist_permissions.php`.

La migración reconstruye `seguridad.cpu_userrolefunction` para el rol `RECEPCIONISTA` y sincroniza `seguridad.cpu_userfunction` de los usuarios que ya tengan ese rol, eliminando permisos heredados de matrices anteriores.

## Validación esperada

Al iniciar sesión con un usuario `RECEPCIONISTA`, el menú debe limitarse a Dashboard, Clientes, Membresías y Servicios y Agenda (según asociaciones visibles existentes). No deben aparecer Equipo, Ventas, Acceso, Comunicaciones ni Reportes salvo que se hayan añadido explícitamente como permisos individuales.
