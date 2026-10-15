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

## Simulación de ficha detallada y pruebas de rendimiento (14-10-2026)

El frontend contiene un botón `Ver ejemplo simulado` en Progreso. Los datos se marcan DEMOSTRACIÓN y no se guardan en la BD, no afectan membresías ni generan push.

- Cliente ficticio: deportista de acondicionamiento general, evaluación inicial del 01-08-2026.
- Medidas sucesivas del 01-08, 15-08, 01-09 y 01-10; pesos 82, 81.7, 81.3 y 80.8 kg. Cintura 92 → 90 cm. Es un escenario de comprobación de gráficos, **no implica automáticamente una mejora clínica ni un objetivo obligatorio de bajar peso**.
- Flexiones: 10, 12, 14 y 16 repeticiones (mismo protocolo). Plancha: 30 → 50 segundos. El ejemplo ilustra una felicitación basada en mejora de desempeño.
- La ficha permite tipos normalizados de antecedentes, objetivos de fuerza/resistencia/movilidad/composición/adherencia y pruebas `FLEXIONES` (repeticiones, mayor es mejor), `PLANCHA` (segundos, mayor es mejor) y `CARRERA_2KM` (segundos, menor es mejor).
- El backend restringe códigos, unidades y rangos y calcula `comparativas_pruebas`. El indicador `mejora_verificada` solo es verdadero si hay al menos dos pruebas con idéntico protocolo no vacío y cambio favorable. **Esto es una validación técnica comparativa, no aval sanitario.**
- No existe todavía una relación demostrada con asistencia, sesiones o ejercicios efectivamente completados: la futura regla de adherencia deberá apoyarse en registros reales de sesiones y no en números estimados.
- Los resultados comparativos se muestran en Progreso; las nuevas medidas y pruebas se ingresan en Ficha inicial → Evaluación física, evitando doble captura.
- **Push y felicitaciones automáticas todavía no implementadas**: diseñar evento idempotente por cliente+prueba+evaluación, preferencias/consentimiento del cliente, límites de frecuencia, mensaje interno y entrega push mediante dispositivo autenticado. No incluir medidas de salud en vista previa de pantalla bloqueada sin autorización.
- No introducir datos de demostración como migraciones o seeds en bases productivas. Compilar/testear frontend y backend y comprobar que el esquema PostgreSQL de desarrollo dispone de las migraciones previas antes de ejecutar pruebas.
