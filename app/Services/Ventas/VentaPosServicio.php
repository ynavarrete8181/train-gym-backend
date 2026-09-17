<?php

namespace App\Services\Ventas;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class VentaPosServicio
{
    public function __construct(
        private readonly TurnoCajaServicio $turnos,
        private readonly VentaServicio $ventas,
    ) {
    }

    public function contexto(int $usuarioId): array
    {
        $turno = $this->turnos->turnoAbiertoUsuario($usuarioId);
        if (! $turno) {
            return $this->contextoVacio();
        }

        $sedeId = (int) $turno->sede_id;

        $clientes = DB::table('gimnasio.deportistas as d')
            ->join('seguridad.users as u', 'u.id', '=', 'd.usuario_id')
            ->where('d.estado', 'ACTIVO')
            ->orderBy('u.name')
            ->get([
                'd.id',
                'd.codigo_deportista as codigo',
                'd.telefono',
                'd.sede_principal_id',
                'u.name as nombre',
                'u.email',
            ]);

        $serviciosQuery = DB::table('gimnasio.servicios as s')
            ->join('gimnasio.horarios_servicio as h', function ($join) use ($sedeId): void {
                $join->on('h.servicio_id', '=', 's.id')
                    ->where('h.sede_id', '=', $sedeId)
                    ->where('h.activo', '=', true);
            })
            ->leftJoin('gimnasio.categorias_servicio as c', 'c.id', '=', 's.categoria_id')
            ->where('s.activo', true);

        $columnasServicios = [
            's.id',
            's.nombre',
            's.descripcion',
            's.duracion_minutos',
            's.requiere_reserva',
            'c.nombre as categoria',
        ];

        if (Schema::connection('pgsql')->hasTable('gimnasio.servicio_precios_sede')) {
            $serviciosQuery->leftJoin('gimnasio.servicio_precios_sede as ps', function ($join) use ($sedeId): void {
                $join->on('ps.servicio_id', '=', 's.id')
                    ->where('ps.sede_id', '=', $sedeId)
                    ->where('ps.activo', '=', true);
            });
            $columnasServicios[] = 'ps.precio';
        } else {
            $columnasServicios[] = DB::raw('NULL::numeric as precio');
        }

        $servicios = $serviciosQuery
            ->select($columnasServicios)
            ->distinct()
            ->orderBy('s.nombre')
            ->get();

        $planes = $this->planesPorSede($sedeId);

        $productos = Schema::connection('pgsql')->hasTable('inventario.productos')
            ? DB::table('inventario.productos')
                ->where('activo', true)
                ->orderBy('nombre')
                ->get([
                    'id',
                    'codigo',
                    'nombre',
                    'descripcion',
                    'precio_venta as precio',
                    'stock_actual',
                    'controla_stock',
                ])
            : collect();

        return [
            'turno' => $turno,
            'clientes' => $clientes,
            'servicios' => $servicios,
            'planes' => $planes,
            'productos' => $productos,
        ];
    }

