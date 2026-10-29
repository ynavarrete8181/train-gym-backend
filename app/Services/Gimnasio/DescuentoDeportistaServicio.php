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
