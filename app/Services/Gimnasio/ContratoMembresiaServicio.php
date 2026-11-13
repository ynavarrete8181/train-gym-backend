<?php

namespace App\Services\Gimnasio;

use App\Services\Concerns\RegistraAuditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sedes y descuentos de una membresía ya registrada. Cada cambio queda en el historial
 * del contrato (membresia_movimientos) con su motivo y su autor.
 */
class ContratoMembresiaServicio
{
    use RegistraAuditoria;

    public function __construct(private readonly DescuentoDeportistaServicio $descuentos)
    {
    }

    public function actualizarSedes(int $id, array $sedes, string $motivo, int $usuarioId): array
    {
        return DB::transaction(function () use ($id, $sedes, $motivo, $usuarioId): array {
            $m = $this->membresia($id, true);
            if (strtoupper((string) $m->estado) === 'CANCELADA') {
                throw ValidationException::withMessages(['sedes_habilitadas' => 'La membresía está cancelada: reábrela antes de cambiar sus sedes.']);
            }
            $nuevas = collect($sedes)->map(fn ($s) => (int) $s)->filter()->unique()->sort()->values();
            if ($nuevas->isEmpty()) {
                throw ValidationException::withMessages(['sedes_habilitadas' => 'Habilita al menos una sede.']);
            }
            $anteriores = DB::table('membresias.membresia_sedes')->where('membresia_id', $id)->where('activo', true)
                ->pluck('sede_id')->map(fn ($s) => (int) $s)->sort()->values();
            if ($anteriores->all() === $nuevas->all()) {
                throw ValidationException::withMessages(['sedes_habilitadas' => 'Las sedes no tienen cambios.']);
            }
            $quitadas = $anteriores->diff($nuevas);
            $nombres = fn ($ids) => DB::table('institucional.sedes')->whereIn('id_sede', collect($ids)->all())
                ->orderBy('nombre')->pluck('nombre')->all();

            // No se quita una sede donde el cliente tiene entrenador asignado.
            $conEntrenador = DB::table('entrenamiento.asignaciones_entrenador_cliente')
                ->where('membresia_id', $id)->where('estado', 'ACTIVO')
                ->whereIn('sede_id', $quitadas->all())->pluck('sede_id');
            if ($conEntrenador->isNotEmpty()) {
                throw ValidationException::withMessages(['sedes_habilitadas' => 'Tiene entrenador asignado en '
                    .implode(', ', $nombres($conEntrenador)).': finaliza esa asignación antes de quitar la sede.']);
            }

            $principal = (int) $m->sede_id;
            if ($quitadas->contains($principal)) {
                if ($m->tipo_producto !== 'PASE_DIARIO') {
                    throw ValidationException::withMessages(['sedes_habilitadas' => 'No se puede quitar la sede principal del contrato ('
                        .implode(', ', $nombres([$principal])).').']);
                }
                // El pase da acceso en la sede de su venta: no se quita mientras ese día siga vigente.
                $ultimo = DB::table('membresias.membresia_periodos as mp')
                    ->leftJoin('ventas.ventas as v', 'v.id', '=', 'mp.venta_id')
                    ->where('mp.membresia_id', $id)->orderByDesc('mp.numero_periodo')
                    ->first(['mp.fecha_fin', 'mp.venta_id', 'v.estado']);
                if ($ultimo && $ultimo->venta_id && $ultimo->estado !== 'ANULADA'
                    && substr((string) $ultimo->fecha_fin, 0, 10) >= now()->toDateString()) {
                    throw ValidationException::withMessages(['sedes_habilitadas' => 'El pase tiene una venta vigente en '
                        .implode(', ', $nombres([$principal])).': no se puede quitar esa sede hasta que pase ese día.']);
                }
                $principal = (int) $nuevas->first();
                DB::table('membresias.membresias')->where('id', $id)->update(['sede_id' => $principal, 'updated_at' => now()]);
            }

            DB::table('membresias.membresia_sedes')->where('membresia_id', $id)->whereNotIn('sede_id', $nuevas->all())
                ->update(['activo' => false, 'es_principal' => false, 'updated_at' => now()]);
            foreach ($nuevas as $sedeId) {
                DB::table('membresias.membresia_sedes')->updateOrInsert(
                    ['membresia_id' => $id, 'sede_id' => $sedeId],
                    ['activo' => true, 'es_principal' => $sedeId === $principal, 'updated_at' => now(), 'created_at' => now()],
                );
            }

            $this->movimiento($id, 'CAMBIAR_SEDES', $motivo, $usuarioId, [
                'sedes_anteriores' => implode(', ', $nombres($anteriores)),
                'sedes_nuevas' => implode(', ', $nombres($nuevas)),
            ]);
            $this->auditar('membresias', 'CAMBIAR_SEDES', 'membresias.membresias', $id, $anteriores->all(), $nuevas->all());

            return ['sedes_habilitadas' => $nuevas->all(), 'sede_id' => $principal];
        });
    }

