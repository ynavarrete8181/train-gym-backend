<?php

namespace App\Services\Gimnasio;

use App\Services\Concerns\RegistraAuditoria;
use Illuminate\Support\Facades\DB;

class PlanServicio
{
    use RegistraAuditoria;

    public function crear(array $datos)
    {
        $preciosSede = $datos['precios_sede'] ?? [];
        $servicioIds = $datos['servicio_ids'] ?? [];
        $modalidades = $datos['modalidades'] ?? [];
        unset($datos['precios_sede'], $datos['servicio_ids'], $datos['modalidades']);

        $datos['created_at'] = now();
        $datos['updated_at'] = now();

        return DB::transaction(function () use ($datos, $preciosSede, $servicioIds, $modalidades) {
            if (empty($datos['codigo'])) {
                $datos['codigo'] = $this->generarCodigo();
            }

            $id = DB::table('gimnasio.planes')->insertGetId($datos);

            $this->sincronizarPreciosSede($id, $preciosSede);
            $this->sincronizarServicios($id, $servicioIds);
            $this->sincronizarModalidades($id, $modalidades, (bool) ($datos['requiere_modalidades'] ?? false));

            $plan = DB::table('gimnasio.planes')->where('id', $id)->first();
            $plan->precios_sede = DB::table('gimnasio.plan_precios_sede')->where('plan_id', $id)->get();
            $plan->servicios = $this->serviciosDelPlan($id);
            $plan->modalidades = $this->modalidadesDelPlan($id);
            $this->auditar('membresias', 'CREAR', 'gimnasio.planes', $id, null, $plan);
            return $plan;
        });
    }

    public function actualizar(int $id, array $datos)
    {
        $preciosSede = $datos['precios_sede'] ?? [];
        $servicioIds = $datos['servicio_ids'] ?? [];
        $modalidades = $datos['modalidades'] ?? [];
        unset($datos['precios_sede'], $datos['servicio_ids'], $datos['modalidades']);

        $datos['updated_at'] = now();

        return DB::transaction(function () use ($id, $datos, $preciosSede, $servicioIds, $modalidades) {
            $antes = DB::table('gimnasio.planes')->where('id', $id)->first();
            DB::table('gimnasio.planes')->where('id', $id)->update($datos);

            $this->sincronizarPreciosSede($id, $preciosSede);
            $this->sincronizarServicios($id, $servicioIds);
            $this->sincronizarModalidades($id, $modalidades, (bool) ($datos['requiere_modalidades'] ?? false));

            $plan = DB::table('gimnasio.planes')->where('id', $id)->first();
            $plan->precios_sede = DB::table('gimnasio.plan_precios_sede')->where('plan_id', $id)->get();
            $plan->servicios = $this->serviciosDelPlan($id);
            $plan->modalidades = $this->modalidadesDelPlan($id);
            $this->auditar('membresias', 'ACTUALIZAR', 'gimnasio.planes', $id, $antes, $plan);
            return $plan;
        });
    }

