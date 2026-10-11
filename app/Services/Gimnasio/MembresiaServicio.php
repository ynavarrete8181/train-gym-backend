<?php

namespace App\Services\Gimnasio;

use App\Services\Concerns\RegistraAuditoria;
use App\Services\Configuracion\EstadoCatalogoServicio;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MembresiaServicio
{
    use RegistraAuditoria;

    public function __construct(private readonly EstadoCatalogoServicio $estados)
    {
    }

    public function crear(array $datos)
    {
        $sedesHabilitadas = $datos['sedes_habilitadas'] ?? [];
        $asignacionesEntrenador = $datos['asignaciones_entrenador'] ?? [];
        unset($datos['sedes_habilitadas'], $datos['asignaciones_entrenador'], $datos['entrenador_id']);

        $plan = DB::table('membresias.planes')->where('id', $datos['plan_id'])->first();
        $modalidad = ! empty($datos['modalidad_id'])
            ? DB::table('membresias.plan_modalidades')
                ->where('id', (int) $datos['modalidad_id'])
                ->where('plan_id', (int) $datos['plan_id'])
                ->where('activo', true)
                ->first()
            : null;

        if (($plan->requiere_modalidades ?? false) && ! $modalidad) {
            throw ValidationException::withMessages([
                'modalidad_id' => 'Selecciona una modalidad activa del plan.',
            ]);
        }

        if (! ($plan->requiere_modalidades ?? false)) {
            $datos['modalidad_id'] = null;
        }

        if (($datos['generar_venta_automatica'] ?? false) && ! ($plan->renovable ?? false)) {
            throw ValidationException::withMessages([
                'generar_venta_automatica' => 'El cobro programado solo aplica a planes renovables.',
            ]);
        }

        $datos['proxima_fecha_cobro'] = ($datos['generar_venta_automatica'] ?? false) && ! empty($datos['dia_pago'])
            ? $this->calcularProximaFechaCobro($datos['fecha_inicio'], (int) $datos['dia_pago'])
            : null;

        $datos['precio_aplicado'] = $this->resolverPrecio(
            (int) $datos['plan_id'],
            $datos['sede_id'] ?? null,
            $modalidad?->id
        );
        $datos['codigo_contrato'] = 'TMP-' . Str::uuid();
        $tipoDuracion = $modalidad?->tipo_duracion ?? $plan->tipo_duracion;
        $duracion = (int) ($modalidad?->duracion ?? $plan->duracion);
        $datos['fecha_fin'] = $this->calcularFechaFin($datos['fecha_inicio'], $tipoDuracion, $duracion);
        $datos['estado'] = ($plan->requiere_pago ?? true) ? 'PENDIENTE_PAGO' : 'ACTIVA';
        $datos = $this->estados->aplicar($datos, 'MEMBRESIA');
        $datos['created_at'] = now();
        $datos['updated_at'] = now();

        $id = DB::table('membresias.membresias')->insertGetId($datos);
        $codigo = 'MEMB-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);

        DB::table('membresias.membresias')->where('id', $id)->update([
            'codigo_contrato' => $codigo,
            'entrenador_id' => null,
            'updated_at' => now(),
        ]);

        $this->sincronizarSedes($id, (int) $datos['sede_id'], $sedesHabilitadas);
        $this->sincronizarAsignaciones(
            $id,
            (int) $datos['deportista_id'],
            $datos['fecha_inicio'],
            $asignacionesEntrenador
        );

        DB::table('membresias.membresia_periodos')->insert([
            'membresia_id' => $id,
            'numero_periodo' => 1,
            'fecha_inicio' => $datos['fecha_inicio'],
            'fecha_fin' => $datos['fecha_fin'],
            'precio' => $datos['precio_aplicado'],
            'estado' => $datos['estado'],
            'venta_id' => null,
            'generado_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $membresia = $this->obtenerMembresiaConRelaciones($id);
        $this->auditar('gimnasio', 'CREAR', 'membresias.membresias', $id, null, $membresia);
        return $membresia;
    }

    public function actualizar(int $id, array $datos)
    {
        $sedesHabilitadas = array_key_exists('sedes_habilitadas', $datos) ? $datos['sedes_habilitadas'] : null;
        $asignacionesEntrenador = array_key_exists('asignaciones_entrenador', $datos) ? $datos['asignaciones_entrenador'] : null;
        unset($datos['sedes_habilitadas'], $datos['asignaciones_entrenador'], $datos['entrenador_id']);

        $antes = DB::table('membresias.membresias')->where('id', $id)->first();

        $planActual = DB::table('membresias.planes')->where('id', $antes->plan_id)->first();
        $generarAutomatico = array_key_exists('generar_venta_automatica', $datos)
            ? (bool) $datos['generar_venta_automatica']
            : (bool) ($antes->generar_venta_automatica ?? false);
        $diaPago = array_key_exists('dia_pago', $datos)
            ? ($datos['dia_pago'] ? (int) $datos['dia_pago'] : null)
            : ($antes->dia_pago ? (int) $antes->dia_pago : null);
        $fechaInicioCobro = $datos['fecha_inicio'] ?? $antes->fecha_inicio;

        if ($generarAutomatico && ! ($planActual->renovable ?? false)) {
            throw ValidationException::withMessages([
                'generar_venta_automatica' => 'El cobro programado solo aplica a planes renovables.',
            ]);
        }

        if (
            array_key_exists('generar_venta_automatica', $datos)
            || array_key_exists('dia_pago', $datos)
            || array_key_exists('fecha_inicio', $datos)
        ) {
            $datos['proxima_fecha_cobro'] = $generarAutomatico && $diaPago
                ? $this->calcularProximaFechaCobro($fechaInicioCobro, $diaPago)
                : null;
        }

        if (array_key_exists('fecha_inicio', $datos)) {
            $plan = DB::table('membresias.planes')->where('id', $antes->plan_id)->first();
            $modalidad = $antes->modalidad_id
                ? DB::table('membresias.plan_modalidades')->where('id', $antes->modalidad_id)->first()
                : null;
            $tipoDuracion = $modalidad?->tipo_duracion ?? $plan->tipo_duracion;
            $duracion = (int) ($modalidad?->duracion ?? $plan->duracion);
            $datos['fecha_fin'] = $this->calcularFechaFin($datos['fecha_inicio'], $tipoDuracion, $duracion);
        }

        if (array_key_exists('estado', $datos)) {
            $datos = $this->estados->aplicar($datos, 'MEMBRESIA');
        }

        $datos['updated_at'] = now();
        DB::table('membresias.membresias')->where('id', $id)->update($datos);

        $actualizada = DB::table('membresias.membresias')->where('id', $id)->first();

        if ($sedesHabilitadas !== null) {
            $this->sincronizarSedes($id, (int) $actualizada->sede_id, $sedesHabilitadas);
        }

        if ($asignacionesEntrenador !== null) {
            $this->sincronizarAsignaciones(
                $id,
                (int) $actualizada->deportista_id,
                $actualizada->fecha_inicio,
                $asignacionesEntrenador
            );
        } elseif (array_key_exists('fecha_inicio', $datos)) {
            DB::table('entrenamiento.asignaciones_entrenador_cliente')
                ->where('membresia_id', $id)
                ->where('estado', 'ACTIVO')
                ->update([
                    'fecha_inicio' => $actualizada->fecha_inicio,
                    'updated_at' => now(),
                ]);
        }

        $membresia = $this->obtenerMembresiaConRelaciones($id);
        $this->auditar('gimnasio', 'ACTUALIZAR', 'membresias.membresias', $id, $antes, $membresia);
        return $membresia;
    }

    public function eliminar(int $id): void
    {
        $antes = $this->obtenerMembresiaConRelaciones($id);
        DB::table('membresias.membresias')->where('id', $id)->delete();
        $this->auditar('gimnasio', 'ELIMINAR', 'membresias.membresias', $id, $antes, null);
    }

    public function obtenerMembresiaConRelaciones(int $id)
    {
        $membresia = DB::table('membresias.membresias')
            ->join('clientes.deportistas', 'membresias.membresias.deportista_id', '=', 'clientes.deportistas.id')
            ->leftJoin('personas.personas as p', 'clientes.deportistas.persona_id', '=', 'p.id')
            ->leftJoin('seguridad.users', 'clientes.deportistas.usuario_id', '=', 'seguridad.users.id')
            ->join('membresias.planes', 'membresias.membresias.plan_id', '=', 'membresias.planes.id')
            ->leftJoin('membresias.plan_modalidades as modalidad', 'membresias.membresias.modalidad_id', '=', 'modalidad.id')
            ->leftJoin('institucional.sedes', 'membresias.membresias.sede_id', '=', 'institucional.sedes.id_sede')
            ->leftJoin('configuracion.estados_catalogo as estado_cfg', 'membresias.membresias.estado_id', '=', 'estado_cfg.id')
            ->select(
                'membresias.membresias.*',
                'clientes.deportistas.codigo_deportista',
                DB::raw('COALESCE(p.nombre_completo, seguridad.users.name) as deportista_nombre'),
                DB::raw('COALESCE(p.email, seguridad.users.email) as deportista_email'),
                'membresias.planes.nombre as plan_nombre',
                'membresias.planes.tipo_producto',
                'membresias.planes.tipo_cobro',
                'membresias.planes.generar_venta',
                'membresias.planes.requiere_pago',
                'membresias.planes.requiere_entrenador',
                'membresias.planes.renovable',
                'membresias.planes.requiere_modalidades',
                'modalidad.nombre as modalidad_nombre',
                'modalidad.dias_por_semana as modalidad_dias_por_semana',
                'modalidad.usos_por_semana as modalidad_usos_por_semana',
                'modalidad.uso_ilimitado as modalidad_uso_ilimitado',
                'modalidad.tipo_duracion as modalidad_tipo_duracion',
                'modalidad.duracion as modalidad_duracion',
                'modalidad.modelo_cobro as modalidad_modelo_cobro',
                'modalidad.momento_cobro as modalidad_momento_cobro',
                'institucional.sedes.nombre as sede_nombre',
                'estado_cfg.codigo as estado_codigo',
                'estado_cfg.valor_interno as estado_valor',
                'estado_cfg.nombre as estado_nombre',
                'estado_cfg.color as estado_color'
            )
            ->where('membresias.membresias.id', $id)
            ->first();

        if (! $membresia) {
            return null;
        }

        $membresia->sedes_habilitadas = DB::table('membresias.membresia_sedes as ms')
            ->join('institucional.sedes as s', 's.id_sede', '=', 'ms.sede_id')
            ->where('ms.membresia_id', $id)
            ->where('ms.activo', true)
            ->orderByDesc('ms.es_principal')
            ->orderBy('s.nombre')
            ->get([
                'ms.sede_id',
                'ms.es_principal',
                'ms.activo',
                's.nombre as sede_nombre',
            ]);

        $membresia->asignaciones_entrenador = DB::table('entrenamiento.asignaciones_entrenador_cliente as a')
            ->join('entrenamiento.entrenadores as e', 'e.id', '=', 'a.entrenador_id')
            ->leftJoin('personas.personas as pe', 'pe.id', '=', 'e.persona_id')
            ->leftJoin('seguridad.users as u', 'u.id', '=', 'e.usuario_id')
            ->leftJoin('agenda.horario_bloques as hb', 'hb.id', '=', 'a.horario_bloque_id')
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', 'a.sede_id')
            ->where('a.membresia_id', $id)
            ->where('a.estado', 'ACTIVO')
            ->orderBy('s.nombre')
            ->orderBy('u.name')
            ->get([
                'a.id',
                'a.entrenador_id',
                'a.sede_id',
                'a.horario_bloque_id',
                'a.fecha_inicio',
                'a.fecha_fin',
                'a.estado',
                DB::raw('COALESCE(pe.nombre_completo, u.name) as entrenador_nombre'),
                'e.especialidad',
                's.nombre as sede_nombre',
                'hb.nombre as horario_nombre',
            ]);

        $venta = DB::table('ventas.ventas')
            ->where('membresia_id', $id)
            ->where('estado', '!=', 'ANULADA')
            ->orderByDesc('id')
            ->first(['id', 'numero', 'estado', 'total']);

        $membresia->requiere_facturar = (bool) $venta;
        $membresia->venta_id = $venta?->id;
        $membresia->venta_numero = $venta?->numero;
        $membresia->venta_estado = $venta?->estado;
        $membresia->venta_total = $venta?->total;
        $membresia->periodos = $this->periodos($id);
        $membresia->periodo_actual = $membresia->periodos->first();

        return $membresia;
    }

    public function periodos(int $membresiaId)
    {
        return DB::table('membresias.membresia_periodos as p')
            ->leftJoin('ventas.ventas as v', 'v.id', '=', 'p.venta_id')
            ->where('p.membresia_id', $membresiaId)
            ->orderByDesc('p.numero_periodo')
            ->get([
                'p.id',
                'p.numero_periodo',
                'p.fecha_inicio',
                'p.fecha_fin',
                'p.precio',
                'p.estado',
                'p.venta_id',
                'v.numero as venta_numero',
                'v.estado as venta_estado',
                'v.total as venta_total',
                'p.generado_at',
            ]);
    }

    public function crearSiguientePeriodo(int $membresiaId): object
    {
        $membresia = DB::table('membresias.membresias')->where('id', $membresiaId)->lockForUpdate()->first();

        if (! $membresia) {
            throw ValidationException::withMessages([
                'membresia_id' => 'La membresía no existe.',
            ]);
        }

        $plan = DB::table('membresias.planes')->where('id', $membresia->plan_id)->first();
        if (! $plan || ! ($plan->renovable ?? false)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'membresia_id' => 'El plan de esta membresía no permite renovación.',
            ]);
        }

        $ultimo = DB::table('membresias.membresia_periodos')
            ->where('membresia_id', $membresiaId)
            ->orderByDesc('numero_periodo')
            ->lockForUpdate()
            ->first();

        $inicio = $ultimo
            ? Carbon::parse($ultimo->fecha_fin)->addDay()->toDateString()
            : Carbon::parse($membresia->fecha_fin)->addDay()->toDateString();

        $numero = $ultimo ? ((int) $ultimo->numero_periodo + 1) : 1;
        $modalidad = $membresia->modalidad_id
            ? DB::table('membresias.plan_modalidades')->where('id', $membresia->modalidad_id)->first()
            : null;
        $tipoDuracion = $modalidad?->tipo_duracion ?? $plan->tipo_duracion;
        $duracion = (int) ($modalidad?->duracion ?? $plan->duracion);
        $fin = $this->calcularFechaFin($inicio, $tipoDuracion, $duracion);
        $precio = $this->resolverPrecio((int) $plan->id, $membresia->sede_id, $membresia->modalidad_id);

        $estadoValor = ($plan->requiere_pago ?? true) ? 'PENDIENTE_PAGO' : 'ACTIVA';
        $estadoMembresia = $this->estados->aplicar(['estado' => $estadoValor], 'MEMBRESIA');

        $id = DB::table('membresias.membresia_periodos')->insertGetId([
            'membresia_id' => $membresiaId,
            'numero_periodo' => $numero,
            'fecha_inicio' => $inicio,
            'fecha_fin' => $fin,
            'precio' => $precio,
            'estado' => $estadoValor,
            'venta_id' => null,
            'generado_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('membresias.membresias')->where('id', $membresiaId)->update([
            'fecha_inicio' => $inicio,
            'fecha_fin' => $fin,
            'precio_aplicado' => $precio,
            'estado' => $estadoMembresia['estado'],
            'estado_id' => $estadoMembresia['estado_id'] ?? null,
            'updated_at' => now(),
        ]);

        $periodo = DB::table('membresias.membresia_periodos')->where('id', $id)->first();

        $this->auditar(
            'gimnasio',
            'RENOVAR_MEMBRESIA',
            'membresias.membresia_periodos',
            $id,
            $ultimo,
            $periodo,
            'Nuevo período generado para la membresía #' . $membresiaId . '.'
        );

        return $periodo;
    }

    public function vincularVentaPeriodo(int $periodoId, int $ventaId): void
    {
        $antes = DB::table('membresias.membresia_periodos')->where('id', $periodoId)->first();

        DB::table('membresias.membresia_periodos')->where('id', $periodoId)->update([
            'venta_id' => $ventaId,
            'updated_at' => now(),
        ]);

        $despues = DB::table('membresias.membresia_periodos')->where('id', $periodoId)->first();

        $this->auditar(
            'gimnasio',
            'VINCULAR_VENTA',
            'membresias.membresia_periodos',
            $periodoId,
            $antes,
            $despues,
            'Venta #' . $ventaId . ' vinculada al período de membresía.'
        );
    }

    private function sincronizarSedes(int $membresiaId, int $sedePrincipalId, array $sedesHabilitadas): void
    {
        $ids = collect($sedesHabilitadas)
            ->map(fn ($item) => is_array($item) ? ($item['sede_id'] ?? null) : $item)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->push($sedePrincipalId)
            ->unique()
            ->values();

        DB::table('membresias.membresia_sedes')
            ->where('membresia_id', $membresiaId)
            ->whereNotIn('sede_id', $ids->all())
            ->update(['activo' => false, 'es_principal' => false, 'updated_at' => now()]);

        foreach ($ids as $sedeId) {
            DB::table('membresias.membresia_sedes')->updateOrInsert(
                ['membresia_id' => $membresiaId, 'sede_id' => $sedeId],
                [
                    'es_principal' => $sedeId === $sedePrincipalId,
                    'activo' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        DB::table('membresias.membresia_sedes')
            ->where('membresia_id', $membresiaId)
            ->where('sede_id', '!=', $sedePrincipalId)
            ->update(['es_principal' => false, 'updated_at' => now()]);
    }

    private function sincronizarAsignaciones(int $membresiaId, int $deportistaId, string $fechaInicio, array $asignaciones): void
    {
        $deseadas = collect($asignaciones)
            ->map(fn ($a) => [
                'entrenador_id' => (int) $a['entrenador_id'],
                'sede_id' => (int) $a['sede_id'],
                'horario_bloque_id' => (int) $a['horario_bloque_id'],
            ])
            ->unique(fn ($a) => $a['entrenador_id'] . ':' . $a['sede_id'] . ':' . $a['horario_bloque_id'])
            ->values();

        $actuales = DB::table('entrenamiento.asignaciones_entrenador_cliente')
            ->where('membresia_id', $membresiaId)
            ->where('estado', 'ACTIVO')
            ->get();

        foreach ($actuales as $actual) {
            $mantener = $deseadas->contains(fn ($a) =>
                $a['entrenador_id'] === (int) $actual->entrenador_id
                && $a['sede_id'] === (int) $actual->sede_id
                && $a['horario_bloque_id'] === (int) $actual->horario_bloque_id
            );

            if (! $mantener) {
                DB::table('entrenamiento.asignaciones_entrenador_cliente')
                    ->where('id', $actual->id)
                    ->update([
                        'estado' => 'FINALIZADO',
                        'fecha_fin' => now()->toDateString(),
                        'updated_at' => now(),
                    ]);
            }
        }

        foreach ($deseadas as $asignacion) {
            $existe = $actuales->first(fn ($actual) =>
                (int) $actual->entrenador_id === $asignacion['entrenador_id']
                && (int) $actual->sede_id === $asignacion['sede_id']
                && (int) $actual->horario_bloque_id === $asignacion['horario_bloque_id']
            );

            if ($existe) {
                DB::table('entrenamiento.asignaciones_entrenador_cliente')
                    ->where('id', $existe->id)
                    ->update([
                        'fecha_inicio' => $fechaInicio,
                        'updated_at' => now(),
                    ]);
                continue;
            }

            DB::table('entrenamiento.asignaciones_entrenador_cliente')->insert([
                'entrenador_id' => $asignacion['entrenador_id'],
                'deportista_id' => $deportistaId,
                'membresia_id' => $membresiaId,
                'sede_id' => $asignacion['sede_id'],
                'horario_bloque_id' => $asignacion['horario_bloque_id'],
                'tipo_asignacion' => 'MEMBRESIA',
                'estado' => 'ACTIVO',
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function calcularProximaFechaCobro(string $fechaInicio, int $diaPago): string
    {
        $inicio = Carbon::parse($fechaInicio)->startOfDay();
        $diaPago = max(1, min(31, $diaPago));

        $candidato = $inicio->copy()->startOfMonth();
        $candidato->day(min($diaPago, $candidato->daysInMonth));

        if ($candidato->lt($inicio)) {
            $candidato = $inicio->copy()->addMonthNoOverflow()->startOfMonth();
            $candidato->day(min($diaPago, $candidato->daysInMonth));
        }

        return $candidato->toDateString();
    }

    private function calcularFechaFin(string $fechaInicio, string $tipoDuracion, int $duracion): string
    {
        $inicio = Carbon::parse($fechaInicio)->startOfDay();

        return match ($tipoDuracion) {
            'DIAS' => $inicio->copy()->addDays(max($duracion - 1, 0))->toDateString(),
            'SEMANAS' => $inicio->copy()->addWeeks($duracion)->subDay()->toDateString(),
            'MESES' => $inicio->copy()->addMonthsNoOverflow($duracion)->subDay()->toDateString(),
            'ANIOS' => $inicio->copy()->addYears($duracion)->subDay()->toDateString(),
            default => $inicio->toDateString(),
        };
    }

    private function resolverPrecio(int $planId, mixed $sedeId, mixed $modalidadId = null): float
    {
        if ($modalidadId) {
            if ($sedeId) {
                $precioSede = DB::table('membresias.plan_modalidad_precios_sede')
                    ->where('modalidad_id', (int) $modalidadId)
                    ->where('sede_id', $sedeId)
                    ->where('activo', true)
                    ->value('precio');

                if ($precioSede !== null) {
                    return (float) $precioSede;
                }
            }

            return (float) DB::table('membresias.plan_modalidades')
                ->where('id', (int) $modalidadId)
                ->where('plan_id', $planId)
                ->value('precio_base');
        }

        if ($sedeId) {
            $precioSede = DB::table('membresias.plan_precios_sede')
                ->where('plan_id', $planId)
                ->where('sede_id', $sedeId)
                ->where('activo', true)
                ->value('precio');

            if ($precioSede !== null) {
                return (float) $precioSede;
            }
        }

        return (float) DB::table('membresias.planes')->where('id', $planId)->value('precio_base');
    }
}
