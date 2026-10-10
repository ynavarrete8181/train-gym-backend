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
