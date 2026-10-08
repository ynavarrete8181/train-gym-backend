# Ventas · Fase 3 · Cierre de caja por método de pago

Fecha: 2026-10-08

## Objetivo

Cerrar cada turno de caja con trazabilidad real de los cobros confirmados realizados durante ese turno.

## Desglose del turno

El cierre conserva:

- efectivo cobrado
- transferencias
- tarjetas
- depósitos
- otros medios
- total cobrado
- cantidad de operaciones de cobro

Una operación mixta cuenta como un solo cobro aunque tenga varias filas en ventas.pagos.

## Regla de efectivo

El efectivo esperado se calcula exclusivamente como:

saldo inicial + cobros confirmados en EFECTIVO

Transferencias, tarjetas, depósitos y otros métodos forman parte del total comercial del turno, pero no del efectivo físico esperado en caja.

## Cierre manual

Al cerrar:

1. El backend obtiene el desglose directamente desde ventas.pagos.
2. Guarda un snapshot de los totales en ventas.turnos_caja.
3. Calcula efectivo esperado.
4. Compara contra efectivo contado.
5. Registra la diferencia.
6. Cierra y concilia el turno.

## Cierre automático

El cierre automático también congela el desglose por método de pago. El efectivo contado permanece pendiente y el turno queda con requiere_arqueo=true hasta su conciliación.

## Fuente de verdad

El frontend no calcula los cobros de caja. Los valores provienen del backend a partir de pagos CONFIRMADOS vinculados al turno.
