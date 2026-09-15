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
| 2 | Roles | ✅ | Jerarquía Revive V1 registrada y visible |
| 3 | Permisos | 🟡 | Matriz CRUD y acciones especiales por rol |
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
| API crear/editar | ✅ Validado con Revive Centro |
| Cambio de estado | ✅ Estructuralmente disponible |
| Auditoría | ✅ Implementada en servicio |
| Datos operativos V1 | ✅ Migrados |
| Formulario operativo V1 | ✅ Guardado validado |
| Campos opcionales nulos | ✅ Corregido y validado |
| Cadena de migraciones | ✅ Corregida y ejecutada |
| Relación con planes/precios | ✅ Existente |
| Relación con membresías | ✅ Existente |
| Relación con servicios/horarios | ✅ Existente |
| Relación con supervisor | 🔴 Se define en fases 2-4 |
| Menús universitarios heredados | 🟡 Se retiran de ADMINISTRADOR en Fase 3; permanecen en BD hasta revisar dependencias |
| Build frontend | 🔴 Pendiente ejecución local |
| Migración backend | ✅ Ejecutada |
| Prueba CRUD real con las 3 sedes | 🟡 Revive Centro validada; Home/Xpadel pendientes de prueba rápida |

---

# Fase 2 - Roles

## Roles existentes antes de la revisión

- ADMINISTRADOR
- CAJERO
- DEPORTISTA
- ENTRENADOR
- RECEPCIONISTA

## Jerarquía Revive V1 definida

| Nivel | Rol | Alcance principal |
| --- | --- | --- |
| 1 | SUPERADMINISTRADOR | Configuración global, seguridad, roles, permisos, sedes, parámetros e integraciones |
| 2 | ADMINISTRADOR | Administración general del gimnasio y operación del negocio |
| 3 | SUPERVISOR DE VENTAS | Supervisión comercial, cajas, ventas, cierres, anulaciones y desempeño por sedes asignadas |
| 4 | CAJERO | Cobros, facturación, ventas, apertura y cierre de caja |
| 4 | RECEPCIONISTA | Clientes, reservas, check-in y apoyo comercial |
| 4 | ENTRENADOR | Evaluaciones, planificación, rutinas, ejecución y seguimiento deportivo |
| 5 | DEPORTISTA | Acceso a app y a su propia información, entrenamiento, reservas y progreso |

Migración agregada:

`2026_09_15_000000_add_revive_superadmin_and_sales_supervisor_roles.php`

La migración registra `SUPERADMINISTRADOR` y `SUPERVISOR DE VENTAS` como roles activos.

## Checklist Fase 2

| Control | Estado |
| --- | --- |
| Inventario de roles existentes | ✅ |
| Definición jerárquica Revive V1 | ✅ |
| SUPERADMINISTRADOR | ✅ Visible y activo en pantalla Roles |
| SUPERVISOR DE VENTAS | ✅ Visible y activo en pantalla Roles |
| ADMINISTRADOR | ✅ Se conserva como administrador del gimnasio |
| CAJERO | ✅ Existente |
| RECEPCIONISTA | ✅ Existente |
| ENTRENADOR | ✅ Existente; interfaz puede mostrar Coach / Entrenador |
| DEPORTISTA | ✅ Existente |
| Permisos por rol | 🟡 Fase 3 en desarrollo |
| Alcance por sede | 🔴 Fases 3-4 |

---

# Fase 3 - Permisos

## Base funcional validada

La pantalla `Seguridad > Permisos de usuarios` ya permite abrir el detalle de accesos. Se corrigió el flujo para mostrar errores reales de API y para manejar correctamente usuarios con rol o sin rol.

Los permisos marcados con etiqueta `Rol` son heredados de la matriz base del rol. Por ello, los cambios estructurales deben hacerse en el rol y no como excepciones individuales por usuario.

## Separación ADMINISTRADOR / SUPERADMINISTRADOR

Se define que `SUPERADMINISTRADOR` controla la plataforma y que `ADMINISTRADOR` administra el gimnasio.

Migración agregada:

`2026_09_15_010000_split_superadmin_and_gym_admin_permissions.php`

