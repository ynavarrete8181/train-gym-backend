# Correo de Revive con Gmail API

Transporte disponible en `dev-revive`: `GMAIL_API`. Esta implementación utiliza OAuth 2.0 y HTTPS (443), sin SMTP. No afecta el proceso de invitaciones, campañas, plantillas ni la cola `correos`.

## Autorización de la cuenta remitente

1. En Google Cloud Console, crear/seleccionar un proyecto, habilitar **Gmail API**, configurar la pantalla de consentimiento y el cliente OAuth 2.0.
2. Autorizar **ynavarrete8181@gmail.com** con el alcance **`https://www.googleapis.com/auth/gmail.send`** y acceso **offline**, obteniendo un *refresh token* por un flujo OAuth consentido. Si la aplicación es externa y permanece en *Testing*, los refresh tokens pueden expirar tras siete días: revisar el estado de publicación para operación sostenida.
3. Guardar en Railway, tanto en `train-gym-backend` como en `train-gym-worker` (preferiblemente mediante variables compartidas/referenciadas):
   - `REVIVE_CORREO_TRANSPORT=GMAIL_API`
   - `GMAIL_OAUTH_CLIENT_ID`
   - `GMAIL_OAUTH_CLIENT_SECRET`
   - `GMAIL_OAUTH_REFRESH_TOKEN`
   - `GMAIL_SENDER=ynavarrete8181@gmail.com`
4. No compartir ni subir tokens/secretos a GitHub; no cambiar el transporte en producción hasta contar con las credenciales autorizadas.
5. Verificar que el worker quede en estado SUCCESS y enviar una sola invitación de prueba. Revisar el historial y la recepción del correo. Una respuesta 200 de Gmail API significa que Gmail aceptó el mensaje, no garantiza entrega en bandeja.

## Estados y errores

Los intentos registran el transporte real (`MICROSOFT_GRAPH`, `SMTP` o `GMAIL_API`). En el callback `failed()` se cierran como `ERROR` los intentos que permanecen en `PROCESANDO` cuando el job termina definitivamente. El transporte Gmail API usa límites de conexión y de espera más cortos que el timeout de los jobs.

Los registros de intentos de trabajos antiguos terminados antes del cambio no se corrigen automáticamente. No editar sus estados sin verificar primero que no existe un job activo para esa notificación.

## Despliegue

El cambio se implementa y prueba primero en `dev-revive`. Su promoción a `main`/producción requiere autorización expresa; un simple push a `dev-revive` no modifica producción.