    /** Días de gracia y renovación automática de una membresía regular. */
    public function actualizarConfiguracion(int $id, array $datos, string $motivo, int $usuarioId): object
    {
        return DB::transaction(function () use ($id, $datos, $motivo, $usuarioId): object {
            $m = $this->membresia($id, true);
            if ($m->tipo_producto === 'PASE_DIARIO') {
                throw ValidationException::withMessages(['membresia_id' => 'El Pase Diario no tiene esta configuración.']);
            }
            $cambios = array_intersect_key($datos, array_flip(['dias_gracia', 'renovacion_automatica']));
            $anterior = ['dias_gracia' => (int) $m->dias_gracia, 'renovacion_automatica' => (bool) $m->renovacion_automatica];
            $nuevo = array_merge($anterior, array_map(fn ($v) => is_bool($v) ? $v : (int) $v, $cambios));
            if ($nuevo === $anterior) {
                throw ValidationException::withMessages(['configuracion' => 'La configuración no tiene cambios.']);
            }
            $actualizada = app(MembresiaServicio::class)->actualizar($id, $cambios);
            $this->movimiento($id, 'CONFIGURAR_CONTRATO', $motivo, $usuarioId, [
                'dias_gracia_anterior' => $anterior['dias_gracia'], 'dias_gracia_nuevo' => $nuevo['dias_gracia'],
                'renovacion_automatica_anterior' => $anterior['renovacion_automatica'], 'renovacion_automatica_nueva' => $nuevo['renovacion_automatica'],
            ]);
            return $actualizada;
        });
    }

    /** Descuentos del cliente que aplican a este plan, con su estado frente a la fecha de hoy. */
    public function descuentos(int $id): array
    {
        $m = $this->membresia($id);
        $hoy = now()->toDateString();
        $filas = $this->consultaDescuentos($m)
            ->leftJoin('seguridad.users as u', 'u.id', '=', 'd.autorizado_por')
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', 'd.sede_id')
            ->orderByDesc('d.fecha_inicio')->orderByDesc('d.id')
            ->get(['d.*', 'u.name as autorizado_nombre', 's.nombre as sede_nombre'])
            ->map(function ($d) use ($hoy) {
                $inicio = substr((string) $d->fecha_inicio, 0, 10);
                $fin = $d->fecha_fin ? substr((string) $d->fecha_fin, 0, 10) : null;
                $d->estado = match (true) {
                    ! $d->activo => 'FINALIZADO',
                    $inicio > $hoy => 'PROGRAMADO',
                    $fin !== null && $fin < $hoy => 'VENCIDO',
                    default => 'VIGENTE',
                };
                return $d;
            });

        // Tarifa de cada sede: el mismo descuento deja un precio distinto según la sede.
        $tarifas = $this->tarifasPorSede($m);
        $aplicaciones = $this->aplicacionesDescuentos($filas->pluck('id')->all());
        $filas = $filas->map(function ($d) use ($tarifas, $aplicaciones) {
            // Un descuento de sede solo cambia lo que se paga en esa sede.
            $d->por_sede = $tarifas->filter(fn ($t) => empty($d->sede_id) || (int) $t->sede_id === (int) $d->sede_id)->map(function ($t) use ($d) {
                $importe = DescuentoDeportistaServicio::calcularImporte($t->precio, (string) $d->tipo, (float) $d->valor);
                return ['sede_id' => $t->sede_id, 'sede_nombre' => $t->sede_nombre, 'precio' => $t->precio,
                    'descuento' => $importe, 'paga' => round($t->precio - $importe, 2)];
            })->values();
            $d->aplicaciones = $aplicaciones->get($d->id, collect())->values();
            return $d;
        });

        return ['contrato' => $this->rangoContrato($m), 'descuentos' => $filas, 'tarifas' => $tarifas->values()];
    }

    /** Sedes con tarifa activa para el plan o su modalidad; sin ninguna, todas las sedes con el precio base. */
    private function tarifasPorSede(object $m)
    {
        $tabla = $m->modalidad_id ? 'membresias.plan_modalidad_precios_sede' : 'membresias.plan_precios_sede';
        $columna = $m->modalidad_id ? 'modalidad_id' : 'plan_id';
        $sedesConTarifa = DB::table($tabla)->where($columna, $m->modalidad_id ?: $m->plan_id)
            ->where('activo', true)->pluck('sede_id')->all();
        $precios = app(MembresiaServicio::class);

        return DB::table('institucional.sedes')->where('activo', true)
            ->when($sedesConTarifa, fn ($q) => $q->whereIn('id_sede', $sedesConTarifa))
            ->orderBy('nombre')->get(['id_sede as sede_id', 'nombre as sede_nombre'])
            ->map(function ($s) use ($m, $precios) {
                $s->precio = round($precios->resolverPrecio((int) $m->plan_id, (int) $s->sede_id, $m->modalidad_id), 2);
                return $s;
            });
    }