La migración copia al SUPERADMINISTRADOR la matriz global, promueve `admin@revive.local` al rol técnico y retira del ADMINISTRADOR los permisos de Menús, Submenús, Roles, Páginas del sistema, Permisos de usuarios, Configuración de APIs y los módulos universitarios heredados. `SEGURIDAD-USUARIOS` e `INSTITUCIONAL-SEDES` permanecen en ADMINISTRADOR por ser funciones operativas del gimnasio.

## Supervisor de Ventas V1

Migración agregada:

`2026_09_15_020000_configure_sales_supervisor_permissions.php`

La matriz base del rol `SUPERVISOR DE VENTAS` se limita a:

- `DASHBOARD`;
- `GIMNASIO-DEPORTISTAS` para consulta/gestión comercial de clientes;
- `GIMNASIO-MEMBRESIAS` para seguimiento y operación de membresías;
- `VENTAS-CAJAS`;
- `VENTAS-VENTAS`;
- `VENTAS-PAGOS`;
- `VENTAS-COMPROBANTES`;
- `REPORTES-DISPONIBLES`;
- `REPORTES-HISTORIAL`.

No recibe Seguridad, configuración técnica, Integraciones, Entrenamiento ni Inventario. La migración reconstruye la matriz del rol desde permisos existentes del ADMINISTRADOR y sincroniza automáticamente a cualquier usuario que ya tenga el rol.

### Alcance pendiente

La restricción por sedes asignadas todavía no se considera cerrada en esta fase. Debe integrarse con la asignación operativa de usuarios de la Fase 4 para que un Supervisor de Ventas no consulte información de sedes fuera de su ámbito.

La separación actual de permisos es por módulo. Más adelante, donde el backend aún agrupa lectura y escritura bajo un mismo código, se evaluará dividir acciones especiales o CRUD si el flujo exige que el supervisor pueda consultar sin modificar determinadas configuraciones.

## Usuarios de referencia

- `admin@revive.local` queda como `SUPERADMINISTRADOR`.
- Andrea Amen queda como `ADMINISTRADOR` activo del gimnasio.

## Checklist Fase 3

| Control | Estado |
| --- | --- |
| Separación permisos por rol / por usuario | ✅ Definida |
| Pantalla Permisos de usuarios | ✅ Abre detalle de accesos |
| Mostrar error real de API | ✅ Implementado |
| Manejo de usuarios sin rol | ✅ Corregido |
| SUPERADMINISTRADOR hereda control global | ✅ Validado visualmente |
| ADMINISTRADOR sin seguridad técnica | ✅ Aplicado |
| ADMINISTRADOR sin módulos universitarios | ✅ Aplicado |
| Andrea Amen como ADMINISTRADOR | ✅ Validado |
| SUPERVISOR DE VENTAS | 🟡 Matriz V1 creada; pendiente ejecutar/probar |
| CAJERO | 🔴 Pendiente revisar |
| RECEPCIONISTA | 🔴 Pendiente revisar |
| ENTRENADOR | 🔴 Pendiente revisar |
| DEPORTISTA | 🔴 Pendiente revisar |
| Matriz CRUD + acciones especiales | 🟡 En desarrollo |
| Restricción por sede | 🔴 Pendiente fases 3-4 |

## Próxima validación

1. Ejecutar `php artisan migrate` para aplicar la matriz de SUPERVISOR DE VENTAS.
2. Abrir `Seguridad > Roles > SUPERVISOR DE VENTAS` o asignar temporalmente el rol a un usuario de prueba para verificar los accesos heredados.
3. Confirmar que no aparezcan Seguridad, Integraciones, Entrenamiento ni Inventario.
4. Confirmar Dashboard, Clientes, Membresías, Ventas y Reportes.
5. Continuar con la matriz de CAJERO y RECEPCIONISTA.

## Nota de arquitectura

Los submódulos heredados `Facultades / Direcciones`, `Campos amplios` y `Carreras / Áreas` permanecen temporalmente en código y base de datos para no romper dependencias históricas. Se retiran del rol operativo ADMINISTRADOR, pero no se eliminan físicamente hasta comprobar que ningún flujo Revive los necesita.
