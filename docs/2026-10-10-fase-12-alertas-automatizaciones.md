# Fase 12 — Alertas y automatizaciones operativas

Fecha: 2026-10-10

## Objetivo

Convertir condiciones operativas relevantes de Revive en alertas persistentes, accionables y deduplicadas, reutilizando la infraestructura existente de avisos en tiempo real.

## Arquitectura

Persistencia:

- `notificaciones.alertas_operativas`

Entrega al usuario:

- `seguridad.avisos_usuario`
- `AvisoUsuarioService`
- emisión en tiempo real mediante `TiempoRealUsuarioService`

Servicio:

- `app/Services/Alertas/AlertaOperativaServicio.php`

Controlador:

- `app/Http/Controllers/Api/Alertas/AlertaOperativaControlador.php`

Vista:

- `Dashboard > Alertas operativas`

Permiso:

- `DASHBOARD-ALERTAS`

Roles:

- SUPERADMINISTRADOR
- ADMINISTRADOR
- SUPERVISOR DE VENTAS

## Condiciones iniciales

### Cartera vencida

Genera una alerta consolidada por sede cuando existen cuentas vencidas con saldo pendiente.

Destino funcional:

- Reporte de cartera vencida.

### Membresías próximas a vencer

Genera una alerta consolidada por sede cuando existen membresías cuyo último período termina dentro de los próximos 7 días.

Destino funcional:

- Reporte de membresías por vencer.

### Conciliaciones de caja pendientes

Genera una alerta crítica por sede cuando existen cierres automáticos pendientes de conciliación.

Destino funcional:

- Conciliación de caja.

### Diferencias de caja

Genera una advertencia por sede cuando los cierres del día presentan diferencia distinta de cero.

Destino funcional:

- Conciliación de caja.

## Ciclo de vida

Estados:

- `ACTIVA`
- `RESUELTA`

Cuando la condición aparece:

- se crea la alerta;
- se registra `detectada_at`;
- se notifica a usuarios autorizados.

Mientras continúa:

- se actualiza `ultima_deteccion_at`;
- no se crean duplicados;
- como máximo se genera un recordatorio cada 24 horas.

Cuando deja de existir:

- cambia automáticamente a `RESUELTA`;
- se registra `resuelta_at`;
- el historial se conserva.

## Automatización

Comando:

`php artisan revive:procesar-alertas-operativas`

Frecuencia:

- cada hora;
- `withoutOverlapping()`.

El scheduler de Laravel debe permanecer activo en producción.

## Destinatarios

Las alertas se entregan únicamente a usuarios activos de los roles:

- SUPERADMINISTRADOR
- ADMINISTRADOR
- SUPERVISOR DE VENTAS

El SUPERADMINISTRADOR recibe alertas globalmente.

Los demás usuarios reciben únicamente alertas de sedes incluidas en su contexto institucional activo.

## Vista

`AlertasOperativasPage` muestra:

- sede;
- tipo;
- nivel;
- título;
- detalle;
- estado;
- fecha de detección;
- última detección;
- fecha de resolución.

Filtros:

- búsqueda;
- sede;
- tipo;
- nivel;
- estado.

Incluye:

- actualización manual;
- PDF global Revive;
- Excel global Revive;
- resumen de activas, críticas, advertencias y resueltas.

## Principio de diseño

Una alerta no equivale a un aviso.

`notificaciones.alertas_operativas` conserva el estado real de la condición.

`seguridad.avisos_usuario` representa la entrega de esa condición a cada usuario.

Esta separación evita duplicados, conserva historial y permite futuras extensiones a correo, push o app móvil sin modificar la lógica de detección.


### Meta comercial en riesgo

La Fase 13 agrega la condición `META_EN_RIESGO`.

Se evalúa para metas activas del mes actual cuando:

- el mes ya alcanzó al menos 50% de avance temporal;
- el porcentaje de cumplimiento de ventas queda más de 15 puntos por debajo del ritmo esperado.

La alerta dirige al módulo `DASHBOARD-METAS-COMERCIALES` y utiliza el mismo ciclo de vida ACTIVA / RESUELTA y la misma política de deduplicación de la Fase 12.