    /** Períodos cobrados con cada descuento: dónde, cuándo y cuánto pagó el cliente. */
    private function aplicacionesDescuentos(array $ids)
    {
        if (! $ids) {
            return collect();
        }

        return DB::table('membresias.membresia_periodos as mp')
            ->join('membresias.membresias as m', 'm.id', '=', 'mp.membresia_id')
            ->leftJoin('ventas.ventas as v', 'v.id', '=', 'mp.venta_id')
            ->leftJoin('ventas.turnos_caja as tc', 'tc.id', '=', 'v.turno_caja_id')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', DB::raw('COALESCE(tc.sede_id, c.sede_id, m.sede_id)'))
            ->whereIn('mp.descuento_id', $ids)
            ->orderByDesc('mp.fecha_inicio')->orderByDesc('mp.id')
            ->get([
                'mp.descuento_id', 'mp.numero_periodo', 'mp.fecha_inicio', 'mp.fecha_fin', 'mp.precio',
                'mp.descuento_importe', 'mp.estado as periodo_estado', 'v.numero as venta_numero', 'v.estado as venta_estado',
                's.nombre as sede_nombre',
            ])
            ->map(function ($a) {
                $a->precio = (float) $a->precio;
                $a->descuento_importe = (float) $a->descuento_importe;
                $a->paga = round($a->precio - $a->descuento_importe, 2);
                return $a;
            })
            ->groupBy('descuento_id');
    }

    public function registrarDescuento(int $id, array $datos, int $usuarioId): object
    {
        return DB::transaction(function () use ($id, $datos, $usuarioId): object {
            $m = $this->membresia($id, true);
            $this->validarRango($m, $datos['fecha_inicio'], $datos['fecha_fin'] ?? null);
            $sedeId = $this->validarSedeDescuento($m, $datos['sede_id'] ?? null);
            $descuento = $this->descuentos->crear([
                'sede_id' => $sedeId,
                'deportista_id' => (int) $m->deportista_id,
                'plan_id' => (int) $m->plan_id,
                'modalidad_id' => $m->modalidad_id ? (int) $m->modalidad_id : null,
                'tipo' => $datos['tipo'], 'valor' => $datos['valor'],
                'fecha_inicio' => $datos['fecha_inicio'], 'fecha_fin' => $datos['fecha_fin'] ?? null,
                'motivo' => trim($datos['motivo']),
            ], $usuarioId);
            $this->movimiento($id, 'DESCUENTO_REGISTRADO', $datos['motivo'], $usuarioId, $this->detalle($descuento));
            $descuento->ventas_recalculadas = $this->recalcularVentasPendientes($id, $usuarioId, $datos['motivo']);
            return $descuento;
        });
    }

