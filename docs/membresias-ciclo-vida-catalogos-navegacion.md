# REVIVE SPORTS — Catálogo y navegación del ciclo de vida

## Documentación y componentes base consultados

- `docs/membresias-ciclo-vida-politica-c-fase-1.md`: reglas y auditoría
- `docs/membresias-ciclo-vida-politica-c-fase-3.md`: cuotas, Ventas y Cartera
- `docs/membresias-vacaciones-programadas-fase-3.md`: solicitud futura
- `src/app/routes.jsx` y `src/app/paginasSistema.js`: navegación dinámica y resolución de páginas
- `seguridad.cpu_usermenu`, `seguridad.cpu_userrolefunction`, `seguridad.cpu_userfunction`, `seguridad.cpu_pagina_sistema`: menú controlado por roles
- Componentes globales: `PageHeader`, `GestionToolbar`, `TablaGestion`, `BotonCancelar`, `BotonGuardar`, `BotonVolver`, `AccionesFormulario`, `formStyles` y `uiTokens`
- Componente reutilizable nuevo: `BotonVistaPrevia`

## Alcance implementado en dev-revive

1. Se crea el catálogo `membresias.motivos_ciclo_vida` con operaciones `CONGELAR`, `REACTIVAR`, `CERRAR`, `REABRIR`, código estable, nombre, descripción, orden y estado activo. La migración inserta motivos iniciales sin borrar ni sobrescribir los existentes.
2. CRUD desde la página `MotivosCicloVidaPage`; no admite borrar físicamente motivos para no destruir trazabilidad. Código/operación se mantienen inmutables tras crearse; se desactiva para no ofrecerlo en nuevos movimientos.
3. `GestionesCicloVidaPage` ofrece listado paginado de contratos con acción Gestionar. Reutiliza `GestionCicloVidaMembresia` y sus servicios.
4. Al congelar, el formulario distingue motivo catalogado, justificación libre y modalidad `TIEMPO` / `FACTURACION`. `Vista previa` consulta Laravel sin escritura. `Guardar` solo se activa cuando la vista previa es confirmable o programable y el motivo contiene al menos ocho caracteres.
5. Al reactivar, `GET /api/base/gimnasio/membresias/{id}/reactivacion/preview` calcula días completos de suspensión, nueva vigencia y saldos de Cartera sin escritura. `Guardar` exige esa revisión; Laravel vuelve a validar dentro de su transacción.
6. El código del motivo se valida contra el catálogo ACTIVO y se preserva en el JSON de auditoría del movimiento. Solicitudes históricas sin motivo codificado siguen siendo compatibles.
7. `routes/console.php` define el comando opcional y repetible `revive:configurar-submenus-membresias`. Por defecto **solo simula**; `--aplicar` registra las páginas nuevas como submenús del menú padre real de `GIMNASIO-PLANES`, sin buscar por nombre ni crear otro menú.
   - `GIMNASIO-CICLO-VIDA` → `GestionesCicloVidaPage`, únicamente roles que ya tienen `GIMNASIO-MEMBRESIAS`.
   - `GIMNASIO-MOTIVOS-CICLO-VIDA` → `MotivosCicloVidaPage`, únicamente roles con `GIMNASIO-MEMBRESIAS` y `GIMNASIO-PLANES`.
   - El submenú existente `GIMNASIO-PLANES` se muestra únicamente como **Planes**, sin repetir el nombre del menú padre. Se reutiliza la sincronización oficial de rol/usuario y la asociación `PaginaSistemaService`; no se crean rutas fijas ni se alteran permisos preexistentes.

## Aplicación en desarrollo (NO PRODUCCIÓN)

Desde el backend:

```bash
git checkout dev-revive
git pull origin dev-revive
php artisan optimize:clear
php artisan migrate --pretend --path=database/migrations/2026_11_03_100000_create_motivos_ciclo_vida.php
# Tras revisar el SQL y respaldar la base de desarrollo:
php artisan migrate --path=database/migrations/2026_11_03_100000_create_motivos_ciclo_vida.php
php artisan revive:configurar-submenus-membresias
# Si se identifica el menú padre real de Planes y los roles previstos:
php artisan revive:configurar-submenus-membresias --aplicar
php artisan route:list --path=membresias
php artisan schedule:list
```

Frontend:

```bash
npm run lint
npm run build
```

## Por implementar y validar

No considerar implementados hasta contar con servicio transaccional y pruebas:
- **Cierre anticipado**: liquidación de derechos, cuotas emitidas, deuda, pagos parciales, devolución/saldo a favor, reservas y asignaciones; eventual aprobación independiente.
- **Cancelación al vencimiento** y desistimiento de cierre futuro.
- **Reapertura excepcional**: permisos de segunda aprobación, conciliación histórica y prevención de reinstalación de ventas ya anuladas.
- **Recontratación vinculada**: nuevo contrato con referencias al anterior, sin sobreescribirlo.
- **Políticas comerciales configurables** por plan y sede: topes de días, períodos mínimos, documentos, prórrogas y descuentos; se debe versionar el acuerdo.
- Integración Agenda/Control de acceso, avisos y app móvil.
- Pruebas end-to-end con PostgreSQL de desarrollo, dos workers y scheduler real.

La categoría del motivo no altera por sí misma la regla financiera. Las operaciones que modifican dinero, vigencia o acceso permanecen validadas por Laravel. No se modificó `main` ni Railway.
