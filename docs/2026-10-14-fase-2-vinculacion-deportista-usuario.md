# Fase 2 — Vinculación de deportista y cuenta de acceso

## Objetivo
Mantener independientes la identidad `personas.personas`, el cliente `clientes.deportistas` y la cuenta `seguridad.users`, unidos por `persona_id` y `usuario_id`.

## Flujo
1. Clientes puede registrar una persona y deportista sin usuario (acceso móvil pendiente).
2. Seguridad crea la cuenta para la persona con rol DEPORTISTA, genera clave técnica e inicia la invitación existente mediante `NotificacionUsuarioService::solicitar`.
3. Dentro de la transacción de creación, Seguridad enlaza `clientes.deportistas.usuario_id` si ya existe un cliente de la misma persona y no está vinculado a otra cuenta.
4. Si la cuenta DEPORTISTA ya existía antes de registrar al cliente, el servicio de Clientes la reutiliza por `persona_id` sin duplicarla.
5. La creación de cliente rechaza `usuario_id` de otra persona.
6. El enlace de activación existente permite que el destinatario establezca su contraseña; el formulario de Clientes nunca recibe contraseñas.

## Seguridad y limitaciones
- La cuenta DEPORTISTA no recibe funciones web de administración desde la interfaz Seguridad.
- Una invitación programada no garantiza su entrega: comprobar la cola de correo.
- Pendiente: verificar en base local los datos históricos que tengan `usuario_id` nulo, vínculos cruzados o cuentas de rol incorrecto.
- Pendiente: pruebas de integración transaccionales y de concurrencia, verificación del flujo móvil y controles de acceso.
- No habilitar automáticamente acceso móvil para clientes sin consentimiento o sin correo verificado según política operativa.
- No modificar el entorno de producción ni ejecutar migraciones por estos cambios.

## Pruebas manuales pendientes
- Alta de cliente nuevo sin usuario.
- Crear usuario DEPORTISTA usando exactamente la persona del cliente: debe escribir `clientes.deportistas.usuario_id`.
- Crear cuenta con otro rol: no debe enlazarla.
- Intentar vincular una cuenta ajena: error de validación.
- Cliente nuevo para persona con una cuenta DEPORTISTA preexistente: se reutiliza.
- Persona con cuenta preexistente de otro rol: el cliente queda sin vinculación DEPORTISTA.
- Probar invitación, código y vencimiento desde la aplicación, sin enviar claves por administración.
