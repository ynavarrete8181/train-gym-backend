# Ventas — POS y facturación operativa

Fecha: 2026-09-16

## Objetivo

Convertir la captura plana de ventas en un punto de venta integrado con el turno de caja, la sede, los clientes y los catálogos comerciales de Revive, manteniendo el estilo y los componentes del Sistema Base.

## Flujo

```text
Turno abierto
→ Nueva venta
→ Cliente / consumidor final
→ Selección de ítems
→ Detalle de venta
→ Guardar pendiente o Guardar y cobrar
→ Pago
→ Comprobante
→ Cierre del turno
```

## Contexto automático

La pantalla no permite elegir libremente sede o caja. Ambas provienen del turno abierto del usuario autenticado.

El encabezado muestra:

- número de venta: se genera al guardar;
- fecha/hora;
- sede del turno;
- caja y código;
- identificador de turno;
- estado inicial `Pendiente de pago`.

## Cliente

El campo visible se denomina `Cliente`. Permite localizar deportistas/clientes activos por nombre o código y admite consumidor final cuando la operación lo permita.

## Tipos de ítem

El POS presenta:

- Servicio;
- Producto;
- Membresía;
- Pase diario;
- Otro.

### Servicios

Se muestran únicamente servicios activos que tienen horario activo en la sede del turno.

Los precios se almacenan en `gimnasio.servicio_precios_sede`. Un servicio sin precio para la sede puede visualizarse, pero no se permite agregarlo al detalle hasta configurar su tarifa.

No se inventan precios automáticamente.

### Productos

La primera versión usa el catálogo activo y su precio de venta existente. El stock por sede requerirá el bloque posterior de inventario multi-sede; hasta entonces no debe afirmarse que el stock mostrado representa disponibilidad física por sede.

### Membresías y pases diarios

Se muestran como referencia comercial con el precio aplicable a la sede, pero su contratación sigue naciendo desde `Membresías`, ya que ese flujo debe crear contrato, vigencia, sede y estado comercial antes de generar/cobrar la venta.

### Otro

Permite capturar un concepto extraordinario con descripción, precio unitario y cantidad.

## Detalle de venta

El POS permite varias líneas. Cada línea registra:

- tipo;
- descripción;
- cantidad;
- precio unitario;
- total de línea;
- producto relacionado cuando corresponde.

El backend calcula nuevamente subtotal, descuento, impuesto y total antes de guardar.

## Pago

`Guardar pendiente` registra la venta en estado inicial pendiente.

`Guardar y cobrar` registra primero la venta y luego un pago confirmado usando el turno abierto. La caja y `turno_caja_id` nunca dependen de una selección manual del cajero.

## Comprobante y factura

La aplicación mantiene separados los conceptos de venta, pago y comprobante. El número de venta se genera automáticamente y el comprobante se consolida con el cobro.

Esta fase no declara integración tributaria electrónica con SRI. Cuando se implemente facturación electrónica, el documento fiscal deberá generarse desde el comprobante con las reglas tributarias correspondientes, sin confundirlo con el identificador interno de venta.

## Diseño Sistema Base

La vista reutiliza:

- `PageHeader`;
- `GestionToolbar`;
- `TablaGestion`;
- `FilterHeaderCell`;
- `NotificacionSnackbar`;
- estilos `dbanuStyles`;
- Material UI.

No existen variantes de la página por rol. Los permisos y el alcance operativo se resuelven en backend.
