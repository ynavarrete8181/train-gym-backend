# Revive · Estándar visual global de vistas operativas

Fecha de actualización: 2026-10-09

## Objetivo

Mantener una interfaz compacta, profesional y coherente entre módulos, reutilizando los patrones del Sistema Base antes de crear componentes o estilos locales.

## Principios obligatorios

1. Evitar duplicar información visual.
2. Priorizar componentes globales antes de crear variantes locales.
3. Mantener el primer `Paper` con separación visual respecto al `PageHeader`.
4. Mantener títulos, botón Volver y acciones alineados y simétricos.
5. Usar tablas compactas con `TablaGestion`.
6. Usar `GestionToolbar` para búsqueda y acciones generales.
7. Usar `FilterHeaderCell` en todas las columnas de datos que admitan filtro.
8. La columna **Acciones** no lleva filtro.
9. Estados mediante chips compactos con borde.
10. Los indicadores/resúmenes deben preferirse como chips compactos en la misma franja de trabajo cuando no necesiten una tarjeta independiente.

## Regla de resúmenes e indicadores

Si un valor ya aparece expresado de forma más útil en un indicador específico, no repetir un contador genérico.

Ejemplo aplicado en Cartera:
- se eliminó el chip genérico `4 RESULTADOS`;
- se conserva `Cuentas abiertas: 4`;
- junto a él aparecen `Saldo por cobrar`, `Cuentas vencidas`, `Saldo vencido` y `Compromisos pendientes`.

Todos usan el mismo patrón visual y pueden variar únicamente color/borde según significado.

## Tablas

Orden recomendado:
- contexto operativo primero, por ejemplo Sede;
- identificador transaccional después, por ejemplo N.º de venta;
- entidad/persona relacionada después, por ejemplo Cliente;
- datos de negocio;
- estado;
- acciones al final.

Los filtros deben ejecutarse en backend cuando afecten información financiera, permisos, sede o reglas de negocio.

## Responsive

Los chips y controles pueden hacer `wrap` cuando no exista espacio horizontal suficiente.
No crear scroll horizontal innecesario solo para conservar una fila de indicadores.

## Aplicación automática

Este estándar debe aplicarse por defecto en nuevas vistas y refactors de Revive, salvo que una necesidad funcional requiera explícitamente otro patrón.


## Alineación de columnas según tipo de dato

La alineación horizontal debe responder al tipo y longitud de la información:

- texto descriptivo, nombres, observaciones, clientes, responsables, conceptos y campos de lectura larga: alineación **izquierda**;
- identificadores cortos, fechas, estados, prioridades, métodos, cantidades, métricas, porcentajes e importes: alineación **centrada**;
- evitar alinear métricas compactas a la derecha salvo que exista una razón contable específica.

Ejemplos:
- `Sede`, `Cliente`, `Responsable`: izquierda;
- `N.º de venta`, `Fecha`, `Prioridad`, `Método`: centro;
- `Transacciones`, `Ventas`, `Saldo`, `Total`, `Operaciones`: centro;
- `Acciones`: centro.

El encabezado y las celdas de una misma columna deben usar la misma alineación.


## Membrete global de reportes

Todos los reportes exportables de Revive deben utilizar un único membrete institucional compartido.

Contenido mínimo:
- logo Revive;
- identificación de reporte institucional;
- título y descripción;
- período/rango;
- filtros aplicados;
- usuario generador;
- rol;
- correo;
- fecha y hora de generación;
- pie institucional.

No se deben crear membretes locales por reporte. Los cambios visuales del membrete se realizan en el componente/servicio global y deben reflejarse automáticamente en todas las exportaciones.


### PDF institucional: márgenes y composición

El patrón global de PDF debe conservar:
- márgenes laterales uniformes de 16 mm;
- margen superior de 16 mm y margen inferior de 18 mm;
- logo/escudo Revive alineado a la izquierda;
- bloque principal del encabezado centrado respecto de la hoja;
- jerarquía: REVIVE → REPORTE INSTITUCIONAL → nombre del reporte → descripción;
- datos de generación en bloque compacto de dos columnas;
- filtros en franja separada;
- tabla sin tocar los bordes laterales;
- pie institucional fijo en todas las páginas.

Los reportes no deben redefinir estos márgenes ni la composición del membrete de forma individual.


