<?php

namespace App\Services\Ventas;

use Illuminate\Support\Facades\DB;
use Throwable;

class SincronizarVentasMembresiasServicio
{
    public function __construct(private readonly VentaServicio $ventas)
    {
    }

    public function procesar(?int $membresiaId = null): array
    {
        $query = DB::table('membresias.membresias as m')
            ->join('membresias.planes as p', 'p.id', '=', 'm.plan_id')
            ->where('m.estado', 'PENDIENTE_PAGO')
            ->where('p.requiere_pago', true)
            ->where('p.generar_venta', true)
            ->whereRaw("UPPER(COALESCE(p.tipo_producto, 'MEMBRESIA')) <> 'PASE_DIARIO'")
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('ventas.ventas as v')
                    ->whereColumn('v.membresia_id', 'm.id')
                    ->where('v.estado', '!=', 'ANULADA');
            })
            ->select([
                'm.id',
                'm.deportista_id',
                'm.codigo_contrato',
                'm.sede_id',
                'm.precio_aplicado',
                'm.created_at',
                'p.id as plan_id',
                'p.nombre as plan_nombre',
                'p.tipo_producto',
            ])
            ->orderBy('m.id');

        if ($membresiaId) {
            $query->where('m.id', $membresiaId);
        }

        $pendientes = $query->get();
        $creadas = 0;
        $omitidas = 0;
        $errores = [];

        foreach ($pendientes as $membresia) {
            try {
                DB::transaction(function () use ($membresia, &$creadas, &$omitidas): void {
                    $yaExiste = DB::table('ventas.ventas')
                        ->where('membresia_id', $membresia->id)
                        ->where('estado', '!=', 'ANULADA')
                        ->lockForUpdate()
                        ->exists();

                    if ($yaExiste) {
                        $omitidas++;
                        return;
                    }

                    $usuarioGenerador = $this->usuarioOrigenMembresia((int) $membresia->id);

                    if (! $usuarioGenerador) {
                        $omitidas++;
                        return;
                    }

                    $venta = $this->ventas->guardarVenta([
                        'cliente_id' => (int) $membresia->deportista_id,
                        'membresia_id' => (int) $membresia->id,
                        'caja_id' => null,
                        'usuario_id' => $usuarioGenerador,
                        'origen_tipo' => 'MEMBRESIA_ASIGNACION',
                        'origen_id' => (int) $membresia->id,
                        'generado_por_tipo' => 'USUARIO',
                        'tipo_venta' => 'MEMBRESIA',
                        'concepto' => $membresia->plan_nombre . ' - ' . $membresia->codigo_contrato,
                        'fecha_venta' => $membresia->created_at ?? now(),
                        'subtotal' => (float) $membresia->precio_aplicado,
                        'descuento' => 0,
                        'impuesto' => 0,
                        'total' => (float) $membresia->precio_aplicado,
                        'estado' => 'PENDIENTE',
                        'observaciones' => 'Venta sincronizada desde membresía pendiente sin cuenta comercial asociada.',
                        'detalle' => [
                            'tipo_item' => 'MEMBRESIA',
                            'referencia_id' => (int) $membresia->plan_id,
                            'descripcion' => $membresia->plan_nombre,
                            'cantidad' => 1,
                            'precio_unitario' => (float) $membresia->precio_aplicado,
                            'total_linea' => (float) $membresia->precio_aplicado,
                        ],
                    ], null, $usuarioGenerador);

                    $periodoId = DB::table('membresias.membresia_periodos')
                        ->where('membresia_id', $membresia->id)
                        ->whereNull('venta_id')
                        ->orderByDesc('numero_periodo')
                        ->value('id');

                    if ($periodoId) {
                        DB::table('membresias.membresia_periodos')
                            ->where('id', $periodoId)
                            ->update([
                                'venta_id' => $venta->id,
                                'updated_at' => now(),
                            ]);
                    }

                    $creadas++;
                });
            } catch (Throwable $e) {
                $errores[] = [
                    'membresia_id' => (int) $membresia->id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'detectadas' => $pendientes->count(),
            'ventas_creadas' => $creadas,
            'omitidas' => $omitidas,
            'errores' => $errores,
        ];
    }

    private function usuarioOrigenMembresia(int $membresiaId): ?int
    {
        $registroId = (string) $membresiaId;

        $usuarioId = DB::table('auditoria.eventos')
            ->where('tabla', 'membresias.membresias')
            ->where('registro_id', $registroId)
            ->where('accion', 'CREAR')
            ->whereNotNull('usuario_id')
            ->orderBy('created_at')
            ->value('usuario_id');

        if ($usuarioId) {
            return (int) $usuarioId;
        }

        $usuarioId = DB::table('auditoria.eventos')
            ->where('tabla', 'membresias.membresias')
            ->where('registro_id', $registroId)
            ->whereNotNull('usuario_id')
            ->orderByDesc('created_at')
            ->value('usuario_id');

        return $usuarioId ? (int) $usuarioId : null;
    }
}
