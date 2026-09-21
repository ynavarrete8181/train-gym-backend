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
        $plan = DB::table('gimnasio.planes')->where('id', $datos['plan_id'])->first();

        $datos['precio_aplicado'] = $this->resolverPrecio((int) $datos['plan_id'], $datos['sede_id'] ?? null);
        $datos['codigo_contrato'] = 'TMP-' . Str::uuid();
        $datos['fecha_fin'] = $this->calcularFechaFin($datos['fecha_inicio'], $plan->tipo_duracion, (int) $plan->duracion);
        $datos['estado'] = ($plan->requiere_pago ?? true) ? 'PENDIENTE_PAGO' : 'ACTIVA';
        $datos = $this->estados->aplicar($datos, 'MEMBRESIA');
        $datos['created_at'] = now();
        $datos['updated_at'] = now();

        $id = DB::table('gimnasio.membresias')->insertGetId($datos);
        $this->sincronizarAsignacionEntrenador($id, (int) $datos['deportista_id'], $datos['entrenador_id'] ?? null, $datos['fecha_inicio']);
        $codigo = 'MEMB-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);

        DB::table('gimnasio.membresias')->where('id', $id)->update([
            'codigo_contrato' => $codigo,
            'updated_at' => now(),
        ]);

        $membresia = $this->obtenerMembresiaConRelaciones($id);
        $this->auditar('gimnasio', 'CREAR', 'gimnasio.membresias', $id, null, $membresia);
        return $membresia;
    }

    public function actualizar(int $id, array $datos)
    {
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

        if (array_key_exists('entrenador_id', $datos)) {
            $actualizada = DB::table('gimnasio.membresias')->where('id', $id)->first();
            $this->sincronizarAsignacionEntrenador($id, (int) $actualizada->deportista_id, $actualizada->entrenador_id, $actualizada->fecha_inicio);
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
        return DB::table('gimnasio.membresias')
            ->join('gimnasio.deportistas', 'gimnasio.membresias.deportista_id', '=', 'gimnasio.deportistas.id')
            ->join('seguridad.users', 'gimnasio.deportistas.usuario_id', '=', 'seguridad.users.id')
            ->join('gimnasio.planes', 'gimnasio.membresias.plan_id', '=', 'gimnasio.planes.id')
            ->leftJoin('institucional.sedes', 'gimnasio.membresias.sede_id', '=', 'institucional.sedes.id_sede')
            ->leftJoin('gimnasio.entrenadores as entrenador_membresia', 'gimnasio.membresias.entrenador_id', '=', 'entrenador_membresia.id')
            ->leftJoin('seguridad.users as entrenador_user', 'entrenador_membresia.usuario_id', '=', 'entrenador_user.id')
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
                'gimnasio.planes.renovable',
                'institucional.sedes.nombre as sede_nombre',
                'entrenador_user.name as entrenador_nombre',
                'entrenador_user.nombres as entrenador_nombres',
                'entrenador_user.apellidos as entrenador_apellidos',
                'estado_cfg.codigo as estado_codigo',
                'estado_cfg.valor_interno as estado_valor',
                'estado_cfg.nombre as estado_nombre',
                'estado_cfg.color as estado_color'
            )
            ->where('gimnasio.membresias.id', $id)
            ->first();
    }

    private function sincronizarAsignacionEntrenador(int $membresiaId, int $deportistaId, mixed $entrenadorId, string $fechaInicio): void
    {
        DB::table('gimnasio.asignaciones_entrenador_cliente')
            ->where('membresia_id', $membresiaId)
            ->where('estado', 'ACTIVO')
            ->update([
                'estado' => 'FINALIZADO',
                'fecha_fin' => now()->toDateString(),
                'updated_at' => now(),
            ]);

        if (! $entrenadorId) {
            return;
        }

        DB::table('gimnasio.asignaciones_entrenador_cliente')->insert([
            'entrenador_id' => (int) $entrenadorId,
            'deportista_id' => $deportistaId,
            'membresia_id' => $membresiaId,
            'horario_bloque_id' => null,
            'tipo_asignacion' => 'MEMBRESIA',
            'estado' => 'ACTIVO',
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
