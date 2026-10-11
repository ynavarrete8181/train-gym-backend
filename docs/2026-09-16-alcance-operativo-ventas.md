# Alcance operativo por sede - Ventas - 2026-09-16

## Objetivo

Aplicar de forma efectiva las asignaciones operativas de Usuarios y las capacidades de Sedes al módulo existente de Ventas, sin crear vistas paralelas por rol.

## Regla general

Una operación de negocio queda autorizada por la combinación de:

1. permiso/función del usuario;
2. sede incluida en `institucional.usuario_contexto`;
3. capacidad habilitada en `institucional.sedes`.

Para Caja/Ventas la capacidad utilizada es `maneja_caja`.

`SUPERADMINISTRADOR` conserva alcance global, pero las operaciones reales siguen respetando la capacidad habilitada de la sede.

## Servicio central

`App\Services\Seguridad\AlcanceOperativoService`

Responsabilidades:

- detectar alcance global de SUPERADMINISTRADOR;
- obtener sedes permitidas del usuario;
- filtrar por una capacidad funcional de sede;
- validar que una operación pertenezca a una sede permitida;
- resolver sede desde caja, membresía o venta.

Capacidades soportadas inicialmente:

- `maneja_caja`;
- `maneja_inventario`;
- `permite_reservas`;
- `permite_entrenamiento`.

## Integración en Ventas

`VentaControlador` pasa el usuario autenticado a todas las consultas y escrituras.

`VentaServicio` aplica alcance server-side a:

- Cajas;
- Ventas;
- Pagos;
- Comprobantes;
- sedes disponibles en catálogos;
- cajas disponibles;
- membresías disponibles;
- ventas pendientes seleccionables;
- opciones de filtros relacionadas con ventas/pagos.

## Resolución de sede de una venta

La sede se resuelve en este orden:

1. sede de la caja de la venta, si existe;
2. sede de la membresía vinculada, cuando la venta nació antes de seleccionar caja.

Esto permite que una venta pendiente generada desde una membresía siga perteneciendo a su sede antes de ser cobrada.

Si caja y membresía pertenecen a sedes diferentes, la operación se rechaza.

## Registro de pagos

Antes de guardar un pago se valida:

- que la venta pertenezca a una sede permitida para el usuario;
- que `maneja_caja` esté habilitado en esa sede;
- si se selecciona una caja para el pago, debe pertenecer a la misma sede que la venta.

## Caso funcional de prueba: Karol

Karol se utilizará como usuario de rol `CAJERO`.

Prueba esperada:

- asignar a Karol una sede concreta con contexto `Toda la sede` o un contexto interno de esa misma sede;
- iniciar sesión con Karol;
- Cajas, Ventas, Pagos y Comprobantes deben mostrar únicamente datos correspondientes a esa sede;
- los selectores de sede/caja deben contener únicamente las sedes permitidas con `maneja_caja = true`;
- una llamada directa a API intentando operar otra sede debe ser rechazada por backend.

## Arquitectura

No se crea una vista por rol. Se reutilizan las mismas páginas y endpoints del módulo Ventas. El backend determina el alcance y el frontend representa los datos permitidos.

## Próximos módulos

Después de validar el caso de Karol, reutilizar `AlcanceOperativoService` en:

1. Inventario con `maneja_inventario`;
2. Reservas con `permite_reservas`;
3. Entrenamiento con `permite_entrenamiento` y las restricciones propias entrenador-deportista-horario.
