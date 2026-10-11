# Fase 13 — Metas comerciales y seguimiento

Fecha: 2026-10-10

## Objetivo

Permitir definir objetivos comerciales mensuales por sede y, opcionalmente, por responsable, comparándolos en tiempo real contra ventas, cobros y movimientos reales de membresías.

## Esquema

Se creó un esquema independiente:

- `metas.metas`
- `metas.meta_responsables`

No se almacenan porcentajes ni resultados calculados. El seguimiento se obtiene en tiempo real desde las fuentes operativas.

## Meta por sede

Cada sede puede tener una sola meta por año y mes.

Indicadores configurables:

- meta de ventas;
- meta de cobros;
- meta de membresías nuevas;
- meta de renovaciones.

Campos de control:

- estado ACTIVA / INACTIVA;
- observaciones;
- creado por;
- actualizado por.

## Meta por responsable

Una meta de sede puede distribuir objetivos individuales entre responsables autorizados.

Cada responsable puede tener:

- meta de ventas;
- meta de cobros;
- meta de membresías nuevas;
- meta de renovaciones.

Los responsables deben ser usuarios activos con alcance institucional válido en la sede y roles compatibles:

- RESPONSABLE;
- SUPERVISOR DE VENTAS;
- ADMINISTRADOR;
- SUPERADMINISTRADOR.

## Seguimiento

El sistema calcula en tiempo real:

- ventas reales;
- cobros reales;
- membresías nuevas reales;
- renovaciones reales;
- porcentaje de cumplimiento de cada indicador.

El cálculo se realiza tanto:

- por sede;
- por responsable.

Las metas futuras comienzan con resultado real cero.

## Integración con Dashboard

El Dashboard ejecutivo muestra un bloque:

`Cumplimiento de metas comerciales`

El mes mostrado corresponde al mes de la fecha final del período seleccionado.

Incluye:

- sede;
- ventas meta / real / porcentaje;
- cobros meta / real / porcentaje;
- nuevas meta / real / porcentaje;
- renovaciones meta / real / porcentaje.

## Alertas

La Fase 12 incorpora el tipo:

`META_EN_RIESGO`

Se evalúa durante el mes actual.

Regla inicial:

- el avance esperado del mes debe haber alcanzado al menos 50%;
- si el cumplimiento de ventas está más de 15 puntos porcentuales por debajo del ritmo esperado, se genera la alerta.

La alerta:

- se consolida por meta/sede;
- se deduplica;
- se actualiza automáticamente;
- se resuelve cuando la condición desaparece;
- respeta el máximo de un recordatorio cada 24 horas.

## Permiso y menú

Permiso:

`DASHBOARD-METAS-COMERCIALES`

Menú:

- Dashboard
  - Resumen ejecutivo
  - Alertas operativas
  - Metas comerciales

Roles:

- SUPERADMINISTRADOR
- ADMINISTRADOR
- SUPERVISOR DE VENTAS

El alcance por sede se valida siempre en backend.

## Arquitectura backend

- `app/Services/Metas/MetaComercialServicio.php`
- `app/Services/Metas/Exportaciones/MetasComercialesExportacionServicio.php`
- `app/Http/Controllers/Api/Metas/MetaComercialControlador.php`
- `routes/base/metas.php`

## Arquitectura frontend

Metas se mantiene como dominio propio aunque su acceso esté agrupado bajo Dashboard:

- `src/features/metas/pages/MetasComercialesPage.jsx`
- `src/features/metas/components/MetasComercialesTable.jsx`
- `src/features/metas/components/MetaComercialFormulario.jsx`
- `src/features/metas/components/MetaComercialSeguimiento.jsx`
- `src/features/metas/services/metasComercialesServicio.js`

La implementación anterior dentro de `features/dashboard` fue retirada para evitar acoplamiento entre navegación y dominio.

## Exportaciones

El seguimiento usa el estándar global Revive:

- PDF;
- Excel.

## Auditoría

Las operaciones CREAR y ACTUALIZAR metas generan auditoría funcional explícita sobre:

`metas.metas`

Los cambios también quedan cubiertos por la infraestructura transversal de auditoría de Revive sin duplicar eventos.

## Principio de diseño

La meta es configuración.

El cumplimiento es cálculo.

Por lo tanto, Revive no persiste snapshots de cumplimiento como fuente de verdad; siempre se recalculan contra los datos operativos reales.


## Patrón visual y navegación interna

La interfaz sigue el estándar del Sistema Base y `docs/estandar-visual-global-revive.md`.

### Listado

```text
Paper 1
Metas comerciales

Paper 2
GestionToolbar
Filtros
TablaGestion
Paginación
```

### Nueva / editar meta

La navegación se realiza dentro de `MetasComercialesPage`, no mediante Dialog:

```text
Paper 1
Nueva meta comercial / Editar meta comercial
[Volver]

Paper 2
Configuración mensual
Metas por responsable
[Cancelar] [Guardar]
```

Se reutilizan:

- `PageHeader`;
- `BotonVolver`;
- `AccionesFormulario`;
- `GestionToolbar`;
- `TablaGestion`;
- `FilterHeaderCell`;
- `StatusChip`;
- `dbanuStyles`;
- `formStyles`.

### Seguimiento

```text
Paper 1
Seguimiento de meta comercial
[Volver]

Paper 2
Resumen sede/período
Cumplimiento consolidado
Cumplimiento por responsable
Observaciones
```

No se crean cabeceras repetidas ni Paper principales adicionales.


## Rutas del dominio

Aunque la opción visual se encuentra bajo Dashboard, las rutas pertenecen al dominio Metas:

- `GET /base/metas`;
- `GET /base/metas/catalogos`;
- `GET /base/metas/excel`;
- `GET /base/metas/{meta}`;
- `POST /base/metas`;
- `PUT /base/metas/{meta}`.

`routes/base/dashboard.php` queda reservado para Dashboard y alertas relacionadas con esa superficie; la lógica CRUD de metas no se acopla a ese archivo.