    public function guardar(array $datos, int $usuarioId): object
    {
        $turno = $this->turnos->turnoAbiertoUsuario($usuarioId);
        if (! $turno) {
            throw ValidationException::withMessages([
                'turno_caja_id' => 'Debes abrir un turno de caja antes de registrar una venta desde el POS.',
            ]);
        }

        $detalles = collect($datos['detalles'] ?? [])->values();
        if ($detalles->isEmpty()) {
            throw ValidationException::withMessages(['detalles' => 'Agrega al menos un ítem a la venta.']);
        }

        $subtotal = round((float) $detalles->sum(fn ($item) => (float) ($item['total_linea'] ?? 0)), 2);
        $descuento = round((float) ($datos['descuento'] ?? 0), 2);
        $impuesto = round((float) ($datos['impuesto'] ?? 0), 2);
        $total = round($subtotal - $descuento + $impuesto, 2);
        if ($total <= 0) {
            throw ValidationException::withMessages(['total' => 'El total de la venta debe ser mayor que cero.']);
        }

        return DB::transaction(function () use ($datos, $detalles, $subtotal, $descuento, $impuesto, $total, $usuarioId, $turno): object {
            $primero = $detalles->first();
            $venta = $this->ventas->guardarVenta([
                'cliente_id' => $datos['cliente_id'] ?? null,
                'caja_id' => (int) $turno->caja_id,
                'turno_caja_id' => (int) $turno->id,
                'tipo_venta' => $this->tipoVentaGeneral($detalles->all()),
                'concepto' => $this->conceptoGeneral($detalles->all()),
                'subtotal' => $subtotal,
                'descuento' => $descuento,
                'impuesto' => $impuesto,
                'total' => $total,
                'estado' => 'PENDIENTE',
                'observaciones' => $datos['observaciones'] ?? null,
                'detalle' => [
                    'producto_id' => $primero['producto_id'] ?? null,
                    'descripcion' => $primero['descripcion'],
                    'cantidad' => $primero['cantidad'],
                    'precio_unitario' => $primero['precio_unitario'],
                    'total_linea' => $primero['total_linea'],
                ],
            ], null, $usuarioId);

            DB::table('ventas.venta_detalles')->where('venta_id', $venta->id)->delete();
            $ahora = now();
            DB::table('ventas.venta_detalles')->insert($detalles->map(fn ($item) => [
                'venta_id' => $venta->id,
                'producto_id' => $item['producto_id'] ?? null,
                'descripcion' => $item['descripcion'],
                'cantidad' => round((float) $item['cantidad'], 2),
                'precio_unitario' => round((float) $item['precio_unitario'], 2),
                'total_linea' => round((float) $item['total_linea'], 2),
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ])->all());

            $venta->detalles = DB::table('ventas.venta_detalles')
                ->where('venta_id', $venta->id)
                ->orderBy('id')
                ->get();

            return $venta;
        });
    }

    private function planesPorSede(int $sedeId)
    {
        if (! Schema::connection('pgsql')->hasTable('gimnasio.planes')) {
            return collect();
        }

        $query = DB::table('gimnasio.planes as p')->where('p.activo', true);
        $precio = 'p.precio_base as precio';

        if (Schema::connection('pgsql')->hasTable('gimnasio.plan_precios_sede')) {
            $query->leftJoin('gimnasio.plan_precios_sede as pp', function ($join) use ($sedeId): void {
                $join->on('pp.plan_id', '=', 'p.id')
                    ->where('pp.sede_id', '=', $sedeId)
                    ->where('pp.activo', '=', true);
            });
            $precio = DB::raw('COALESCE(pp.precio, p.precio_base) as precio');
        }

        $columnas = [
            'p.id',
            'p.codigo',
            'p.nombre',
            'p.descripcion',
            'p.tipo_duracion',
            'p.duracion',
            $precio,
            'p.tarifa_inscripcion',
        ];

        $columnas[] = Schema::connection('pgsql')->hasColumn('gimnasio.planes', 'tipo_producto')
            ? 'p.tipo_producto'
            : DB::raw("'MEMBRESIA'::varchar as tipo_producto");

        $columnas[] = Schema::connection('pgsql')->hasColumn('gimnasio.planes', 'tipo_cobro')
            ? 'p.tipo_cobro'
            : DB::raw("'PAGO_UNICO'::varchar as tipo_cobro");

        return $query
            ->select($columnas)
            ->orderBy('p.nombre')
            ->get();
    }

    private function contextoVacio(): array
    {
        return [
            'turno' => null,
            'clientes' => [],
            'servicios' => [],
            'planes' => [],
            'productos' => [],
        ];
    }

    private function tipoVentaGeneral(array $detalles): string
    {
        $tipos = collect($detalles)->pluck('tipo')->filter()->unique();
        return $tipos->count() === 1 && in_array($tipos->first(), ['PRODUCTO', 'MEMBRESIA', 'SERVICIO', 'OTRO'], true)
            ? $tipos->first()
            : 'OTRO';
    }

    private function conceptoGeneral(array $detalles): string
    {
        if (count($detalles) === 1) {
            return (string) $detalles[0]['descripcion'];
        }

        return 'Venta POS - ' . count($detalles) . ' ítems';
    }
}
