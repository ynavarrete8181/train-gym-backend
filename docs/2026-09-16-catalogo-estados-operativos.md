# Catálogo central de estados operativos - 2026-09-16

## Objetivo

Centralizar la identidad y presentación de los estados sin romper las reglas de negocio existentes. Se adopta `configuracion.estados_catalogo` como fuente maestra y se mantiene temporalmente la columna textual `estado` durante la transición.

## Estructura

Cada estado posee:

- `id`: identificador relacional.
- `codigo`: código estable del sistema, por ejemplo `MEM_PENDIENTE_PAGO`.
- `entidad`: dominio, por ejemplo `MEMBRESIA`, `VENTA`, `PAGO`, `RESERVA`.
- `valor_interno`: valor legado/operativo usado por las reglas actuales, por ejemplo `PENDIENTE_PAGO`.
- `nombre`: etiqueta visible.
- `color`, `orden`, `activo`, `es_inicial`, `es_final`.
- `protegido_sistema`: impide desactivar códigos involucrados en reglas de negocio.

## Tablas enlazadas en esta fase

Migración:

`2026_09_16_120000_link_operational_states_to_catalog.php`

Se agrega `estado_id` con FK hacia `configuracion.estados_catalogo.id` en:

- `gimnasio.membresias` -> entidad `MEMBRESIA`.
- `ventas.ventas` -> entidad `VENTA`.
- `ventas.pagos` -> entidad `PAGO`.
- `gimnasio.reservas_dia` -> entidad `RESERVA`.

La migración rellena `estado_id` a partir de la combinación `entidad + valor_interno = estado` de los registros existentes.

## Compatibilidad temporal

No se elimina todavía la columna `estado`. Durante esta fase toda escritura nueva mantiene sincronizados:

- `estado`: valor interno legible por el código legado.
- `estado_id`: FK al catálogo central.

Esto permite probar el cambio sin perder compatibilidad ni datos. La eliminación de la columna textual se hará solamente después de validar todos los flujos y búsquedas.

## Servicio central

Se incorpora:

`App\\Services\\Configuracion\\EstadoCatalogoServicio`

Responsabilidades:

- resolver un estado por `entidad + valor_interno`;
- obtener el `estado_id`;
- sincronizar `estado` y `estado_id` antes de persistir;
- rechazar valores no configurados o inactivos.

## Controladores y servicios adaptados

### Membresías

- creación asigna `PENDIENTE_PAGO` o `ACTIVA` y su `estado_id` correspondiente;
- edición valida el valor contra el catálogo `MEMBRESIA`;
- listado y detalle retornan `estado_codigo`, `estado_valor`, `estado_nombre`, `estado_color`;
- pago completo de una venta vinculada actualiza simultáneamente `estado = ACTIVA` y `estado_id`.

### Ventas

- alta/edición valida contra catálogo `VENTA`;
- persistencia sincroniza `estado` + `estado_id`;
- recálculo por pagos sincroniza `PENDIENTE`, `PARCIAL` o `PAGADA` con su ID;
- listados retornan presentación del catálogo.

### Pagos

- alta valida contra catálogo `PAGO`;
- persistencia sincroniza `estado` + `estado_id`;
- listados retornan presentación central.

### Reservas

Los estados reales del flujo se normalizan en el catálogo:

- `RES_RESERVADA` -> `RESERVADA`.
- `RES_ASISTIO` -> `ASISTIO`.
- `RES_CANCELADA` -> `CANCELADA`.
- `RES_NO_ASISTIO` -> `NO_ASISTIO`.

El controlador valida contra la entidad `RESERVA`, asigna `estado_id` y expone nombre/color/código en consultas.

## Frontend

- `Configuración > Estados` administra el catálogo con el patrón visual del Sistema Base.
- `Ventas` y `Pagos` consumen los estados retornados por la API en lugar de arreglos locales.
- `Membresías` y `Reservas` conservan temporalmente sus valores internos en el formulario; el backend ya es la autoridad y persiste el ID. Pendiente retirar los últimos arreglos locales tras validar la migración en entorno local.

## Reglas de arquitectura

1. El catálogo define identidad, etiqueta, color, orden y disponibilidad.
2. Las transiciones siguen siendo reglas de backend. Crear un estado no crea automáticamente una transición.
3. Estados protegidos no se eliminan ni desactivan desde administración.
4. Nuevas entidades con estados deben relacionarse por `estado_id`; no se deben crear nuevos enums/listas duplicadas sin revisar primero este catálogo.
5. Antes de retirar columnas textuales se debe validar búsqueda, filtros, auditoría, integraciones y datos históricos.

## Pendientes

- ejecutar migración y validar backfill en base local;
- probar creación/edición de membresía;
- probar venta pendiente -> pago parcial -> pagada;
- comprobar activación automática de membresía;
- probar los cuatro estados de Reservas;
- retirar hardcodes visuales restantes en Membresías y Reservas;
- evaluar en una fase posterior Comprobantes y otros dominios con estados propios.
