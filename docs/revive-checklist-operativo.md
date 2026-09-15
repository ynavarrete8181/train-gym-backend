# Revive Sports - Checklist operativo de evolución

Documento vivo para revisar, corregir y cerrar por fases la operación del sistema Revive Sports.

## Método de revisión por fase

Cada fase se valida en este orden:

1. Frontend y experiencia de usuario.
2. API y reglas de negocio.
3. Base de datos y relaciones.
4. Roles y permisos.
5. Flujo real del gimnasio.
6. Estilos y componentes del sistema base.
7. Pruebas funcionales/build.
8. Documentación del cambio y pendientes.

Estados:

- ✅ Terminado y validado.
- 🟡 En revisión/desarrollo.
- 🔴 Pendiente.
- ⏳ Fase futura.

## Fases maestras

| Orden | Módulo | Estado | Resultado esperado |
| --- | --- | --- | --- |
| 1 | Sedes | 🟡 | Sedes y configuración operativa correctas |
| 2 | Roles | ⏳ | Superadmin, Supervisor, Caja/Recepción, Coach, Especialista y Deportista |
| 3 | Permisos | ⏳ | Matriz CRUD y acciones especiales por rol |
| 4 | Usuarios | ⏳ | Persona + usuario + rol + sede |
| 5 | Coaches | ⏳ | Perfil, especialidad, sede y disponibilidad |
| 6 | Deportistas | ⏳ | Ficha maestra del socio y relación con procesos deportivos |
| 7 | Planes | ⏳ | Catálogo de membresías y reglas comerciales |
| 8 | Precios | ⏳ | Valores por sede y vigencia |
| 9 | Servicios | ⏳ | Clases y servicios deportivos |
| 10 | Horarios | ⏳ | Agenda, cupos, sede y coach |

---

# Fase 1 - Sedes

## Base actual identificada

Sedes de prueba reales:

- Revive Centro.
- Revive Home.
- Revive Xpadel.

La tabla `institucional.sedes` originalmente almacenaba únicamente código, nombre, estado y timestamps. El módulo ya contaba con listado, búsqueda, filtros, paginación, creación, edición, cambio de estado, auditoría y protección mediante autenticación/permisos del sistema base.

## Relaciones verificadas

Las sedes ya forman parte de flujos importantes del gimnasio:

- precios de planes por sede (`gimnasio.plan_precios_sede`);
- membresías por sede (`gimnasio.membresias.sede_id`);
- horarios de servicios por sede;
- reservas por sede;
- estructura institucional/contextos heredados del sistema base.

Por ello, `id_sede` se conserva como identificador central y no se reemplaza.

## Ampliación operativa V1

Migración agregada:

`2026_09_14_220000_expand_institucional_sedes_for_revive.php`

Campos incorporados:

- dirección;
- ciudad;
- provincia;
- teléfono;
- WhatsApp;
- correo;
- hora de apertura;
- hora de cierre;
- maneja caja/ventas;
- maneja inventario;
- permite reservas;
- permite entrenamiento.

No se agregó todavía un `supervisor_id`: esa relación se definirá al cerrar las fases de Roles, Permisos y Usuarios para evitar acoplar la sede a un modelo de roles todavía no validado.

## Corrección de guardado con campos opcionales

Durante la primera prueba de edición se detectó que una sede no debía bloquear el guardado cuando los nuevos datos de contacto u horario estaban vacíos. Se corrigió la validación para que los campos opcionales acepten `null`, y el frontend normaliza cadenas vacías a `null` antes de enviar el payload. Los flags operativos continúan siendo booleanos cuando vienen informados.

## Incidencia de cadena de migraciones

Al ejecutar la migración de Sedes se detectó un bloqueo previo en `2026_09_02_150500_fix_membresia_permission_visibility.php`. Esa migración conserva el permiso `GIMNASIO-MEMBRESIAS` sin mostrar un submenú independiente, usando `id_usermenu = null`, pero las tablas `seguridad.cpu_userrolefunction` y `seguridad.cpu_userfunction` mantenían `id_usermenu` como `NOT NULL`.

Se corrigió la migración para permitir `NULL` en `id_usermenu` antes de restaurar los permisos ocultos. Esto desacopla correctamente autorización y navegación: un permiso puede seguir activo para la API sin generar una entrada visible en el menú lateral.

## Checklist Fase 1

| Control | Estado |
| --- | --- |
| Listado de sedes | ✅ |
| Búsqueda | ✅ |
| Filtros | ✅ |
| Paginación | ✅ |
| API crear/editar | 🟡 En prueba real |
| Cambio de estado | ✅ Estructuralmente disponible |
| Auditoría | ✅ Implementada en servicio |
| Datos operativos V1 | 🟡 Implementados, en prueba local |
| Formulario operativo V1 | 🟡 Implementado, en prueba local |
| Campos opcionales nulos | ✅ Corregido en frontend y backend |
| Cadena de migraciones | 🟡 Corregida, pendiente reejecutar localmente |
| Relación con planes/precios | ✅ Existente |
| Relación con membresías | ✅ Existente |
| Relación con servicios/horarios | ✅ Existente |
| Relación con supervisor | 🔴 Se define en fases 2-4 |
| Menús universitarios heredados | 🔴 Pendiente análisis de dependencias antes de ocultar/retirar |
| Build frontend | 🔴 Pendiente ejecución local |
| Migración backend | 🟡 Pendiente confirmar ejecución completa |
| Prueba CRUD real con las 3 sedes | 🟡 Iniciada con Revive Centro |

## Próxima validación

1. Actualizar el backend con la corrección de la migración de membresías.
2. Ejecutar nuevamente `php artisan migrate` hasta completar la cadena pendiente.
3. Confirmar que `2026_09_14_220000_expand_institucional_sedes_for_revive` quede en estado `Ran`.
4. Guardar Revive Centro dejando vacíos los datos opcionales.
5. Recargar y confirmar persistencia.
6. Completar luego los datos reales de las tres sedes.
7. Verificar búsqueda, estado y auditoría.
8. Ejecutar build del frontend.
9. Marcar Fase 1 como terminada o registrar correcciones.

## Nota de arquitectura

Los submódulos heredados `Facultades / Direcciones`, `Campos amplios` y `Carreras / Áreas` permanecen temporalmente en código y base de datos. No deben eliminarse hasta comprobar todas sus dependencias. En Revive se evaluará ocultarlos del menú si no participan en ningún proceso deportivo u operativo.