    public function actualizarDescuento(int $id, int $descuentoId, array $datos, int $usuarioId): object
    {
        return DB::transaction(function () use ($id, $descuentoId, $datos, $usuarioId): object {
            $m = $this->membresia($id, true);
            $anterior = $this->descuentoDeMembresia($m, $descuentoId);
            if (! $anterior->activo || ($anterior->fecha_fin && substr((string) $anterior->fecha_fin, 0, 10) < now()->toDateString())) {
                throw ValidationException::withMessages(['descuento' => 'El descuento ya terminó: registra uno nuevo.']);
            }
            $this->validarRango($m, $datos['fecha_inicio'], $datos['fecha_fin'] ?? null);
            $sedeId = $this->validarSedeDescuento($m, $datos['sede_id'] ?? null);
            $hoy = now()->toDateString();
            $inicioAnterior = substr((string) $anterior->fecha_inicio, 0, 10);
            $finAnterior = $anterior->fecha_fin ? substr((string) $anterior->fecha_fin, 0, 10) : null;
            $desde = substr((string) $datos['fecha_inicio'], 0, 10);

            // Un descuento que ya empezó no se reescribe: se cierra el día anterior al cambio
            // y el cambio queda como un registro nuevo. Así el historial conserva lo que rigió.
            if ($inicioAnterior <= $hoy) {
                if ($desde <= $inicioAnterior) {
                    throw ValidationException::withMessages(['fecha_inicio' => 'Este descuento ya empezó: indica desde qué fecha rige el cambio (posterior al '
                        . implode('/', array_reverse(explode('-', $inicioAnterior))) . ').']);
                }
                if ($finAnterior && $desde > $finAnterior) {
                    throw ValidationException::withMessages(['fecha_inicio' => 'El cambio debe empezar dentro de las fechas del descuento. Para después, registra uno nuevo.']);
                }
                $cierre = \Carbon\Carbon::parse($desde)->subDay()->toDateString();
                DB::table('membresias.descuentos_deportistas')->where('id', $descuentoId)
                    ->update(['fecha_fin' => $cierre, 'updated_at' => now()]);
                // Las continuaciones ya creadas con los valores anteriores dejan de valer.
                DB::table('membresias.descuentos_deportistas')->where('descuento_origen_id', $descuentoId)
                    ->where('activo', true)->whereDate('fecha_inicio', '>', $cierre)
                    ->update(['activo' => false, 'updated_at' => now()]);
                $descuento = $this->descuentos->crear([
                    'deportista_id' => (int) $anterior->deportista_id,
                    'plan_id' => $anterior->plan_id, 'modalidad_id' => $anterior->modalidad_id,
                    'sede_id' => $sedeId,
                    'tipo' => $datos['tipo'], 'valor' => $datos['valor'],
                    'fecha_inicio' => $desde, 'fecha_fin' => $datos['fecha_fin'] ?? null,
                    'motivo' => trim($datos['motivo']),
                    'descuento_origen_id' => $descuentoId,
                    'membresia_periodo_id' => $anterior->membresia_periodo_id ?? null,
                ], $usuarioId);
                $cerrado = DB::table('membresias.descuentos_deportistas')->where('id', $descuentoId)->first();
                $this->movimiento($id, 'DESCUENTO_EDITADO', $datos['motivo'], $usuarioId,
                    $this->detalle($descuento) + ['anterior' => $this->detalle($cerrado), 'nuevo_registro' => true]);
                $descuento->ventas_recalculadas = $this->recalcularVentasPendientes($id, $usuarioId, $datos['motivo']);
                return $descuento;
            }

            // Aún no empieza: se corrige en el mismo registro.
            $descuento = $this->descuentos->actualizar($descuentoId, [
                'sede_id' => $sedeId,
                'id' => $descuentoId, 'tipo' => $datos['tipo'], 'valor' => $datos['valor'],
                'fecha_inicio' => $datos['fecha_inicio'], 'fecha_fin' => $datos['fecha_fin'] ?? null,
                'motivo' => trim($datos['motivo']),
            ], $usuarioId);
            $this->movimiento($id, 'DESCUENTO_EDITADO', $datos['motivo'], $usuarioId,
                $this->detalle($descuento) + ['anterior' => $this->detalle($anterior)]);
            $descuento->ventas_recalculadas = $this->recalcularVentasPendientes($id, $usuarioId, $datos['motivo']);
            return $descuento;
        });
    }

    /** Termina el descuento desde hoy: las ventas de hoy en adelante ya no lo aplican. */
    public function finalizarDescuento(int $id, int $descuentoId, string $motivo, int $usuarioId): object
    {
        return DB::transaction(function () use ($id, $descuentoId, $motivo, $usuarioId): object {
            $m = $this->membresia($id, true);
            $d = $this->descuentoDeMembresia($m, $descuentoId);
            $hoy = now()->toDateString();
            if (! $d->activo || ($d->fecha_fin && substr((string) $d->fecha_fin, 0, 10) < $hoy)) {
                throw ValidationException::withMessages(['descuento' => 'El descuento ya terminó.']);
            }
            $inicio = substr((string) $d->fecha_inicio, 0, 10);
            $ayer = now()->subDay()->toDateString();
            $cambios = $inicio > $ayer
                ? ['activo' => false]
                : ['fecha_fin' => $ayer];
            DB::table('membresias.descuentos_deportistas')->where('id', $descuentoId)
                ->update($cambios + ['updated_at' => now()]);
            // Las continuaciones ya creadas para períodos futuros también terminan.
            $futuros = DB::table('membresias.descuentos_deportistas')
                ->where('descuento_origen_id', $descuentoId)->where('activo', true)
                ->whereDate('fecha_inicio', '>', $hoy)->pluck('id');
            while ($futuros->isNotEmpty()) {
                DB::table('membresias.descuentos_deportistas')->whereIn('id', $futuros)
                    ->update(['activo' => false, 'updated_at' => now()]);
                $futuros = DB::table('membresias.descuentos_deportistas')
                    ->whereIn('descuento_origen_id', $futuros)->where('activo', true)->pluck('id');
            }
            $final = DB::table('membresias.descuentos_deportistas')->where('id', $descuentoId)->first();
            $this->movimiento($id, 'DESCUENTO_FINALIZADO', $motivo, $usuarioId, $this->detalle($final));
            $this->auditar('gimnasio', 'FINALIZAR_DESCUENTO', 'membresias.descuentos_deportistas', $descuentoId, $d, $final);
            $final->ventas_recalculadas = $this->recalcularVentasPendientes($id, $usuarioId, $motivo);
            return $final;
        });
    }

