# Membresías — cobro programado y venta manual

Fecha de actualización: 2026-10-01

## Objetivo

Separar claramente:

- contratación y vigencia de la membresía;
- programación del cobro;
- venta pendiente;
- pago;
- comprobante.

La membresía se configura desde Clientes / Membresías. La operación de cobro se ejecuta desde Ventas.

## Datos de cobro

`membresias.membresias` incorpora:

- `dia_pago`: día habitual del mes, entre 1 y 31;
- `generar_venta_automatica`: indica si el sistema debe crear la venta pendiente al llegar la fecha;
- `proxima_fecha_cobro`: fecha calculada por backend.

El día de pago aplica a planes renovables. Los planes no renovables, como un pase diario, se cobran de forma inmediata o manual.

## Cálculo de próxima fecha

El backend calcula la próxima fecha a partir de:

- fecha de inicio de la membresía;
- día habitual de pago.

Si el día elegido ya pasó dentro del mes de inicio, se utiliza el siguiente mes.

Para días 29, 30 o 31 en meses más cortos se utiliza el último día disponible del mes.

## Venta automática

El comando:

`php artisan revive:procesar-cobros-membresias`

procesa membresías cuya `proxima_fecha_cobro` ya llegó.

La tarea programada se ejecuta diariamente a las 00:05 mediante Laravel Scheduler.

Reglas:

1. No elimina ni modifica pagos históricos.
2. No duplica la venta del período actual.
3. La venta se crea en `PENDIENTE`.
4. La venta queda vinculada a la membresía.
5. El período actual queda vinculado mediante `membresia_periodos.venta_id`.
6. La venta automática no necesita turno de caja.
7. El turno de caja se aplica cuando la cajera cobra la cuenta.
8. Si el plan es renovable y corresponde iniciar un nuevo período, se crea el siguiente período conservando el historial.

## Venta manual

Si `generar_venta_automatica = false`:

1. La membresía existe y mantiene su vigencia.
2. No se crea una venta automáticamente.
3. En Ventas / POS la cajera selecciona al cliente.
4. El cliente puede buscarse por nombre, código, identificación o teléfono.
5. El POS muestra las membresías vigentes del cliente en la sede del turno.
6. Solo aparecen membresías cuyo período actual todavía no tenga una venta asociada.
7. La membresía se agrega al carrito en cantidad 1.
8. Al guardar o cobrar, la venta queda vinculada a la membresía y al período actual.

## Trazabilidad

No se debe crear una nueva membresía solo para cobrar un período.

La secuencia es:

```text
Membresía
  ↓
Período
  ↓
Venta
  ↓
Pago
  ↓
Comprobante
```

Cada renovación crea un nuevo período y conserva los anteriores.

## Producción

Laravel Scheduler debe estar activo en el servidor. Ejemplo de cron:

```text
* * * * * php /ruta/al/proyecto/artisan schedule:run
```

También puede utilizarse `php artisan schedule:work` bajo el administrador de procesos definido para producción.

## Prueba manual

Puede probarse una fecha concreta con:

```bash
php artisan revive:procesar-cobros-membresias --fecha=2026-10-15
```

Usar únicamente una fecha de prueba controlada, porque el comando puede crear ventas pendientes reales en la base conectada.
