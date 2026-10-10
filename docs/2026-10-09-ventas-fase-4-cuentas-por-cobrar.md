# Ventas · Fase 4 · Cuentas por cobrar / Cartera

Fecha: 2026-10-09

## Objetivo

Separar la gestión de cartera del núcleo transaccional de ventas, manteniendo a ventas.ventas y ventas.pagos como única fuente financiera.

## Esquema modular

### cuentas_cobrar.cuentas
Encabezado de gestión por venta.

Guarda:
- venta_id
- fecha_vencimiento
- estado de ciclo de vida (ABIERTA, PAGADA, ANULADA)
- prioridad
- responsable de cartera
- última y próxima gestión
- cierre
- observaciones

No guarda un saldo maestro. El saldo se deriva siempre desde la venta y los pagos confirmados.

### cuentas_cobrar.gestiones
Histórico de seguimiento:
- llamada
- WhatsApp
- correo
- presencial
- nota
- resultado
- detalle
- usuario
- fecha
- próxima gestión

### cuentas_cobrar.compromisos_pago
Promesas de pago:
- monto
- fecha compromiso
- estado
- observaciones
- usuario que registra
- pago asociado cuando corresponda

## Fuente financiera

Saldo pendiente:

ventas.ventas.total
- SUM(ventas.pagos.monto WHERE estado = CONFIRMADO)
= saldo pendiente

El módulo de cartera no duplica ni reemplaza esa información.

## Vencimiento

Para ventas asociadas a membresía:

fecha_inicio del período + dias_gracia de la membresía

Para ventas no contractuales:

fecha de la venta

La fecha puede ajustarse desde Cartera cuando exista una decisión administrativa documentada.

## Estado visible

El estado mostrado se calcula dinámicamente:

- PENDIENTE: sin pagos y dentro de fecha
- PARCIAL: existe pago confirmado pero aún hay saldo
- VENCIDA: hay saldo y fecha de vencimiento anterior a hoy
- PAGADA: saldo igual a cero
- ANULADA: venta/cuenta anulada

## Sincronización automática

Al crear o modificar una venta pendiente/parcial se crea o reactiva su encabezado de cartera.

Después de cada cobro:
- si queda saldo, la cuenta continúa abierta;
- si llega a cero, la cuenta pasa a PAGADA;
- la cuenta no se elimina, queda histórica.

## Responsabilidades

Responsable comercial de la venta y responsable de cartera son conceptos distintos.

La cuenta nace sin responsable de cartera. Administrador o Supervisor asigna posteriormente al responsable de seguimiento.

## Seguridad

VENTAS-CARTERA está disponible para:
- SUPERADMINISTRADOR
- ADMINISTRADOR
- SUPERVISOR DE VENTAS

El alcance de datos sigue limitado por las sedes permitidas del usuario.

El cajero no administra cartera; cobra cuentas desde el POS cuando tiene turno propio abierto.

## Interfaz

Ventas > Cartera muestra:
- cuentas abiertas
- saldo total por cobrar
- cuentas vencidas
- saldo vencido
- compromisos pendientes
- cliente / identificación
- venta y concepto
- sede
- vencimiento
- total, pagado y saldo
- responsable
- estado

Desde Gestionar se puede:
- ajustar vencimiento
- asignar prioridad
- asignar responsable
- registrar observaciones
- registrar gestión
- registrar compromiso

Desde Cobrar se reutiliza VentaPosFormulario y las reglas existentes de turno de caja.


## Corrección incremental de menú y permisos

Se agregó la migración:

`database/migrations/2026_10_09_001100_ensure_cartera_menu_permissions.php`

Objetivo:
- garantizar que `VENTAS-CARTERA` exista dentro del menú **Ventas** aunque la migración inicial de Fase 4 ya se hubiera ejecutado;
- registrar `CarteraPage` en `seguridad.cpu_pagina_sistema`;
- asignar el submenú únicamente a:
  - SUPERADMINISTRADOR
  - ADMINISTRADOR
  - SUPERVISOR DE VENTAS
- sincronizar `seguridad.cpu_userrolefunction` y `seguridad.cpu_userfunction` para usuarios existentes;
- retirar `VENTAS-CARTERA` de roles no autorizados.

El backend continúa protegiendo todas las rutas de cartera con:

`base.auth`
`base.permiso:VENTAS-CARTERA`

Por tanto, la visibilidad del submenú y la autorización real quedan alineadas.
