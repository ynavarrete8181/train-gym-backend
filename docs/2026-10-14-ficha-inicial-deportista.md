# Ficha profesional de valoración y seguimiento — Revive

## Norma visual
Seguir `docs/2026-09-22-convenciones-globales-ui.md` del frontend: menú vertical a la izquierda, tablas en el panel derecho, botón Añadir, formularios con `BotonGuardar` y `dbanuStyles.formActions` (extremo inferior derecho), avisos mediante `NotificacionSnackbar` superior derecho.

## Arquitectura y persistencia
- `clientes.fichas_iniciales`: primer borrador de cuatro secciones (estructura heredada; no eliminar hasta decidir migración de datos).
- `clientes.fichas_iniciales_historial`: auditoría de cambios del borrador anterior.
- `clientes.valoracion_registros`: registros fechados **inmutables a través de la API**, asociados a deportista, sección, responsable, título, fechas y datos permitidos.
- `clientes.valoracion_controles`: controles programados enlazados a registros; estado PROGRAMADO o REALIZADO, usuario responsable y resultado de control.

La nueva interfaz usa `valoracion_registros`, no sobrescribe la ficha inicial y no depende de membresías. Los antecedentes permiten registrar inicio y finalización. Las evaluaciones físicas generan una nueva instantánea fechada, con próximo control opcional. El IMC, si se incorpora después, debe ser orientativo en adultos y nunca establecer aptitud médica.

## API
- GET `/api/base/gimnasio/clientes/{id}/valoraciones?seccion=evaluacion`
- POST `/api/base/gimnasio/clientes/{id}/valoraciones` con campos `seccion,titulo,fecha_registro,fecha_inicio?,fecha_fin?,proximo_control?,datos`.
- GET `/api/base/gimnasio/valoraciones/controles-pendientes?dias=7`: administrador, lista los controles PROGRAMADO con fecha próxima o vencida.

Autorización: ADMINISTRADOR o ENTRENADOR con asignación activa de un perfil de entrenador vinculado a su usuario. La consulta global de controles corresponde exclusivamente a ADMINISTRADOR. Validaciones y permisos en Laravel.

## Pendientes para cerrar la fase
- Integrar el listado de controles con el centro de notificaciones real y la ejecución programada. El endpoint de controles pendientes **no envía ni crea notificaciones automáticamente**.
- Evaluar si `entrenamiento.progresos_corporales` ofrece campos y claves compatibles con el nuevo seguimiento y evitar registros físicos duplicados; por ahora son fuentes independientes.
- Migrar o preservar de forma visible los borradores previos de `fichas_iniciales` antes de usar el nuevo historial en clientes que ya tengan ficha.
- Probar migraciones, consultas, seguridad por rol, concurrencia, controles y compilación de frontend en un entorno de desarrollo. No desplegar en producción sin autorización.

## Aplicación de migración
En base de desarrollo únicamente:
`php artisan migrate --path=database/migrations/2026_10_14_170000_create_valoracion_registros_controles.php`
Verificar previamente conexión de `.env`, existencia del esquema `clientes` y de `clientes.deportistas`, `seguridad.users`.

## Progreso visual y fotografías — ampliación

- `GET /api/base/gimnasio/clientes/{id}/progreso-informe` genera un informe de lectura desde `clientes.valoracion_registros` de sección `evaluacion`; calcula en Laravel IMC, masa magra y variación del peso. No genera nuevas mediciones ni permite editar este historial desde Progreso.
- `clientes.valoracion_fotografias` se relaciona obligatoriamente con una evaluación física y su deportista. Imágenes JPG/PNG/WEBP hasta 5 MB en disco `local` **privado**, acceso protegido por autenticación y comprobación de rol/asignación. Endpoints GET listado, POST carga y GET imagen de `clientes/{id}/fotos-valoracion`.
- En la pestaña Ficha inicial → Evaluación física → detalle se añaden las fotos; en Progreso se consultan las gráficas y la galería fotográfica.
- **No almacenar imágenes como URLs públicas ni en la base PostgreSQL**. Antes de desplegar Railway, configurar almacenamiento privado persistente mediante volumen o proveedor compatible y verificar copias de seguridad, permisos del filesystem, retención y consentimiento informado. La interfaz actual recuerda el consentimiento, **pero aún no impone su registro ni permite revocación/retención automática**: completar esos controles antes de habilitar fotografías para usuarios reales.
- **Pendiente de migración de históricos:** `entrenamiento.progresos_corporales` contiene datos anteriores y su servicio usa la relación antigua `gimnasio.deportistas`. No se modifica ni elimina esa tabla. Se debe verificar el mapeo contra `clientes.deportistas` usando el esquema real, conciliar registros y deduplicar de forma idempotente antes de declarar `clientes.valoracion_registros` como histórico íntegro. Actualmente los reportes nuevos leen solo valoraciones recientes.
- **Validación pendiente:** sintaxis, rutas, subida/lectura de blobs autenticados, permisos, almacenamiento persistente, migraciones y UI en desarrollo. No se ha probado contra PostgreSQL ni desplegado en producción.
