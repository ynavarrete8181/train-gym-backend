# REVIVE SPORTS — Vencimiento mensual fijo de cuotas (fase 5)

## Regla aprobada

La **fecha de pago** elegida en la contratación es el **primer vencimiento** y determina el día fijo de cada mes: por ejemplo, 05/11/2026 → 05/12/2026 → 05/01/2027. Esta programación NO altera las fechas de inicio y fin de los períodos deportivos.

Para meses cortos, si el primer pago es el 29, 30 o 31, el vencimiento del mes se ajusta a su último día. Los siguientes vencimientos se calculan siempre desde la fecha **original**, para volver a día 31 cuando el mes lo admita.

- Al contratar, `MembresiaControlador::store` obtiene `dia_pago` de `fecha_primer_pago`, valida la consistencia y lo guarda en el encabezado; no se usa `generar_venta_automatica` heredado.
- `ContratoCuotasServicio::programar` genera vencimientos por meses calendario, independientemente del inicio y fin de cada período, y almacena en `condiciones_contratadas` tanto `dia_pago` como `programacion_vencimientos: DIA_FIJO_MENSUAL`. Las reglas comerciales se incorporan al historial propio del módulo (`membresias.membresia_condiciones_historial`) y a `auditoria.eventos` según el servicio existente.
- Se rechaza la contratación si una fecha de vencimiento resulta posterior al fin de **su período** (especialmente posible para contratos largos de 4 semanas). El controlador no emite ventas ni cuotas incompletas porque opera en una transacción.
- El importe contractual, la emisión de ventas, los cobros y la cartera siguen sus servicios existentes. Los días de gracia NO cambian la fecha de vencimiento original.
- **No se alteran** membresías ya contratadas, cuotas existentes, ni modalidades del catálogo.

## 4 semanas frente a 1 mes

Una modalidad con `tipo_duracion=SEMANAS`, `duracion=4` dura exactamente **28 días**. NO se la considera automáticamente mensual. Una modalidad `tipo_duracion=MESES`, `duracion=1` sigue la duración real de los meses calendario. Ambos tipos pueden contratarse, siempre que su calendario de vencimientos no coloque una cuota después de su propio período.

Para vender mensualidades de noviembre a marzo como **cinco meses calendario**, crear o actualizar de manera autorizada la modalidad comercial a `MESES / 1`, conservando historial de versiones; los contratos previos permanecen con sus condiciones congeladas. No ejecutar un UPDATE masivo sobre la base de datos de producción.

## Ejemplo

- Vigencia: 01/11/2026 al 20/03/2027, modalidad 4 semanas, 5 períodos de 28 días.
- Fecha de pago: 05/11/2026.
- Vencimientos: 05/11, 05/12, 05/01, 05/02 y 05/03. La fecha contractual final sigue siendo **20/03/2027**.
- Si la modalidad fuera de 1 mes, la vigencia desde 01/11/2026 hasta 31/03/2027 sería de cinco períodos mensuales.

## Validaciones locales

```bash
php -l app/Services/Gimnasio/ContratoCuotasServicio.php
php -l app/Http/Controllers/Api/Gimnasio/MembresiaControlador.php
php artisan test tests/Unit/Gimnasio/VencimientoMensualTest.php
```

Probar creación real con Jessael en base local y comprobar fechas en `membresias.membresia_periodos`, `membresias.membresia_cuotas`, `membresias.membresias`, `auditoria.eventos`, y `logs` solo si hay error. Si aún faltan migraciones de historial, aplicar primero las de snapshot y versión comercial en orden; no aplicar migraciones de producción sin autorización.