    /**
     * Alinea las ventas pendientes del contrato con sus descuentos. Cada período usa el descuento
     * vigente en su fecha de inicio (sede del contrato). Solo se tocan ventas PENDIENTES sin cobros:
     * venta, comprobante, período, cuota y cartera. Lo ya cobrado no se reescribe; se informa.
     *
     * @return array{actualizadas: array<int, array>, cobradas: array<int, array>}
     */
    public function recalcularVentasPendientes(int $id, ?int $usuarioId, string $motivo): array
    {
        $m = $this->membresia($id, true);
        $resultado = ['actualizadas' => [], 'cobradas' => []];
        $periodos = DB::table('membresias.membresia_periodos')->where('membresia_id', $id)
            ->whereNotNull('venta_id')->orderBy('numero_periodo')->lockForUpdate()->get();
        foreach ($periodos as $p) {
            $venta = DB::table('ventas.ventas')->where('id', $p->venta_id)->lockForUpdate()->first();
            if (! $venta || $venta->estado === 'ANULADA') {
                continue;
            }
            $subtotal = round((float) $venta->subtotal, 2);
            $beneficio = $this->descuentos->resolver(
                (int) $m->deportista_id, (int) $m->plan_id, $m->modalidad_id ? (int) $m->modalidad_id : null,
                substr((string) $p->fecha_inicio, 0, 10), $subtotal, $m->sede_id ? (int) $m->sede_id : null
            );
            $importe = round((float) $beneficio['importe_descuento'], 2);
            if (abs($importe - (float) $venta->descuento) < 0.005) {
                continue;
            }
            $fila = ['periodo' => (int) $p->numero_periodo, 'venta' => $venta->numero,
                'descuento_antes' => (float) $venta->descuento, 'descuento_despues' => $importe,
                'total_antes' => (float) $venta->total, 'total_despues' => round($subtotal - $importe, 2)];
            $pagado = (float) DB::table('ventas.pagos')->where('venta_id', $venta->id)->where('estado', 'CONFIRMADO')->sum('monto');
            if ($pagado > 0 || $venta->estado !== 'PENDIENTE') {
                $resultado['cobradas'][] = $fila;
                continue;
            }
            $total = $fila['total_despues'];
            DB::table('ventas.ventas')->where('id', $venta->id)->update(['descuento' => $importe, 'total' => $total, 'updated_at' => now()]);
            DB::table('ventas.comprobantes')->where('venta_id', $venta->id)->update(['total' => $total, 'updated_at' => now()]);
            DB::table('membresias.membresia_periodos')->where('id', $p->id)
                ->update(['descuento_id' => $beneficio['descuento_id'], 'descuento_importe' => $importe, 'updated_at' => now()]);
            DB::table('membresias.membresia_cuotas')->where('venta_id', $venta->id)->update(['monto' => $total, 'updated_at' => now()]);
            app(\App\Services\CuentasCobrar\CarteraServicio::class)->sincronizarVenta((int) $venta->id);
            $resultado['actualizadas'][] = $fila;
        }
        if ($resultado['actualizadas']) {
            $texto = collect($resultado['actualizadas'])->map(fn ($f) => "período {$f['periodo']} ({$f['venta']}): $"
                . number_format($f['total_antes'], 2) . ' → $' . number_format($f['total_despues'], 2))->implode('; ');
            $this->movimiento($id, 'VENTAS_RECALCULADAS', 'Ventas pendientes ajustadas al descuento vigente de cada período: ' . $texto . '. ' . trim($motivo),
                $usuarioId, $resultado);
        }
        return $resultado;
    }

