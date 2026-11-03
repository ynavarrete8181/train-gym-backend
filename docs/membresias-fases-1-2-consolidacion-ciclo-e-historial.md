# REVIVE SPORTS — Fases 1 y 2: regreso anticipado e historial integral

## Contexto y diseño base
Documentación consultada: `docs/membresias-vacaciones-programadas-fase-3.md`, `docs/membresias-renovacion-manual-y-regreso-anticipado.md`, `docs/membresias-ciclo-vida-politica-c-fase-3.md`.
Se mantienen las capas Servicios Laravel, controladores API, rutas `routes/base/gimnasio.php` y componentes React con Material UI, `uiTokens`, `formStyles` y estilos `dbanuStyles`. La experiencia operativa permanece en **Clientes → Perfil integral → Membresías**. No se crean catálogos duplicados.

## Fase 1 — Reactivación anticipada, cálculo consistente
- La reactivación manual de una suspensión `VIGENTE` conserva el día del regreso como primer día **activo**, no como día suspendido.
- Para 02/11/2026–15/12/2026, reactivar el 03/11/2026 reconoce únicamente el 02/11 (1 día). Si el vencimiento original fue 31/12/2026, queda 01/01/2027.
- Regresar el 10/12/2026 reconoce 02/11–09/12 (38 días), fin ajustado 07/02/2027.
- Previsualización y persistencia invocan ahora `PoliticaCicloVidaMembresia::calcularRegreso`, evitando dos fórmulas ligeramente distintas.
- Se rechazan el mismo día de inicio y el regreso después de la fecha aprobada (requiere revisión administrativa).
- `CicloVidaMembresiaServicio::reactivar` guarda `fecha_regreso`, `fecha_fin_real`, `dias_reconocidos`, `fecha_fin_anterior`, `fecha_fin_nueva` y `motivo_codigo` en la auditoría. Conserva pagos y ventas emitidas; en `TIEMPO` mantiene las fechas de cobro.
- El frontend muestra las cuatro cifras del cálculo y un mensaje final con días efectivos y vencimiento anterior/nuevo.
- Se añadieron cuatro casos en `tests/Unit/Gimnasio/PoliticaCicloVidaMembresiaTest.php`.

## Fase 2 — Historial integral, origen separado de estado actual
Se creó `HistorialIntegralMembresiaServicio`, disponible mediante:
`GET /api/base/gimnasio/membresias/{id}/historial-integral`
El endpoint respeta los mismos permisos del módulo y el alcance operativo de la sede. No hace cambios de estado, pagos, vencimientos ni períodos.

La API presenta:
- `contratacion_original`: condiciones capturadas en la primera entrada `CONTRATACION` de `membresia_condiciones_historial`; nunca reconstruidas de los campos actuales.
- `vigencia_actual`: `fecha_inicio` y `fecha_fin` vigentes en `membresias.membresias`.
- `eventos`: un arreglo cronológico que une el historial comercial y `membresia_movimientos`, con responsable, motivo, tipo, fecha y detalle de la operación, junto a la suspensión asociada cuando existe.
- Si no existe historial de contratación original, `contratacion_original` es `null`. No se inventa un vencimiento original.

En React, `HistorialIntegralMembresia.jsx` sustituye la vista fragmentada de `CondicionesHistoricasContrato.jsx` en la acción **Ver historial del contrato**.
Se muestran las fechas originales y actuales en campos diferentes, seguidas por movimientos expandibles de contratación, cambio administrativo, congelación, reprogramación, cancelación, reactivación y renovación. Los datos proceden directamente de Laravel.

## Verificación pendiente en entorno de desarrollo
No hay ejecución de PostgreSQL, PHP, Vite o pruebas end-to-end desde el entorno de conexión GitHub. Antes de desplegar:

```bash
cd train-gym-backend
php artisan optimize:clear
php artisan test tests/Unit/Gimnasio/PoliticaCicloVidaMembresiaTest.php
php artisan test tests/Unit/Gimnasio/RenovacionContratoProgramadoTest.php
php artisan route:list --path=membresias
```

```bash
cd train-gym-web
npm run build
npm run dev
```

Casos para validar con una base de desarrollo y un cliente de pruebas:
1. Original 31/12 y reactivación del 03/11: fecha actual 01/01, original sigue 31/12.
2. Reactivación con regreso anticipado posterior: días completos hasta ayer, no incluye regreso.
3. Regreso el día de inicio o después del autorizado: rechazo sin escritura.
4. Doble confirmación concurrente: una sola reactivación.
5. Historial de contrato nuevo, renovado, congelado, reactivado y sin snapshot de contratación.
6. Verificar que roles sin alcance de sede no consulten el historial integral.
7. Venta pagada, cuotas programadas y Cartera permanecen inalteradas tras TIEMPO.
8. Historial combinado ordenado, sin eventos duplicados y con usuario/responsable real.
9. Comprobar que la interfaz muestra correctamente fechas originales y vigentes sin confundirlas.
10. Verificar rutas del frontend y compatibilidad en pantalla pequeña.

**Alcance de esta entrega:** `dev-revive` solamente, sin datos reales, sin `main`, sin Railway y sin cambios de app móvil.
