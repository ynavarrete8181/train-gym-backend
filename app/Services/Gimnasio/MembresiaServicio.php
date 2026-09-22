<?php

namespace App\Services\Gimnasio;

use App\Services\Concerns\RegistraAuditoria;
use App\Services\Configuracion\EstadoCatalogoServicio;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

        $plan = DB::table('gimnasio.planes')->where('id', $datos['plan_id'])->first();

        $datos['precio_aplicado'] = $this->resolverPrecio((int) $datos['plan_id'], $datos['sede_id'] ?? null);
        $datos['codigo_contrato'] = 'TMP-' . Str::uuid();
        $datos['fecha_fin'] = $this->calcularFechaFin($datos['fecha_inicio'], $plan->tipo_duracion, (int) $plan->duracion);
        $datos['estado'] = ($plan->requiere_pago ?? true) ? 'PENDIENTE_PAGO' : 'ACTIVA';
        $datos = $this->estados->aplicar($datos, 'MEMBRESIA');
        $datos['created_at'] = now();
        $datos['updated_at'] = now();

        $id = DB::table('gimnasio.membresias')->insertGetId($datos);
        $codigo = 'MEMB-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);

        DB::table('gimnasio.membresias')->where('id', $id)->update([
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

        $membresia = $this->obtenerMembresiaConRelaciones($id);
        $this->auditar('gimnasio', 'CREAR', 'gimnasio.membresias', $id, null, $membresia);
        return $membresia;
    }

    public function actualizar(int $id, array $datos)
    {
        $sedesHabilitadas = array_key_exists('sedes_habilitadas', $datos) ? $datos['sedes_habilitadas'] : null;
        $asignacionesEntrenador = array_key_exists('asignaciones_entrenador', $datos) ? $datos['asignaciones_entrenador'] : null;
        unset($datos['sedes_habilitadas'], $datos['asignaciones_entrenador'], $datos['entrenador_id']);

        $antes = DB::table('gimnasio.membresias')->where('id', $id)->first();

        if (array_key_exists('fecha_inicio', $datos)) {
            $plan = DB::table('gimnasio.planes')->where('id', $antes->plan_id)->first();
            $datos['fecha_fin'] = $this->calcularFechaFin($datos['fecha_inicio'], $plan->tipo_duracion, (int) $plan->duracion);
        }

        if (array_key_exists('estado', $datos)) {
            $datos = $this->estados->aplicar($datos, 'MEMBRESIA');
        }

        $datos['updated_at'] = now();
        DB::table('gimnasio.membresias')->where('id', $id)->update($datos);

        $actualizada = DB::table('gimnasio.membresias')->where('id', $id)->first();

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
            DB::table('gimnasio.asignaciones_entrenador_cliente')
                ->where('membresia_id', $id)
                ->where('estado', 'ACTIVO')
                ->update([
                    'fecha_inicio' => $actualizada->fecha_inicio,
                    'updated_at' => now(),
                ]);
        }

        $membresia = $this->obtenerMembresiaConRelaciones($id);
        $this->auditar('gimnasio', 'ACTUALIZAR', 'gimnasio.membresias', $id, $antes, $membresia);
        return $membresia;
    }

    public function eliminar(int $id): void
    {
        $antes = $this->obtenerMembresiaConRelaciones($id);
        DB::table('gimnasio.membresias')->where('id', $id)->delete();
        $this->auditar('gimnasio', 'ELIMINAR', 'gimnasio.membresias', $id, $antes, null);
    }

    public function obtenerMembresiaConRelaciones(int $id)
    {
        $membresia = DB::table('gimnasio.membresias')
            ->join('gimnasio.deportistas', 'gimnasio.membresias.deportista_id', '=', 'gimnasio.deportistas.id')
            ->join('seguridad.users', 'gimnasio.deportistas.usuario_id', '=', 'seguridad.users.id')
            ->join('gimnasio.planes', 'gimnasio.membresias.plan_id', '=', 'gimnasio.planes.id')
            ->leftJoin('institucional.sedes', 'gimnasio.membresias.sede_id', '=', 'institucional.sedes.id_sede')
            ->leftJoin('configuracion.estados_catalogo as estado_cfg', 'gimnasio.membresias.estado_id', '=', 'estado_cfg.id')
            ->select(
                'gimnasio.membresias.*',
                'gimnasio.deportistas.codigo_deportista',
                'seguridad.users.name as deportista_nombre',
                'seguridad.users.email as deportista_email',
                'gimnasio.planes.nombre as plan_nombre',
                'gimnasio.planes.tipo_producto',
                'gimnasio.planes.tipo_cobro',
                'gimnasio.planes.generar_venta',
                'gimnasio.planes.requiere_pago',
                'gimnasio.planes.requiere_entrenador',
                'gimnasio.planes.renovable',
                'institucional.sedes.nombre as sede_nombre',
                'estado_cfg.codigo as estado_codigo',
                'estado_cfg.valor_interno as estado_valor',
                'estado_cfg.nombre as estado_nombre',
                'estado_cfg.color as estado_color'
            )
            ->where('gimnasio.membresias.id', $id)
            ->first();

        if (! $membresia) {
            return null;
        }

        $membresia->sedes_habilitadas = DB::table('gimnasio.membresia_sedes as ms')
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

        $membresia->asignaciones_entrenador = DB::table('gimnasio.asignaciones_entrenador_cliente as a')
            ->join('gimnasio.entrenadores as e', 'e.id', '=', 'a.entrenador_id')
            ->join('seguridad.users as u', 'u.id', '=', 'e.usuario_id')
            ->leftJoin('gimnasio.horario_bloques as hb', 'hb.id', '=', 'a.horario_bloque_id')
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
                'u.name as entrenador_nombre',
                'e.especialidad',
                's.nombre as sede_nombre',
                'hb.nombre as horario_nombre',
            ]);

        return $membresia;
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

        DB::table('gimnasio.membresia_sedes')
            ->where('membresia_id', $membresiaId)
            ->whereNotIn('sede_id', $ids->all())
            ->update(['activo' => false, 'es_principal' => false, 'updated_at' => now()]);

        foreach ($ids as $sedeId) {
            DB::table('gimnasio.membresia_sedes')->updateOrInsert(
                ['membresia_id' => $membresiaId, 'sede_id' => $sedeId],
                [
                    'es_principal' => $sedeId === $sedePrincipalId,
                    'activo' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        DB::table('gimnasio.membresia_sedes')
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

        $actuales = DB::table('gimnasio.asignaciones_entrenador_cliente')
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
                DB::table('gimnasio.asignaciones_entrenador_cliente')
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
                DB::table('gimnasio.asignaciones_entrenador_cliente')
                    ->where('id', $existe->id)
                    ->update([
                        'fecha_inicio' => $fechaInicio,
                        'updated_at' => now(),
                    ]);
                continue;
            }

            DB::table('gimnasio.asignaciones_entrenador_cliente')->insert([
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

    private function calcularFechaFin(string $fechaInicio, string $tipoDuracion, int $duracion): string
    {
        $inicio = Carbon::parse($fechaInicio)->startOfDay();

        return match ($tipoDuracion) {
            'DIAS' => $inicio->copy()->addDays(max($duracion - 1, 0))->toDateString(),
            'MESES' => $inicio->copy()->addMonthsNoOverflow($duracion)->subDay()->toDateString(),
            'ANIOS' => $inicio->copy()->addYears($duracion)->subDay()->toDateString(),
            default => $inicio->toDateString(),
        };
    }

    private function resolverPrecio(int $planId, mixed $sedeId): float
    {
        if ($sedeId) {
            $precioSede = DB::table('gimnasio.plan_precios_sede')
                ->where('plan_id', $planId)
                ->where('sede_id', $sedeId)
                ->where('activo', true)
                ->value('precio');

            if ($precioSede !== null) {
                return (float) $precioSede;
            }
        }

        return (float) DB::table('gimnasio.planes')->where('id', $planId)->value('precio_base');
    }
}
