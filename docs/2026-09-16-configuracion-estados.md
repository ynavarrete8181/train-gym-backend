# Configuración > Estados

Fecha: 2026-09-16

## Objetivo

Centralizar los estados funcionales de Revive Sports para evitar valores visibles y catálogos dispersos en controladores y frontend.

Se crea el esquema `configuracion` y la tabla:

`configuracion.estados_catalogo`

## Campos principales

- `codigo`: identificador global y estable del estado, por ejemplo `MEM_PENDIENTE_PAGO`.
- `entidad`: contexto funcional: `MEMBRESIA`, `VENTA`, `PAGO`, `RESERVA`, etc.
- `valor_interno`: valor almacenado en la tabla funcional existente, por ejemplo `PENDIENTE_PAGO`.
- `nombre`: etiqueta visible configurable.
- `descripcion`: explicación funcional.
- `color`: semántica de presentación (`default`, `info`, `success`, `warning`, `error`).
- `orden`: orden de presentación.
- `activo`: disponibilidad del estado.
- `es_inicial`: identifica estados iniciales del flujo.
- `es_final`: identifica estados terminales.
- `protegido_sistema`: impide modificar código/entidad/valor interno o desactivar estados usados por reglas de negocio.

## Estados protegidos iniciales

### Membresía

- `MEM_PENDIENTE_PAGO` → `PENDIENTE_PAGO`
- `MEM_ACTIVA` → `ACTIVA`
- `MEM_CONGELADA` → `CONGELADA`
- `MEM_VENCIDA` → `VENCIDA`
- `MEM_CANCELADA` → `CANCELADA`

### Venta

- `VEN_PENDIENTE` → `PENDIENTE`
- `VEN_PARCIAL` → `PARCIAL`
- `VEN_PAGADA` → `PAGADA`
- `VEN_ANULADA` → `ANULADA`

### Pago

- `PAG_CONFIRMADO` → `CONFIRMADO`
- `PAG_ANULADO` → `ANULADO`

### Reserva

- `RES_PENDIENTE` → `PENDIENTE`
- `RES_CONFIRMADA` → `CONFIRMADA`
- `RES_CANCELADA` → `CANCELADA`

## Navegación y permisos

Se crea el menú principal `Configuración` y la función:

`CONFIGURACION-ESTADOS`

La administración queda habilitada para `SUPERADMINISTRADOR` y `ADMINISTRADOR`.

La lectura del catálogo está disponible para usuarios autenticados para que los módulos funcionales puedan obtener etiquetas y presentación sin otorgar permiso de administración.

## API

Base:

`/base/configuracion/estados`

Operaciones:

- `GET /base/configuracion/estados`: consulta paginada y filtros.
- `POST /base/configuracion/estados`: crea estado administrativo.
- `PUT /base/configuracion/estados/{id}`: actualiza configuración permitida.
- `PATCH /base/configuracion/estados/{id}/desactivar`: desactiva estados no protegidos.

## Convención Sistema Base para listados

El endpoint de consulta sigue el contrato estándar usado por los catálogos administrativos:

- `page`;
- `per_page`, por defecto `5`;
- `busqueda`;
- filtros por columna;
- metadatos `pagina_actual`, `por_pagina`, `total`, `ultima_pagina`;
- `opciones_filtro` para filtros tipo Excel del frontend.

Filtros implementados:

- código;
- entidad;
- valor interno;
- nombre;
- color;
- inicial;
- final;
- protegido;
- estado activo/inactivo.

## Regla arquitectónica

El catálogo define **qué estado existe y cómo se presenta**, pero no sustituye las reglas de transición del dominio.

Ejemplo: que exista `MEM_ACTIVA` no implica que cualquier estado pueda pasar a `ACTIVA`. La activación por pago continúa siendo responsabilidad del backend de Membresías/Ventas.

## Pendientes relacionados

- consumir el catálogo central desde selectores de Membresías;
- consumirlo desde Ventas y Pagos;
- hacer que `StatusChip` pueda resolver nombre/color desde catálogo cuando corresponda;
- incorporar futuros catálogos dentro de `Configuración` respetando el mismo patrón del Sistema Base.
