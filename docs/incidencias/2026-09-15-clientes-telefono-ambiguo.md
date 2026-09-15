# Incidencia: carga de Clientes por columna teléfono ambigua

Fecha: 2026-09-15

## Síntoma

Al ingresar al módulo `Clientes` con un usuario autorizado, la pantalla mostraba `Error al cargar los clientes` y la tabla quedaba sin resultados.

## Causa

El listado de clientes usa `gimnasio.deportistas` y realiza un `LEFT JOIN` con `institucional.sedes`. Ambas tablas disponen actualmente de una columna `telefono`.

El método `DeportistaControlador::opcionesFiltro()` consultaba `telefono` sin calificar el nombre de la tabla, provocando una referencia ambigua en PostgreSQL después de la ampliación operativa de Sedes.

## Corrección

Se calificaron explícitamente las columnas usadas para construir las opciones de filtro:

- `gimnasio.deportistas.codigo_deportista`
- `gimnasio.deportistas.telefono`
- `seguridad.users.name`
- `institucional.sedes.nombre`

La corrección es de código y no requiere una nueva migración.

## Validación pendiente

1. Actualizar `train-gym-backend` desde `dev-revive`.
2. Refrescar `Clientes` con Karol Cajero / `SUPERVISOR DE VENTAS`.
3. Confirmar que desaparece el error de API.
4. Si el listado queda en cero sin error, revisar si los usuarios con rol `DEPORTISTA` cuentan también con su registro de perfil en `gimnasio.deportistas`; un usuario con rol DEPORTISTA no implica automáticamente que exista su perfil deportivo.