    /**
     * Cambia el precio pactado de un contrato desde un período. Solo administradores y con
     * motivo. Los períodos sin cobrar (y sus cuotas y ventas pendientes) pasan al precio nuevo
     * con su descuento recalculado; un período ya cobrado solo se alinea si su venta ya está a
     * ese precio. Queda en el historial del contrato y en auditoría.
     */
    public function cambiarPrecio(int $id, array $datos, int $usuarioId): array
    {
        return DB::transaction(function () use ($id, $datos, $usuarioId): array {
            if (! app(\App\Services\Seguridad\AlcanceOperativoService::class)->puedeGestionarCartera($usuarioId)) {
                throw ValidationException::withMessages(['precio' => 'Solo un administrador puede cambiar el precio del contrato.']);
            }
            $m = $this->membresia($id, true);
            if ($m->tipo_producto === 'PASE_DIARIO') {
                throw ValidationException::withMessages(['precio' => 'El pase diario se cobra con la tarifa del día; no tiene precio pactado.']);
            }
            if (strtoupper((string) $m->estado) === 'CANCELADA') {
                throw ValidationException::withMessages(['precio' => 'La membresía está cancelada.']);
            }
            $precio = round((float) $datos['precio'], 2);
            $desde = (int) $datos['desde_periodo'];
            $periodos = DB::table('membresias.membresia_periodos')->where('membresia_id', $id)
                ->where('numero_periodo', '>=', $desde)->orderBy('numero_periodo')->lockForUpdate()->get();
            if ($periodos->isEmpty()) {
                throw ValidationException::withMessages(['desde_periodo' => 'No hay períodos desde ese número.']);
            }

            $cambios = [];
            foreach ($periodos as $p) {
                $venta = $p->venta_id ? DB::table('ventas.ventas')->where('id', $p->venta_id)->lockForUpdate()->first() : null;
                $vigente = $venta && $venta->estado !== 'ANULADA';
                $pagado = $vigente ? (float) DB::table('ventas.pagos')->where('venta_id', $venta->id)->where('estado', 'CONFIRMADO')->sum('monto') : 0.0;
                $descuento = $p->descuento_id ? DB::table('membresias.descuentos_deportistas')->where('id', $p->descuento_id)->first() : null;
                $importe = $descuento ? DescuentoDeportistaServicio::calcularImporte($precio, (string) $descuento->tipo, (float) $descuento->valor) : 0.0;
                $total = round($precio - $importe, 2);

                if ($vigente && ($pagado > 0 || in_array($venta->estado, ['PAGADA', 'PARCIAL'], true))) {
                    // Ya cobrado: no se reescribe lo cobrado; solo se alinea si ya se cobró a este precio.
                    if (abs((float) $venta->subtotal - $precio) > 0.009) {
                        throw ValidationException::withMessages(['desde_periodo' => "El período {$p->numero_periodo} ya está cobrado a $"
                            . number_format((float) $venta->subtotal, 2) . '. El cambio debe empezar en un período sin cobrar.']);
                    }
                } elseif ($vigente) {
                    $cantidad = (float) (DB::table('ventas.venta_detalles')->where('venta_id', $venta->id)->where('tipo_item', 'MEMBRESIA')->value('cantidad') ?: 1);
                    DB::table('ventas.venta_detalles')->where('venta_id', $venta->id)->where('tipo_item', 'MEMBRESIA')
                        ->update(['precio_unitario' => $precio, 'total_linea' => round($precio * $cantidad, 2), 'updated_at' => now()]);
                    DB::table('ventas.ventas')->where('id', $venta->id)
                        ->update(['subtotal' => $precio, 'descuento' => $importe, 'total' => $total, 'updated_at' => now()]);
                    DB::table('ventas.comprobantes')->where('venta_id', $venta->id)
                        ->update(['subtotal' => $precio, 'total' => $total, 'updated_at' => now()]);
                    app(\App\Services\CuentasCobrar\CarteraServicio::class)->sincronizarVenta((int) $venta->id);
                }

                DB::table('membresias.membresia_periodos')->where('id', $p->id)
                    ->update(['precio' => $precio, 'descuento_importe' => $importe, 'updated_at' => now()]);
                DB::table('membresias.membresia_cuotas')->where('periodo_id', $p->id)->update(['monto' => $precio, 'updated_at' => now()]);
                $cambios[] = ['periodo' => (int) $p->numero_periodo, 'antes' => (float) $p->precio, 'despues' => $precio];
            }

            $anterior = (float) $m->precio_aplicado;
            DB::table('membresias.membresias')->where('id', $id)->update(['precio_aplicado' => $precio, 'updated_at' => now()]);
            $detalle = ['precio_anterior' => $anterior, 'precio_nuevo' => $precio, 'desde_periodo' => $desde, 'periodos' => $cambios];
            $this->movimiento($id, 'CAMBIO_PRECIO', $datos['motivo'], $usuarioId, $detalle);
            $this->auditar('gimnasio', 'CAMBIAR_PRECIO_CONTRATO', 'membresias.membresias', $id, $m,
                DB::table('membresias.membresias')->where('id', $id)->first(), 'Precio pactado $' . number_format($anterior, 2) . ' → $' . number_format($precio, 2));

            return $detalle;
        });
    }

    /**
     * Precio del próximo período al renovar, según el modo del contrato:
     * CONTRATO mantiene el pactado, TARIFA usa la tarifa vigente de la sede principal y
     * FIJO usa el precio programado. Sin modo, conserva el comportamiento anterior ($porDefecto).
     *
     * @return array{0: float, 1: string}
     */
    public function precioRenovacion(object $m, float $porDefecto, ?float $precioAnterior, string $origenPorDefecto = 'precio del contrato'): array
    {
        return match (strtoupper((string) ($m->precio_renovacion_modo ?? ''))) {
            'TARIFA' => [round(app(MembresiaServicio::class)->resolverPrecio((int) $m->plan_id, $m->sede_id, $m->modalidad_id), 2), 'tarifa vigente de la sede'],
            'FIJO' => (float) ($m->precio_renovacion ?? 0) > 0 ? [round((float) $m->precio_renovacion, 2), 'precio programado'] : [$porDefecto, 'precio del contrato'],
            'CONTRATO' => [round($precioAnterior ?: $porDefecto, 2), 'precio del contrato'],
            default => [round($porDefecto, 2), $origenPorDefecto],
        };
    }

