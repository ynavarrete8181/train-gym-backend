# Fase 11 — Dashboard ejecutivo y KPIs

Fecha: 2026-10-10

## Objetivo

Consolidar en una sola vista ejecutiva los principales indicadores comerciales y operativos de Revive, reutilizando los reportes existentes como fuente de verdad y evitando duplicar lógica de negocio.

## Arquitectura

Backend:

- `app/Services/Dashboard/DashboardEjecutivoServicio.php`
- `app/Http/Controllers/Api/Dashboard/DashboardEjecutivoControlador.php`
- `routes/base/dashboard.php`

Frontend:

- `src/features/dashboard/pages/DashboardEjecutivoPage.jsx`
- `src/features/dashboard/services/dashboardEjecutivoServicio.js`

Permiso:

- `DASHBOARD-EJECUTIVO`

Menú:

- Dashboard
  - Resumen ejecutivo

Roles:

- SUPERADMINISTRADOR
- ADMINISTRADOR
- SUPERVISOR DE VENTAS

## Fuentes reutilizadas

El dashboard no replica consultas ya existentes. Orquesta:

- `ResumenComercialServicio`
- `CarteraVencidaServicio`
- `MembresiasNuevasRenovacionesServicio`
- `MembresiasPorVencerServicio`
- `ConciliacionCajaServicio`
- `VentasResponsableServicio`
- `ProductosServiciosVendidosServicio`

Los únicos KPIs operativos consultados directamente son:

- membresías activas;
- turnos de caja abiertos.

## Indicadores

- Ventas del período
- Total cobrado
- Transacciones
- Ticket promedio
- Variación de ventas frente al período anterior
- Variación de cobros frente al período anterior
- Membresías activas
- Membresías nuevas
- Renovaciones
- Membresías por vencer
- Cartera vencida
- Cuentas vencidas
- Turnos de caja abiertos
- Conciliaciones pendientes
- Diferencia acumulada de caja

## Bloques ejecutivos

- Ventas por sede
- Top responsables comerciales
- Productos y servicios más vendidos
- Membresías próximas a vencer
- Cartera crítica

## Alertas

El dashboard genera alertas operativas cuando existen:

- saldos vencidos;
- membresías que vencen dentro de 7 días;
- conciliaciones de caja pendientes;
- diferencias acumuladas de caja;
- turnos de caja abiertos.

## Seguridad

El alcance por sede se resuelve exclusivamente en backend mediante `AlcanceOperativoService`.

El frontend solo recibe y representa sedes autorizadas para el usuario.

## Filtros

- Desde
- Hasta
- Sede

El período por defecto es desde el primer día del mes actual hasta hoy.

## Auditoría

El dashboard es de consulta. Las operaciones GET no generan auditoría funcional adicional.

Las modificaciones originadas desde los módulos operativos continúan registrándose mediante la infraestructura global de auditoría de la Fase 10.
