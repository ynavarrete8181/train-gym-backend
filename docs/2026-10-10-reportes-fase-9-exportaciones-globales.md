# Reportes · Fase 9 · Exportaciones PDF y Excel con membrete global

Fecha: 2026-10-10

## Objetivo

Estandarizar las exportaciones de todos los reportes especializados de Revive mediante un único membrete institucional reutilizable.

El membrete es global. Los reportes no duplican diseño documental; únicamente aportan sus datos, título, descripción, filtros y columnas.

## Membrete global

Servicio backend:

`app/Services/Reportes/Documentos/ReporteMembreteServicio.php`

Formato Excel:

`app/Services/Reportes/Documentos/ReporteExcelDocumentoServicio.php`

Componente PDF/imprimible frontend:

`src/features/reportes/components/ReporteExportaciones.jsx`

Logo institucional:

`public/brand/revive-logo.jpeg`

Origen visual del logo:
`src/assets/brand/revive-logo.jpeg`

## Información común del membrete

Todos los reportes muestran:
- logo Revive;
- REVIVE · REPORTE INSTITUCIONAL;
- nombre del reporte;
- descripción del reporte;
- usuario que generó el documento;
- rol;
- correo;
- fecha y hora de generación;
- período o rango consultado;
- filtros aplicados;
- pie institucional.

El diseño del membrete no se implementa individualmente dentro de cada reporte.

## PDF

El botón **PDF** genera una vista documental profesional en modo impresión:
- orientación horizontal;
- membrete institucional;
- tabla con encabezado repetible;
- alineaciones según tipo de dato;
- colores institucionales;
- pie de documento.

El navegador abre el diálogo de impresión para guardar el documento como PDF.

El PDF recupera todos los registros que cumplen los filtros, no solo la página visible.

## Excel

El Excel se genera en backend con `PhpSpreadsheet`.

Incluye:
- logo institucional;
- nombre y descripción del reporte;
- usuario, rol, correo y fecha de generación;
- período y filtros;
- encabezados profesionales;
- autofiltro;
- congelado de encabezados;
- ajuste automático de columnas;
- pie con nombre del reporte y numeración de página para impresión.

Cada reporte mantiene su exportador independiente bajo:

`app/Services/Ventas/Reportes/Exportaciones/`

## Reportes habilitados

- Resumen comercial
- Cartera vencida
- Cobros por método de pago
- Ventas por período
- Ventas por responsable
- Membresías nuevas y renovaciones
- Membresías por vencer
- Conciliación de caja
- Productos y servicios vendidos

Todos incluyen botones **PDF** y **Excel**.

## Principio de aislamiento

Se comparte únicamente infraestructura documental:
- membrete;
- renderizado Excel;
- utilidad de recopilación paginada;
- componente visual de exportación.

No se comparte la consulta de negocio de los reportes.

Cada exportador llama exclusivamente al servicio de su propio reporte.

Por lo tanto, modificar datos o columnas de un reporte no altera otro reporte.

## Seguridad

Los endpoints de Excel reutilizan exactamente el mismo permiso del reporte correspondiente.

Ejemplo:

`/base/ventas/reportes/ventas-periodo/excel`

usa:

`REPORTES-VENTAS-PERIODO`

El alcance por sede continúa siendo validado por el servicio backend específico.

## Regla para reportes futuros

Todo nuevo reporte debe:
1. tener controlador y servicio propios;
2. tener exportador propio;
3. usar `ReporteMembreteServicio` para Excel;
4. usar `ReporteExportaciones` para PDF/imprimible y acciones;
5. no copiar ni redefinir localmente el membrete.
