# Arquitectura modular sugerida

## Backend

- `app/Http/Controllers/<Modulo>`: controladores delgados, solo validan y delegan.
- `app/Services/<Modulo>`: lógica de negocio y consultas complejas.
- `app/Services/Audit/AuditService.php`: punto único para registrar auditoría.
- `routes/api.php`: rutas agrupadas por prefijo de dominio.

### Convención

- `Inventarios`: productos, categorías, lotes, movimientos, stock.
- `Servicios`: categorías de servicio, tipos de servicio, reservas futuras.
- `Horarios`: horarios, turnos, reglas operativas.
- `Auth`: autenticación, usuarios, perfiles y permisos.

## Frontend

- `src/modules/<modulo>/api.js`: contrato HTTP del módulo.
- `src/pages/<Modulo>`: vistas principales.
- `src/pages/<Modulo>/components`: formularios y tablas específicas del módulo.
- `src/components`: solo componentes realmente globales.

## Auditoría

- Registrar inserción, actualización y desactivación desde servicios de backend.
- No auditar desde controladores ni desde el frontend.
- La tabla fuente de auditoría queda en `train_gimnasio.aud_cambios`.

## Base de datos

- `public`: solo infraestructura técnica y tablas base del framework.
- `train_gimnasio`: todo el dominio del negocio.
- Evitar nuevos duplicados en `public`.
- No separar por esquemas de módulo todavía; primero consolidar el dominio y contratos.
