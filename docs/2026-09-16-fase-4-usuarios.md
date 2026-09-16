# Fase 4 - Usuarios - 2026-09-16

## Objetivo

Cerrar el modelo de usuario de Revive antes de continuar con Coaches/Entrenadores y Deportistas, manteniendo la arquitectura del Sistema Base y evitando duplicar persona, credenciales, rol, permisos o asignaciones operativas.

## Modelo adoptado

Un usuario administrativo se compone de:

- persona/datos básicos en `seguridad.users`;
- un rol principal en `users.usr_tipo`;
- estado de acceso en `users.usr_estado`;
- permisos efectivos en `seguridad.cpu_userfunction`;
- asignaciones operativas en `institucional.usuario_contexto` cuando el rol lo requiere.

El sistema sigue trabajando con un rol principal por usuario. Los permisos individuales son excepciones sobre la matriz base del rol, no un sistema multirrol.

## Sincronización de permisos

`App\Services\Seguridad\UsuarioService` mantiene las siguientes reglas:

1. Al crear un usuario sin personalización explícita, copia las funciones activas de `seguridad.cpu_userrolefunction` a `seguridad.cpu_userfunction`.
2. Si cambia el rol principal, reconstruye los permisos efectivos desde el nuevo rol.
3. `guardarFuncionesUsuario()` permite guardar la selección efectiva del usuario.
4. La sincronización emite `MENU_ACTUALIZADO` después del commit para refrescar navegación en tiempo real.

## Asignación operativa

La relación vigente es:

`institucional.usuario_contexto`

Características:

- un usuario puede tener uno o varios contextos;
- el primer contexto se marca como principal;
- la reasignación reemplaza la colección anterior;
- los contextos se construyen desde sede + unidad + carrera/área cuando corresponde.

### Roles que requieren asignación operativa

En esta fase se considera que requieren al menos una asignación operativa:

- ADMINISTRADOR;
- SUPERVISOR DE VENTAS;
- CAJERO;
- RECEPCIONISTA;
- ENTRENADOR;
- otros roles internos futuros, salvo regla específica.

### Roles sin asignación operativa interna

No se fuerza `usuario_contexto` para:

- SUPERADMINISTRADOR: alcance global técnico;
- DEPORTISTA: su sede/alcance deriva de membresías, reservas y relaciones deportivas;
- RESPONSABLE: su alcance deriva de los deportistas vinculados y permisos de app.

El backend normaliza estos roles dejando `contextos = []`.

## Protecciones de seguridad revisadas

`UsuarioController` protege:

- rol inexistente/inactivo;
- cambio del propio rol desde Usuarios;
- inactivación de la propia cuenta con la sesión abierta;
- contextos requeridos según el rol;
- unicidad de cédula y correo;
- contraseña segura en creación.

El flujo de creación ya no intenta validar un `$usuario` inexistente antes de crear el registro.

## Frontend

`UsuarioForm` usa el patrón visual del Sistema Base y quedó alineado con backend:

- SUPERADMINISTRADOR no muestra asignación operativa;
- DEPORTISTA y RESPONSABLE no muestran asignación operativa;
- DEPORTISTA y RESPONSABLE no reciben permisos administrativos web desde este formulario;
- roles internos requieren al menos una asignación antes de avanzar;
- se conserva el Stepper `Información del usuario` -> `Permisos y confirmación`;
- se utilizan componentes comunes de contraseña, botones, confirmaciones y estilos.

## Hallazgo sobre el listado local

En una validación visual del entorno local se observó una columna `Asignación` con valores como `Revive Centro`, `Recepción | Revive Centro` y `General`. La versión de `UsuariosTable.jsx` visible en GitHub al momento de esta revisión no contenía esa columna, lo que indica una diferencia entre el working tree/local y la rama remota.

No se debe sobrescribir esa mejora visual sin reconciliar primero la versión local con `dev-revive`.

## Reglas conceptuales importantes

1. La sede operativa del personal no es la misma cosa que la sede comercial/deportiva de un cliente.
2. DEPORTISTA no debe recibir contexto operativo por ser cliente de una sede; esa relación pertenece a membresía/reserva/entrenamiento.
3. RESPONSABLE tampoco debe recibir contexto operativo; su alcance se resolverá mediante la relación Responsable <-> Deportista.
4. SUPERADMINISTRADOR es global y no debe quedar artificialmente limitado por una sede.
5. ADMINISTRADOR, SUPERVISOR, CAJERO, RECEPCIONISTA y ENTRENADOR sí deben poder restringirse posteriormente por sus contextos asignados.

## Pendientes para cerrar Fase 4

- reconciliar la columna `Asignación` del entorno local con el componente remoto;
- probar creación de un usuario de personal con contexto obligatorio;
- probar creación/edición de SUPERADMINISTRADOR sin contexto;
- probar DEPORTISTA y RESPONSABLE sin contexto y sin permisos web;
- validar cambio de rol de personal a DEPORTISTA/RESPONSABLE y limpieza de contextos;
- validar cambio de rol entre roles internos y resincronización de permisos;
- validar que un usuario no pueda cambiar su propio rol ni inactivarse;
- definir y aplicar el filtrado real por `usuario_contexto` en los módulos que deban restringirse por sede;
- ejecutar build frontend y pruebas funcionales locales.

## Siguiente fase

Una vez validados estos casos, continuar con la fase de Entrenadores/Coaches y Deportistas, reutilizando `seguridad.users` como identidad y evitando duplicar personas.