    /** Deja en el historial que una renovación nació con un precio distinto al período anterior. */
    public function registrarPrecioRenovado(int $id, int $periodo, float $antes, float $despues, string $origen): void
    {
        if (abs($antes - $despues) < 0.009 || $antes <= 0) return;
        $this->movimiento($id, 'CAMBIO_PRECIO', sprintf('Renovación del período %d con %s: $%s → $%s.', $periodo, $origen,
            number_format($antes, 2), number_format($despues, 2)), auth()->id(), [
            'precio_anterior' => $antes, 'precio_nuevo' => $despues, 'desde_periodo' => $periodo, 'origen' => $origen,
        ]);
    }

    /**
     * Cambio masivo de tarifa: los contratos indicados renuevan con la tarifa vigente.
     * Un contrato con precio específico (precio negociado) se respeta.
     */
    public function aplicarTarifaEnRenovacion(array $ids, string $motivo, int $usuarioId): int
    {
        if (! app(\App\Services\Seguridad\AlcanceOperativoService::class)->puedeGestionarCartera($usuarioId)) {
            throw ValidationException::withMessages(['aplicar_vigentes' => 'Solo un administrador puede aplicar la tarifa a los contratos vigentes.']);
        }
        $aplicados = 0;
        foreach (DB::table('membresias.membresias')->whereIn('id', $ids)->get() as $m) {
            if (strtoupper((string) $m->precio_renovacion_modo) === 'FIJO') continue;
            DB::table('membresias.membresias')->where('id', $m->id)
                ->update(['precio_renovacion_modo' => 'TARIFA', 'precio_renovacion' => null, 'updated_at' => now()]);
            $this->movimiento((int) $m->id, 'PRECIO_RENOVACION', $motivo, $usuarioId,
                ['modo' => 'TARIFA', 'modo_anterior' => $m->precio_renovacion_modo, 'origen' => 'CAMBIO_TARIFA']);
            $aplicados++;
        }
        return $aplicados;
    }

    /** Configura cómo toma el precio la próxima renovación (solo administradores, con motivo). */
    public function configurarPrecioRenovacion(int $id, array $datos, int $usuarioId): object
    {
        return DB::transaction(function () use ($id, $datos, $usuarioId): object {
            if (! app(\App\Services\Seguridad\AlcanceOperativoService::class)->puedeGestionarCartera($usuarioId)) {
                throw ValidationException::withMessages(['modo' => 'Solo un administrador puede configurar el precio de renovación.']);
            }
            $m = $this->membresia($id, true);
            if ($m->tipo_producto === 'PASE_DIARIO' || strtoupper((string) $m->estado) === 'CANCELADA') {
                throw ValidationException::withMessages(['modo' => 'Esta membresía no se renueva.']);
            }
            $modo = strtoupper((string) $datos['modo']);
            $precio = $modo === 'FIJO' ? round((float) ($datos['precio'] ?? 0), 2) : null;
            if ($modo === 'FIJO' && $precio <= 0) {
                throw ValidationException::withMessages(['precio' => 'Indica el precio para la próxima renovación.']);
            }
            DB::table('membresias.membresias')->where('id', $id)
                ->update(['precio_renovacion_modo' => $modo, 'precio_renovacion' => $precio, 'updated_at' => now()]);
            $detalle = ['modo' => $modo, 'precio' => $precio, 'modo_anterior' => $m->precio_renovacion_modo, 'precio_anterior' => $m->precio_renovacion];
            $this->movimiento($id, 'PRECIO_RENOVACION', $datos['motivo'], $usuarioId, $detalle);
            $this->auditar('gimnasio', 'CONFIGURAR_PRECIO_RENOVACION', 'membresias.membresias', $id, $m,
                DB::table('membresias.membresias')->where('id', $id)->first(), 'Precio de renovación: ' . $modo . ($precio ? ' $' . number_format($precio, 2) : ''));
            return DB::table('membresias.membresias')->where('id', $id)->first();
        });
    }

    /** Un contrato acortado recorta los descuentos que quedaban fuera de su nuevo fin. */
    public function ajustarDescuentosAlContrato(int $id, ?int $usuarioId): void
    {
        $m = $this->membresia($id);
        $fin = $this->rangoContrato($m)['fin'];
        if (! $fin) return;
        $afectados = $this->consultaDescuentos($m)->where('d.activo', true)
            ->where(fn ($q) => $q->whereNull('d.fecha_fin')->orWhereDate('d.fecha_fin', '>', $fin))
            ->get(['d.*']);
        foreach ($afectados as $d) {
            $cambios = substr((string) $d->fecha_inicio, 0, 10) > $fin ? ['activo' => false] : ['fecha_fin' => $fin];
            DB::table('membresias.descuentos_deportistas')->where('id', $d->id)->update($cambios + ['updated_at' => now()]);
            $nuevo = DB::table('membresias.descuentos_deportistas')->where('id', $d->id)->first();
            $this->movimiento($id, 'DESCUENTO_AJUSTADO', 'El fin del contrato cambió y el descuento se ajustó a sus nuevas fechas.',
                $usuarioId, $this->detalle($nuevo) + ['anterior' => $this->detalle($d)]);
        }
    }

