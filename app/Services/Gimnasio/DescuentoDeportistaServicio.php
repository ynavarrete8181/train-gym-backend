<?php

namespace App\Services\Gimnasio;

use Carbon\Carbon;
use App\Services\Concerns\RegistraAuditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DescuentoDeportistaServicio
{
    use RegistraAuditoria;

    public function resolver(int $deportistaId, int $planId, ?int $modalidadId, string $fecha, float $precioBase): array
    {
        $beneficios = DB::table('membresias.descuentos_deportistas')
            ->where('deportista_id', $deportistaId)
            ->where('activo', true)
            ->whereDate('fecha_inicio', '<=', $fecha)
            ->where(function ($q) use ($fecha) {
                $q->whereNull('fecha_fin')->orWhereDate('fecha_fin', '>=', $fecha);
            })
            ->where(function ($q) use ($planId) {
                $q->whereNull('plan_id')->orWhere('plan_id', $planId);
            })
            ->where(function ($q) use ($modalidadId) {
                $q->whereNull('modalidad_id');
                if ($modalidadId !== null) $q->orWhere('modalidad_id', $modalidadId);
            })
            ->orderByRaw('CASE WHEN modalidad_id IS NOT NULL THEN 0 WHEN plan_id IS NOT NULL THEN 1 ELSE 2 END')
            ->orderByDesc('fecha_inicio')
            ->limit(2)
            ->get();

        $beneficio = $beneficios->first();

        // Un descuento futuro no disminuye la venta actual; se informa para
        // explicar por qué el POS muestra $0 de descuento.
        $programado = null;
        if (! $beneficio) {
            $programado = DB::table('membresias.descuentos_deportistas')
                ->where('deportista_id', $deportistaId)
                ->where('activo', true)
                ->whereDate('fecha_inicio', '>', $fecha)
                ->where(function ($q) use ($planId) {
                    $q->whereNull('plan_id')->orWhere('plan_id', $planId);
                })
                ->where(function ($q) use ($modalidadId) {
                    $q->whereNull('modalidad_id');
                    if ($modalidadId !== null) $q->orWhere('modalidad_id', $modalidadId);
                })
                ->orderBy('fecha_inicio')
                ->first(['id', 'tipo', 'valor', 'fecha_inicio', 'fecha_fin']);
        }

        $importe = $beneficio
            ? self::calcularImporte($precioBase, $beneficio->tipo, (float) $beneficio->valor)
            : 0.0;

        return [
            'precio_base' => round($precioBase, 2),
            'descuento_id' => $beneficio?->id,
            'tipo' => $beneficio?->tipo,
            'valor' => $beneficio?->valor,
            'importe_descuento' => $importe,
            'total' => round($precioBase - $importe, 2),
            'fecha_evaluacion' => $fecha,
            'beneficio_estado' => $beneficio ? 'VIGENTE' : ($programado ? 'PROGRAMADO' : 'SIN_BENEFICIO'),
            'beneficio_programado' => $programado ? [
                'fecha_inicio' => $programado->fecha_inicio,
                'fecha_fin' => $programado->fecha_fin,
                'tipo' => $programado->tipo,
                'valor' => (float) $programado->valor,
            ] : null,
        ];
    }

    public static function calcularImporte(float $precioBase, string $tipo, float $valor): float
    {
        $base = max(0, $precioBase);
        if ($tipo === 'PORCENTAJE') {
            $descuento = round($base * min(100, max(0, $valor)) / 100, 2);
        } elseif ($tipo === 'FIJO') {
            $descuento = $valor;
        } else {
            throw new \InvalidArgumentException('Tipo de descuento no permitido.');
        }
        return round(min($base, max(0, $descuento)), 2);
    }

    public function crear(array $datos, int $usuarioId): object
    {
        $this->validarAlcance($datos);
        $datos['autorizado_por'] = $usuarioId;
        $datos['activo'] = true;
        $datos['created_at'] = now();
        $datos['updated_at'] = now();
        $id = DB::table('membresias.descuentos_deportistas')->insertGetId($datos);

        $registrado = DB::table('membresias.descuentos_deportistas')->where('id', $id)->first();
        $this->auditar('gimnasio', 'CREAR_DESCUENTO', 'membresias.descuentos_deportistas', $id, null, $registrado);
        return $registrado;
    }

    /**
     * Al renovar, el descuento vigente hasta el último día del período anterior
     * continúa en el período nuevo como un registro propio con sus mismas fechas.
     * Uno finalizado o desactivado (ya no llega a ese día) no continúa.
     */
    public function continuarEnPeriodo(int $periodoId, ?int $usuarioId = null): ?object
    {
        $tabla = 'membresias.descuentos_deportistas';
        $nuevo = DB::table('membresias.membresia_periodos')->where('id', $periodoId)->first();
        if (! $nuevo) return null;
        $existente = DB::table($tabla)->where('membresia_periodo_id', $periodoId)->where('activo', true)->first();
        if ($existente) return $existente;

        $anterior = DB::table('membresias.membresia_periodos')
            ->where('membresia_id', $nuevo->membresia_id)
            ->where('numero_periodo', '<', $nuevo->numero_periodo)
            ->orderByDesc('numero_periodo')->first();
        $m = DB::table('membresias.membresias')->where('id', $nuevo->membresia_id)->first();
        if (! $anterior || ! $m) return null;

        $finAnterior = substr((string) $anterior->fecha_fin, 0, 10);
        $inicio = substr((string) $nuevo->fecha_inicio, 0, 10);
        $fin = substr((string) $nuevo->fecha_fin, 0, 10);

        $d = DB::table($tabla)
            ->where('deportista_id', $m->deportista_id)
            ->where('activo', true)
            ->whereDate('fecha_inicio', '<=', $finAnterior)
            ->where(fn ($q) => $q->whereNull('fecha_fin')->orWhereDate('fecha_fin', '>=', $finAnterior))
            ->where(fn ($q) => $q->whereNull('plan_id')->orWhere('plan_id', $m->plan_id))
            ->where(function ($q) use ($m) {
                $q->whereNull('modalidad_id');
                if ($m->modalidad_id) $q->orWhere('modalidad_id', $m->modalidad_id);
            })
            ->orderByRaw('CASE WHEN modalidad_id IS NOT NULL THEN 0 WHEN plan_id IS NOT NULL THEN 1 ELSE 2 END')
            ->orderByDesc('fecha_inicio')
            ->first();
        if (! $d) return null;

        // Ya cubre el período nuevo con una fecha fin explícita: no hace falta copiarlo.
        if ($d->fecha_fin && substr((string) $d->fecha_fin, 0, 10) >= $fin) return $d;

        // Un descuento "sin fin" o que llega más allá del período anterior se cierra en él,
        // para que cada período tenga su propio registro.
        if (! $d->fecha_fin || substr((string) $d->fecha_fin, 0, 10) > $finAnterior) {
            DB::table($tabla)->where('id', $d->id)->update(['fecha_fin' => $finAnterior, 'updated_at' => now()]);
        }
        if (! $d->membresia_periodo_id) {
            DB::table($tabla)->where('id', $d->id)->update(['membresia_periodo_id' => $anterior->id]);
        }

        $id = DB::table($tabla)->insertGetId([
            'deportista_id' => $d->deportista_id,
            'plan_id' => $d->plan_id,
            'modalidad_id' => $d->modalidad_id,
            'tipo' => $d->tipo,
            'valor' => $d->valor,
            'fecha_inicio' => $inicio,
            'fecha_fin' => $fin,
            'motivo' => $d->motivo,
            'activo' => true,
            'autorizado_por' => $usuarioId ?? $d->autorizado_por,
            'descuento_origen_id' => $d->id,
            'membresia_periodo_id' => $periodoId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $continuado = DB::table($tabla)->where('id', $id)->first();

        DB::table('membresias.membresia_movimientos')->insert([
            'membresia_id' => $m->id, 'tipo' => 'DESCUENTO_RENOVADO', 'estado' => 'CONFIRMADO',
            'fecha_efectiva' => now()->toDateString(),
            'motivo' => 'El descuento continúa en el período ' . $nuevo->numero_periodo . ' por la renovación.',
            'usuario_id' => $usuarioId, 'autorizado_por' => $usuarioId,
            'detalle' => json_encode([
                'tipo' => $continuado->tipo, 'valor' => (float) $continuado->valor,
                'fecha_inicio' => $inicio, 'fecha_fin' => $fin, 'activo' => true,
                'periodo' => (int) $nuevo->numero_periodo, 'descuento_origen_id' => (int) $d->id,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->auditar('gimnasio', 'RENOVAR_DESCUENTO', $tabla, $id, $d, $continuado);

        return $continuado;
    }

    public function actualizar(int $id, array $datos, int $usuarioId): object
    {
        $anterior = DB::table('membresias.descuentos_deportistas')->where('id', $id)->first();
        if (! $anterior) abort(404, 'Beneficio no encontrado.');
        $this->validarAlcance(array_merge((array) $anterior, $datos));
        $datos['autorizado_por'] = $usuarioId;
        $datos['updated_at'] = now();
        DB::table('membresias.descuentos_deportistas')->where('id', $id)->update($datos);
        $actualizado = DB::table('membresias.descuentos_deportistas')->where('id', $id)->first();
        $this->auditar('gimnasio', 'ACTUALIZAR_DESCUENTO', 'membresias.descuentos_deportistas', $id, $anterior, $actualizado);
        return $actualizado;
    }

    private function validarAlcance(array $datos): void
    {
        $planId = $datos['plan_id'] ?? null;
        $modalidadId = $datos['modalidad_id'] ?? null;
        if ($modalidadId && ! $planId) {
            throw ValidationException::withMessages(['plan_id' => 'Selecciona el plan de la modalidad.']);
        }
        if ($modalidadId && ! DB::table('membresias.plan_modalidades')->where('id', $modalidadId)->where('plan_id', $planId)->exists()) {
            throw ValidationException::withMessages(['modalidad_id' => 'La modalidad no pertenece al plan seleccionado.']);
        }
        if (isset($datos['fecha_fin']) && $datos['fecha_fin'] !== null && Carbon::parse($datos['fecha_fin'])->lt(Carbon::parse($datos['fecha_inicio']))) {
            throw ValidationException::withMessages(['fecha_fin' => 'La fecha final no puede ser anterior al inicio.']);
        }
        if (($datos['tipo'] ?? '') === 'PORCENTAJE' && (float) ($datos['valor'] ?? 0) > 100) {
            throw ValidationException::withMessages(['valor' => 'Un porcentaje no puede superar el 100 %.']);
        }

        // Los beneficios del mismo alcance no pueden tener períodos superpuestos.
        // Entre alcances diferentes prevalece modalidad > plan > general.
        if (($datos['activo'] ?? true) === false) return;
        $consulta = DB::table('membresias.descuentos_deportistas')
            ->where('deportista_id', (int) $datos['deportista_id'])
            ->where('activo', true)
            ->whereDate('fecha_inicio', '<=', $datos['fecha_fin'] ?? '9999-12-31')
            ->where(function ($q) use ($datos) {
                $q->whereNull('fecha_fin')->orWhereDate('fecha_fin', '>=', $datos['fecha_inicio']);
            });
        if (empty($planId)) $consulta->whereNull('plan_id');
        else $consulta->where('plan_id', (int) $planId);
        if (empty($modalidadId)) $consulta->whereNull('modalidad_id');
        else $consulta->where('modalidad_id', (int) $modalidadId);
        if (! empty($datos['id'])) $consulta->where('id', '<>', (int) $datos['id']);
        if ($consulta->exists()) {
            throw ValidationException::withMessages(['fecha_inicio' => 'Ya existe un descuento vigente o programado con fechas superpuestas para el mismo alcance.']);
        }
    }
}