## Jerarquía obligatoria de referencia

Revive no define patrones visuales o de navegación desde cero.

Antes de crear o refactorizar una vista se debe revisar, en este orden:

1. **Sistema Base**: repositorio `1312721242/dbanu-frontend`, especialmente `docs/estandar-visual-vistas.md` y las vistas modernas equivalentes.
2. **Estándar visual global de Revive**: este documento y los componentes globales ya adaptados a Revive.
3. **Documentación funcional del módulo**: reglas específicas del dominio que se está implementando.
4. **Vistas Revive ya validadas**: reutilizar su estructura cuando representen el mismo tipo de flujo.

Si existe un patrón equivalente en Sistema Base o en un componente global de Revive, no se crea una variante local sin una razón funcional documentada.

### Navegación interna obligatoria

Para listados que navegan internamente a crear, editar, configurar, revisar o consultar seguimiento, usar estado de vista dentro de la página o un componente hijo del feature.

Patrón:

```text
LISTADO
  ↓ Añadir / Editar / Ver
VISTA INTERNA
  ↓
Paper 1: PageHeader + Botón Volver
Paper 2: formulario / detalle / seguimiento
  ↓
AccionesFormulario cuando exista guardado
```

Reglas:

- no usar un `Dialog` como sustituto de una vista interna cuando el flujo requiere formularios o detalles de trabajo;
- mantener un solo Paper principal de cabecera visible;
- mantener un solo Paper principal de contenido;
- las secciones internas del formulario son `Box`, `Stack`, `Grid`, tabs o contenedores internos, no nuevos Paper principales;
- el primer Paper debe conservar separación visual respecto al contenido siguiente;
- título y botón `Volver` deben estar alineados y simétricos;
- reutilizar `BotonVolver` y `AccionesFormulario`;
- las acciones de tabla deben reutilizar `dbanuStyles.actionView`, `actionEdit`, `actionDelete` o equivalentes globales;
- el CRUD debe mantener backend como fuente de verdad para validaciones y alcance.

### Estructura por feature

Para un dominio funcional propio:

```text
src/features/<modulo>/
├── pages/
├── components/
└── services/
```

Una pantalla no debe ubicarse bajo otro feature únicamente porque aparezca dentro de su menú. Por ejemplo, **Metas comerciales** pertenece a `features/metas`, aunque su entrada de navegación se encuentre bajo Dashboard.


## Regla principal: dos Paper

Siguiendo el Sistema Base, toda vista administrativa o de gestión debe tener, salvo excepción funcional documentada:

1. **Paper 1 — Cabecera**: icono, título, descripción y acciones globales como `Volver`.
2. **Paper 2 — Contenido operativo**: filtros, formularios, indicadores, tablas, seguimiento y acciones.

No crear Paper principales adicionales para:
- filtros;
- bloques de formulario;
- indicadores;
- tablas;
- resúmenes.

Esos elementos deben vivir dentro del Paper 2 mediante `Box`, `Stack`, `Grid`, secciones internas o componentes transversales.

En navegación interna se reemplaza el contenido de los dos Paper de la vista; no se apilan nuevas cabeceras encima de la pantalla anterior.


### Selects con icono

Cuando un selector represente una entidad o dimensión claramente identificable, usar el componente global `CampoSelectIcono` del frontend Revive.

Ejemplos:
- Sede → icono institucional / edificio.
- Año → calendario anual.
- Mes → calendario mensual.
- Estado → bandera / estado.
- Responsable → persona.

No repetir manualmente `InputAdornment`, tamaño, color o espaciado en cada módulo si el selector puede reutilizar el patrón global.


#### Opciones enriquecidas del selector

`CampoSelectIcono` no debe limitarse al icono del campo cerrado. Cuando el selector tenga opciones identificables, el desplegable debe mostrar:

- icono por opción;
- texto principal;
- descripción secundaria cuando aporte contexto;
- indicador visual de la opción seleccionada;
- menú compacto con borde y sombra suaves.

Ejemplos:
- Sede: nombre + “Sede operativa”.
- Año: año + “Año de cumplimiento”.
- Mes: nombre + “Período mensual”.
- Estado: nombre + explicación funcional.
- Responsable: nombre + rol.

Evitar listas desplegables de texto plano cuando el contexto permita una representación más clara.
