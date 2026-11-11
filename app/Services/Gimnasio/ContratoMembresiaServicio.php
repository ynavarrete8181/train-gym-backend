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
            ->orderByDesc('d.fecha_inicio')->orderByDesc('d.id')
            ->get(['d.*', 'u.name as autorizado_nombre'])
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
            $d->por_sede = $tarifas->map(function ($t) use ($d) {
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
            $descuento = $this->descuentos->crear([
                'deportista_id' => (int) $m->deportista_id,
                'plan_id' => (int) $m->plan_id,
                'modalidad_id' => $m->modalidad_id ? (int) $m->modalidad_id : null,
                'tipo' => $datos['tipo'], 'valor' => $datos['valor'],
                'fecha_inicio' => $datos['fecha_inicio'], 'fecha_fin' => $datos['fecha_fin'] ?? null,
                'motivo' => trim($datos['motivo']),
            ], $usuarioId);
            $this->movimiento($id, 'DESCUENTO_REGISTRADO', $datos['motivo'], $usuarioId, $this->detalle($descuento));
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
            $descuento = $this->descuentos->actualizar($descuentoId, [
                'id' => $descuentoId, 'tipo' => $datos['tipo'], 'valor' => $datos['valor'],
                'fecha_inicio' => $datos['fecha_inicio'], 'fecha_fin' => $datos['fecha_fin'] ?? null,
                'motivo' => trim($datos['motivo']),
            ], $usuarioId);
            $this->movimiento($id, 'DESCUENTO_EDITADO', $datos['motivo'], $usuarioId,
                $this->detalle($descuento) + ['anterior' => $this->detalle($anterior)]);
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
            return $final;
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
