<?php

namespace App\Services\Gimnasio;

use Carbon\Carbon;
use App\Services\Concerns\RegistraAuditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DescuentoDeportistaServicio
{
    use RegistraAuditoria;

    public function resolver(int $deportistaId, int $planId, ?int $modalidadId, string $fecha, float $precioBase, ?int $sedeId = null): array
    {
        // Sin sede conocida solo valen los descuentos generales; con sede, el de esa sede tiene prioridad.
        $filtroSede = function ($q) use ($sedeId) {
            $q->whereNull('sede_id');
            if ($sedeId !== null) $q->orWhere('sede_id', $sedeId);
        };
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
            ->where($filtroSede)
            ->orderByRaw('CASE WHEN sede_id IS NOT NULL THEN 0 ELSE 1 END')
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
                ->where($filtroSede)
                ->orderBy('fecha_inicio')
                ->first(['id', 'tipo', 'valor', 'fecha_inicio', 'fecha_fin']);
        }

        $importe = $beneficio
            ? self::calcularImporte($precioBase, $beneficio->tipo, (float) $beneficio->valor)
            : 0.0;

        return [
            'precio_base' => round($precioBase, 2),
            'descuento_id' => $beneficio?->id,
            'sede_id' => $beneficio?->sede_id,
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
     * Al renovar, cada descuento vigente hasta el último día del período anterior
     * (uno general y, si hay, uno por sede) continúa en el período nuevo como un
     * registro propio con sus mismas fechas. Uno finalizado o desactivado no continúa.
     * Devuelve el general, o el primero si solo hay de sede.
     */
    public function continuarEnPeriodo(int $periodoId, ?int $usuarioId = null): ?object
    {
        $continuados = $this->continuarTodosEnPeriodo($periodoId, $usuarioId);
        return collect($continuados)->first(fn ($d) => empty($d->sede_id)) ?? ($continuados[0] ?? null);
    }

    /** @return array<int, object> */
    public function continuarTodosEnPeriodo(int $periodoId, ?int $usuarioId = null): array
    {
        $tabla = 'membresias.descuentos_deportistas';
        static $conSede = null;
        $conSede ??= Schema::hasColumn($tabla, 'sede_id');

        $nuevo = DB::table('membresias.membresia_periodos')->where('id', $periodoId)->first();
        if (! $nuevo) return [];
        $anterior = DB::table('membresias.membresia_periodos')
            ->where('membresia_id', $nuevo->membresia_id)
            ->where('numero_periodo', '<', $nuevo->numero_periodo)
            ->orderByDesc('numero_periodo')->first();
        $m = DB::table('membresias.membresias')->where('id', $nuevo->membresia_id)->first();
        if (! $anterior || ! $m) return [];

        $finAnterior = substr((string) $anterior->fecha_fin, 0, 10);
        $inicio = substr((string) $nuevo->fecha_inicio, 0, 10);
        $fin = substr((string) $nuevo->fecha_fin, 0, 10);

        $candidatos = DB::table($tabla)
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
            ->get();
        // Uno por sede (el general va con clave vacía), el de mayor prioridad.
        $porSede = $candidatos->groupBy(fn ($d) => (string) ($d->sede_id ?? ''))->map->first();

        $resultado = [];
        foreach ($porSede as $d) {
            $existente = DB::table($tabla)->where('membresia_periodo_id', $periodoId)->where('activo', true)
                ->when($conSede, fn ($q) => empty($d->sede_id) ? $q->whereNull('sede_id') : $q->where('sede_id', $d->sede_id))
                ->first();
            if ($existente) { $resultado[] = $existente; continue; }

            // Ya cubre el período nuevo con una fecha fin explícita: no hace falta copiarlo.
            if ($d->fecha_fin && substr((string) $d->fecha_fin, 0, 10) >= $fin) { $resultado[] = $d; continue; }

            // Un descuento "sin fin" o que llega más allá del período anterior se cierra en él,
            // para que cada período tenga su propio registro.
            if (! $d->fecha_fin || substr((string) $d->fecha_fin, 0, 10) > $finAnterior) {
                DB::table($tabla)->where('id', $d->id)->update(['fecha_fin' => $finAnterior, 'updated_at' => now()]);
            }
            if (! $d->membresia_periodo_id) {
                DB::table($tabla)->where('id', $d->id)->update(['membresia_periodo_id' => $anterior->id]);
            }

            $fila = [
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
            ];
            if ($conSede) $fila['sede_id'] = $d->sede_id ?? null;
            $id = DB::table($tabla)->insertGetId($fila);
            $continuado = DB::table($tabla)->where('id', $id)->first();

            DB::table('membresias.membresia_movimientos')->insert([
                'membresia_id' => $m->id, 'tipo' => 'DESCUENTO_RENOVADO', 'estado' => 'CONFIRMADO',
                'fecha_efectiva' => now()->toDateString(),
                'motivo' => 'El descuento continúa en el período ' . $nuevo->numero_periodo . ' por la renovación.',
                'usuario_id' => $usuarioId, 'autorizado_por' => $usuarioId,
                'detalle' => json_encode([
                    'tipo' => $continuado->tipo, 'valor' => (float) $continuado->valor,
                    'fecha_inicio' => $inicio, 'fecha_fin' => $fin, 'activo' => true,
                    'sede_id' => $d->sede_id ?? null,
                    'periodo' => (int) $nuevo->numero_periodo, 'descuento_origen_id' => (int) $d->id,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->auditar('gimnasio', 'RENOVAR_DESCUENTO', $tabla, $id, $d, $continuado);
            $resultado[] = $continuado;
        }

        return $resultado;
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
        if (empty($datos['sede_id'])) $consulta->whereNull('sede_id');
        else $consulta->where('sede_id', (int) $datos['sede_id']);
        if (! empty($datos['id'])) $consulta->where('id', '<>', (int) $datos['id']);
        if ($consulta->exists()) {
            throw ValidationException::withMessages(['fecha_inicio' => 'Ya existe un descuento vigente o programado con fechas superpuestas para el mismo plan y la misma sede.']);
        }
    }
}