    /** @return array{inicio: ?string, fin: ?string} */
    public function rangoContrato(object $m): array
    {
        if ($m->tipo_producto === 'PASE_DIARIO') {
            return [
                'inicio' => substr((string) ($m->vigencia_inicio ?? $m->created_at), 0, 10),
                'fin' => $m->vigencia_fin ? substr((string) $m->vigencia_fin, 0, 10) : null,
            ];
        }
        return [
            'inicio' => substr((string) $m->fecha_inicio, 0, 10),
            'fin' => $m->fecha_fin ? substr((string) $m->fecha_fin, 0, 10) : null,
        ];
    }

    /** Un descuento de sede solo puede ser de una sede autorizada de la membresía. */
    private function validarSedeDescuento(object $m, mixed $sedeId): ?int
    {
        if (empty($sedeId)) return null;
        $autorizada = DB::table('membresias.membresia_sedes')->where('membresia_id', $m->id)
            ->where('sede_id', (int) $sedeId)->where('activo', true)->exists();
        if (! $autorizada) {
            throw ValidationException::withMessages(['sede_id' => 'La sede del descuento debe estar entre las sedes autorizadas.']);
        }
        return (int) $sedeId;
    }

    private function validarRango(object $m, string $inicio, ?string $fin): void
    {
        $rango = $this->rangoContrato($m);
        $legible = fn (string $f) => implode('/', array_reverse(explode('-', $f)));
        $inicio = substr($inicio, 0, 10);
        $fin = $fin ? substr($fin, 0, 10) : null;
        if (strtoupper((string) $m->estado) === 'CANCELADA') {
            throw ValidationException::withMessages(['descuento' => 'La membresía está cancelada.']);
        }
        if ($inicio < $rango['inicio']) {
            throw ValidationException::withMessages(['fecha_inicio' => 'El descuento no puede empezar antes del contrato ('.$legible($rango['inicio']).').']);
        }
        if ($rango['fin'] && (! $fin || $fin > $rango['fin'])) {
            throw ValidationException::withMessages(['fecha_fin' => 'El descuento debe terminar a más tardar el '.$legible($rango['fin']).', fin del contrato.']);
        }
        if ($fin && $fin < $inicio) {
            throw ValidationException::withMessages(['fecha_fin' => 'La fecha fin no puede ser anterior a la fecha inicio.']);
        }
    }

    private function consultaDescuentos(object $m)
    {
        return DB::table('membresias.descuentos_deportistas as d')
            ->where('d.deportista_id', $m->deportista_id)
            ->where(fn ($q) => $q->whereNull('d.plan_id')->orWhere('d.plan_id', $m->plan_id))
            ->where(function ($q) use ($m) {
                $q->whereNull('d.modalidad_id');
                if ($m->modalidad_id) $q->orWhere('d.modalidad_id', $m->modalidad_id);
            });
    }

    private function descuentoDeMembresia(object $m, int $descuentoId): object
    {
        $d = $this->consultaDescuentos($m)->where('d.id', $descuentoId)->first(['d.*']);
        if (! $d) {
            throw ValidationException::withMessages(['descuento' => 'El descuento no corresponde a esta membresía.']);
        }
        return $d;
    }

    private function membresia(int $id, bool $bloquear = false): object
    {
        $consulta = DB::table('membresias.membresias as m')->join('membresias.planes as p', 'p.id', '=', 'm.plan_id')
            ->where('m.id', $id);
        $m = ($bloquear ? $consulta->lockForUpdate() : $consulta)->first(['m.*', 'p.tipo_producto']);
        if (! $m) {
            throw ValidationException::withMessages(['membresia_id' => 'Membresía no encontrada.']);
        }
        return $m;
    }

    private function detalle(object $d): array
    {
        return [
            'tipo' => $d->tipo, 'valor' => (float) $d->valor,
            'fecha_inicio' => substr((string) $d->fecha_inicio, 0, 10),
            'fecha_fin' => $d->fecha_fin ? substr((string) $d->fecha_fin, 0, 10) : null,
            'activo' => (bool) $d->activo,
            'sede_id' => $d->sede_id ?? null,
        ];
    }

    private function movimiento(int $id, string $tipo, string $motivo, ?int $usuarioId, array $detalle): void
    {
        DB::table('membresias.membresia_movimientos')->insert([
            'membresia_id' => $id, 'tipo' => $tipo, 'estado' => 'CONFIRMADO',
            'fecha_efectiva' => now()->toDateString(), 'motivo' => trim($motivo),
            'usuario_id' => $usuarioId, 'autorizado_por' => $usuarioId,
            'detalle' => json_encode($detalle, JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
