# Ventas — cajas permanentes y turnos operativos

Fecha: 2026-09-16

## Objetivo

Separar la configuración permanente de puntos de cobro de la operación diaria o por turno del personal de caja, manteniendo trazabilidad por usuario, sede y caja.

## Navegación

```text
Ventas
├── Cajas
├── Turnos de caja
├── Ventas
├── Pagos
└── Comprobantes
```

## Caja permanente

`ventas.cajas` representa un punto físico o lógico de cobro. No se crea cada día.

Ejemplo:

```text
Código: CAJA-XPADEL-01
Nombre: Caja principal Xpadel
Sede: Revive Xpadel
Activa: Sí
```

Responsables de configuración:

- SUPERADMINISTRADOR
- ADMINISTRADOR
- SUPERVISOR DE VENTAS

El rol CAJERO no administra cajas permanentes.

## Turno de caja

`ventas.turnos_caja` representa una sesión operativa de un usuario sobre una caja.

Un turno contiene:

- caja;
- usuario/cajero;
- sede;
- fecha y hora de apertura;
- saldo inicial;
- fecha y hora de cierre;
- efectivo esperado;
- efectivo contado;
- diferencia;
- observaciones de apertura/cierre;
- usuario que realizó el cierre cuando corresponde.

Reglas principales:

1. Solo se puede abrir una caja activa y dentro del alcance operativo del usuario.
2. No puede existir más de un turno abierto simultáneo para la misma caja.
3. Un usuario no puede mantener dos turnos abiertos al mismo tiempo.
4. El cajero puede cerrar su propio turno.
5. SUPERADMINISTRADOR, ADMINISTRADOR y SUPERVISOR DE VENTAS pueden cerrar un turno ajeno de forma excepcional.
6. Los pagos operativos requieren un turno abierto.
7. La caja y `turno_caja_id` del pago se determinan automáticamente desde el turno abierto del usuario.
8. Si una venta manual se registra durante un turno abierto, queda vinculada al turno y a su caja.
9. Las ventas pendientes generadas automáticamente por otros procesos, por ejemplo Membresías, pueden existir sin turno; el cobro posterior sí requiere turno.

## Cierre y arqueo

Para el cierre se calcula:

```text
efectivo cobrado en el turno
+ saldo inicial
= efectivo esperado

 efectivo contado
- efectivo esperado
= diferencia
```

Solo los pagos `CONFIRMADO` con método `EFECTIVO` asociados al `turno_caja_id` forman parte del efectivo esperado. Los demás métodos permanecen registrados y auditables, pero no incrementan el efectivo físico esperado.

## Alcance por sede

Toda operación respeta `AlcanceOperativoService` y la capacidad `maneja_caja` de la sede.

Ejemplo:

```text
Karol — CAJERO
Asignación: Revive Xpadel → Toda la sede

Puede abrir:
- cajas activas de Revive Xpadel

No puede operar:
- cajas de Revive Home
- cajas de Revive Centro
```

## Permisos

### CAJERO

- VENTAS-TURNOS-CAJA
- VENTAS-VENTAS
- VENTAS-PAGOS
- VENTAS-COMPROBANTES

No recibe `VENTAS-CAJAS` porque no configura puntos de cobro permanentes.

### SUPERVISOR DE VENTAS / ADMINISTRADOR / SUPERADMINISTRADOR

Pueden disponer además de `VENTAS-CAJAS` para la administración de puntos de cobro, según su matriz de permisos y alcance por sede.

## Prueba funcional recomendada

1. Un supervisor/administrador crea `Caja principal Xpadel` en Revive Xpadel.
2. Karol inicia sesión como CAJERO.
3. Karol abre un turno con saldo inicial.
4. Se genera o selecciona una venta pendiente de Revive Xpadel.
5. Karol registra el pago.
6. El pago queda vinculado al turno y caja abiertos.
7. Se verifica comprobante y actualización de estado de la venta/membresía.
8. Karol cierra el turno ingresando efectivo contado.
9. Se verifica efectivo esperado y diferencia.