    private function sincronizarPreciosSede(int $planId, array $preciosSede)
    {
        DB::table('gimnasio.plan_precios_sede')->where('plan_id', $planId)->delete();

        $insertData = [];
        foreach ($preciosSede as $p) {
            $insertData[] = [
                'plan_id' => $planId,
                'sede_id' => $p['sede_id'],
                'precio' => $p['precio'],
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (!empty($insertData)) {
            DB::table('gimnasio.plan_precios_sede')->insert($insertData);
        }
    }

    private function sincronizarServicios(int $planId, array $servicioIds): void
    {
        DB::table('gimnasio.plan_servicios')->where('plan_id', $planId)->delete();

        $ids = collect($servicioIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $ahora = now();
        DB::table('gimnasio.plan_servicios')->insert(
            $ids->map(fn ($servicioId) => [
                'plan_id' => $planId,
                'servicio_id' => $servicioId,
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ])->all()
        );
    }

    private function serviciosDelPlan(int $planId)
    {
        return DB::table('gimnasio.plan_servicios as ps')
            ->join('gimnasio.servicios as s', 's.id', '=', 'ps.servicio_id')
            ->leftJoin('gimnasio.categorias_servicio as c', 'c.id', '=', 's.categoria_id')
            ->where('ps.plan_id', $planId)
            ->where('ps.activo', true)
            ->orderBy('s.nombre')
            ->get([
                's.id',
                's.nombre',
                's.duracion_minutos',
                'c.nombre as categoria',
            ]);
    }

    private function sincronizarModalidades(int $planId, array $modalidades, bool $requiereModalidades): void
    {
        $existentes = DB::table('membresias.plan_modalidades')
            ->where('plan_id', $planId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $conservar = [];

        if ($requiereModalidades) {
            foreach ($modalidades as $modalidad) {
                $modalidadId = ! empty($modalidad['id']) ? (int) $modalidad['id'] : null;

                if ($modalidadId && ! in_array($modalidadId, $existentes, true)) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'modalidades' => 'Una de las modalidades no pertenece al plan seleccionado.',
                    ]);
                }

                unset($modalidad['id']);

                $guardada = $this->guardarModalidad($planId, $modalidad, $modalidadId);
                $conservar[] = (int) $guardada->id;
            }
        }

        $eliminar = array_values(array_diff($existentes, $conservar));

        foreach ($eliminar as $modalidadId) {
            $this->eliminarModalidad($planId, (int) $modalidadId);
        }
    }

    public function modalidadesDelPlan(int $planId)
    {
        $modalidades = DB::table('membresias.plan_modalidades')
            ->where('plan_id', $planId)
            ->orderByDesc('activo')
            ->orderBy('dias_por_semana')
            ->orderBy('nombre')
            ->get();

        $ids = $modalidades->pluck('id')->all();
        $precios = empty($ids)
            ? collect()
            : DB::table('membresias.plan_modalidad_precios_sede as pms')
                ->join('institucional.sedes as s', 's.id_sede', '=', 'pms.sede_id')
                ->whereIn('pms.modalidad_id', $ids)
                ->where('pms.activo', true)
                ->get([
                    'pms.id',
                    'pms.modalidad_id',
                    'pms.sede_id',
                    'pms.precio',
                    'pms.activo',
                    's.nombre as sede_nombre',
                ])
                ->groupBy('modalidad_id');

        return $modalidades->map(function ($modalidad) use ($precios) {
            $modalidad->precios_sede = $precios->get($modalidad->id, collect())->values();
            return $modalidad;
        });
    }

    public function guardarModalidad(int $planId, array $datos, ?int $modalidadId = null): object
    {
        $preciosSede = $datos['precios_sede'] ?? [];
        unset($datos['precios_sede']);

        return DB::transaction(function () use ($planId, $datos, $preciosSede, $modalidadId): object {
            $plan = DB::table('gimnasio.planes')->where('id', $planId)->first();
            if (! $plan) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'plan_id' => 'El plan seleccionado no existe.',
                ]);
            }

            if (! $plan->requiere_modalidades) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'plan_id' => 'Activa "Requiere modalidades" en el plan antes de registrar modalidades.',
                ]);
            }

            $existente = $modalidadId
                ? DB::table('membresias.plan_modalidades')->where('plan_id', $planId)->where('id', $modalidadId)->first()
                : null;

            if ($modalidadId && ! $existente) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'modalidad_id' => 'La modalidad no pertenece al plan.',
                ]);
            }

            if (empty($datos['codigo'])) {
                $datos['codigo'] = $this->generarCodigoModalidad($planId, (string) ($datos['nombre'] ?? 'MOD'));
            }

            if (($datos['uso_ilimitado'] ?? false) === true) {
                $datos['dias_por_semana'] = null;
                $datos['usos_por_semana'] = null;
            } else {
                $datos['usos_por_semana'] = $datos['usos_por_semana'] ?? $datos['dias_por_semana'] ?? null;
            }

            $datos['updated_at'] = now();

            if ($modalidadId) {
                DB::table('membresias.plan_modalidades')->where('id', $modalidadId)->update($datos);
                $id = $modalidadId;
            } else {
                $datos['plan_id'] = $planId;
                $datos['created_at'] = now();
                $id = DB::table('membresias.plan_modalidades')->insertGetId($datos);
            }

            DB::table('membresias.plan_modalidad_precios_sede')->where('modalidad_id', $id)->delete();
            if (! empty($preciosSede)) {
                DB::table('membresias.plan_modalidad_precios_sede')->insert(
                    collect($preciosSede)->map(fn ($precio) => [
                        'modalidad_id' => $id,
                        'sede_id' => (int) $precio['sede_id'],
                        'precio' => (float) $precio['precio'],
                        'activo' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])->all()
                );
            }

            return $this->modalidadesDelPlan($planId)->firstWhere('id', $id);
        });
    }

    public function eliminarModalidad(int $planId, int $modalidadId): void
    {
        $enUso = DB::table('gimnasio.membresias')
            ->where('modalidad_id', $modalidadId)
            ->exists();

        if ($enUso) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'modalidad_id' => 'La modalidad no se puede eliminar porque ya tiene membresías asociadas. Inactívala para conservar el historial.',
            ]);
        }

        DB::table('membresias.plan_modalidades')
            ->where('plan_id', $planId)
            ->where('id', $modalidadId)
            ->delete();
    }

    private function generarCodigoModalidad(int $planId, string $nombre): string
    {
        $base = strtoupper((string) preg_replace('/[^A-Z0-9]+/i', '-', trim($nombre)));
        $base = trim($base, '-');
        $base = $base !== '' ? substr($base, 0, 30) : 'MOD';
        $codigo = $base;
        $secuencia = 1;

        while (
            DB::table('membresias.plan_modalidades')
                ->where('plan_id', $planId)
                ->where('codigo', $codigo)
                ->exists()
        ) {
            $secuencia++;
            $codigo = $base . '-' . $secuencia;
        }

        return $codigo;
    }

    private function generarCodigo(): string
    {
        DB::statement('LOCK TABLE gimnasio.planes IN SHARE ROW EXCLUSIVE MODE');

        $siguiente = ((int) DB::table('gimnasio.planes')->max('id')) + 1;
        $codigo = 'PLAN-' . $siguiente;

        while (DB::table('gimnasio.planes')->where('codigo', $codigo)->exists()) {
            $siguiente++;
            $codigo = 'PLAN-' . $siguiente;
        }

        return $codigo;
    }

}
