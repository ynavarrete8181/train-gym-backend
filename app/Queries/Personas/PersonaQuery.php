<?php

namespace App\Queries\Personas;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PersonaQuery
{
    public function buscarPorDocumento(string $documento): ?array
    {
        $documento = trim($documento);
        $documentoNormalizado = preg_replace('/[^A-Za-z0-9]/', '', $documento);

        if ($documento === '' || $documentoNormalizado === '') {
            return null;
        }

        $row = DB::table('core.personas as p')
            ->leftJoin('seguridad.usuarios as su', 'su.persona_id', '=', 'p.id')
            ->leftJoin('core.estados as e', 'e.id', '=', 'p.estado_id')
            ->selectRaw("
                p.id,
                p.numero_identificacion,
                p.tipo_identificacion,
                p.nombres,
                p.apellidos,
                p.email,
                p.telefono,
                p.direccion,
                p.foto_url,
                p.ciudad,
                p.provincia,
                p.fecha_nacimiento,
                p.sexo,
                e.codigo as estado_codigo,
                e.nombre as estado_nombre
            ")
            ->where(function ($query) use ($documento, $documentoNormalizado) {
                $query->where('p.numero_identificacion', $documento)
                    ->orWhere('su.cedula', $documento)
                    ->orWhereRaw(
                        "regexp_replace(COALESCE(p.numero_identificacion, ''), '[^A-Za-z0-9]', '', 'g') = ?",
                        [$documentoNormalizado]
                    )
                    ->orWhereRaw(
                        "regexp_replace(COALESCE(su.cedula, ''), '[^A-Za-z0-9]', '', 'g') = ?",
                        [$documentoNormalizado]
                    );
            })
            ->orderByDesc('p.id')
            ->first();

        if (!$row) {
            return null;
        }

        $tipos = DB::table('core.persona_tipo_detalle as ptd')
            ->join('core.persona_tipos as pt', 'pt.id', '=', 'ptd.tipo_id')
            ->where('ptd.persona_id', $row->id)
            ->where('ptd.activo', true)
            ->orderBy('pt.nombre')
            ->get(['pt.codigo', 'pt.nombre'])
            ->map(fn ($tipo) => [
                'codigo' => $tipo->codigo,
                'nombre' => $tipo->nombre,
            ])
            ->all();

        $socio = DB::table('socios.socios as s')
            ->leftJoin('socios.socio_membresias as sm', function ($join) {
                $join->on('sm.socio_id', '=', 's.id');
            })
            ->leftJoin('socios.membresias as m', 'm.id', '=', 'sm.membresia_id')
            ->leftJoin('core.estados as e', 'e.id', '=', 's.estado_id')
            ->leftJoin('core.estados as eme', 'eme.id', '=', 'sm.estado_id')
            ->where('s.persona_id', $row->id)
            ->orderByDesc('sm.id')
            ->selectRaw("
                s.id,
                s.codigo_socio,
                s.sede_id,
                s.fecha_alta,
                e.nombre as estado_nombre,
                e.codigo as estado_codigo,
                m.nombre as membresia_nombre,
                sm.fecha_inicio,
                sm.fecha_fin,
                eme.codigo as membresia_estado_codigo
            ")
            ->first();

        $ultimaMedicion = DB::table('salud.fichas_tecnicas as ft')
            ->leftJoin('salud.ficha_mediciones as fm', 'fm.ficha_tecnica_id', '=', 'ft.id')
            ->where('ft.persona_id', $row->id)
            ->orderByDesc('ft.fecha_ficha')
            ->orderByDesc('fm.id')
            ->selectRaw("
                ft.id as ficha_id,
                ft.fecha_ficha,
                ft.objetivo,
                ft.actividad_fisica,
                fm.peso_kg,
                fm.talla_cm,
                fm.imc,
                fm.cintura_cm,
                fm.grasa_corporal_pct,
                fm.masa_magra_kg
            ")
            ->first();

        $membresiaActiva = $this->isMembresiaActiva($socio);
        $deudaResumen = $this->buildDeudaResumen((int) $row->id);

        return [
            'id' => (int) $row->id,
            'cedula' => $row->numero_identificacion,
            'tipo_identificacion' => $row->tipo_identificacion,
            'nombres' => trim($row->nombres . ' ' . ($row->apellidos ?? '')),
            'nombres_base' => $row->nombres,
            'apellidos' => $row->apellidos,
            'email' => $row->email,
            'telefono' => $row->telefono,
            'direccion' => $row->direccion,
            'foto_url' => $row->foto_url,
            'ciudad' => $row->ciudad,
            'provincia' => $row->provincia,
            'fecha_nacimiento' => $row->fecha_nacimiento,
            'sexo' => $row->sexo,
            'estado' => $row->estado_nombre ?? 'Activo',
            'estado_codigo' => $row->estado_codigo ?? 'ACTIVO',
            'tipos' => $tipos,
            'es_socio' => (bool) $socio,
            'socio' => $socio ? [
                'id' => (int) $socio->id,
                'codigo_socio' => $socio->codigo_socio,
                'sede_id' => $socio->sede_id ? (int) $socio->sede_id : null,
                'fecha_alta' => $socio->fecha_alta,
                'estado' => $socio->estado_nombre,
                'estado_codigo' => $socio->estado_codigo,
                'membresia_nombre' => $socio->membresia_nombre,
                'fecha_inicio' => $socio->fecha_inicio,
                'fecha_fin' => $socio->fecha_fin,
                'membresia_estado_codigo' => $socio->membresia_estado_codigo,
                'membresia_activa' => $membresiaActiva,
            ] : null,
            'deuda' => $deudaResumen,
            'ultima_ficha' => $ultimaMedicion ? [
                'ficha_id' => (int) $ultimaMedicion->ficha_id,
                'fecha_ficha' => $ultimaMedicion->fecha_ficha,
                'objetivo' => $ultimaMedicion->objetivo,
                'actividad_fisica' => $ultimaMedicion->actividad_fisica,
                'peso_kg' => $ultimaMedicion->peso_kg !== null ? (float) $ultimaMedicion->peso_kg : null,
                'talla_cm' => $ultimaMedicion->talla_cm !== null ? (float) $ultimaMedicion->talla_cm : null,
                'imc' => $ultimaMedicion->imc !== null ? (float) $ultimaMedicion->imc : null,
                'cintura_cm' => $ultimaMedicion->cintura_cm !== null ? (float) $ultimaMedicion->cintura_cm : null,
                'grasa_corporal_pct' => $ultimaMedicion->grasa_corporal_pct !== null ? (float) $ultimaMedicion->grasa_corporal_pct : null,
                'masa_magra_kg' => $ultimaMedicion->masa_magra_kg !== null ? (float) $ultimaMedicion->masa_magra_kg : null,
            ] : null,
        ];
    }

    public function listar(array $filtros = []): array
    {
        $query = DB::table('core.personas as p')
            ->leftJoin('core.estados as e', 'e.id', '=', 'p.estado_id')
            ->selectRaw("
                p.id,
                p.numero_identificacion as cedula,
                p.nombres,
                p.apellidos,
                p.email,
                p.telefono,
                p.fecha_nacimiento,
                p.sexo,
                p.foto_url,
                p.ciudad,
                p.provincia,
                p.direccion,
                e.nombre as estado,
                e.codigo as estado_codigo
            ");

        if (!empty($filtros['buscar'])) {
            $buscar = '%' . trim($filtros['buscar']) . '%';
            $query->where(function ($q) use ($buscar) {
                $q->where('p.numero_identificacion', 'like', $buscar)
                  ->orWhere('p.nombres', 'like', $buscar)
                  ->orWhere('p.apellidos', 'like', $buscar);
            });
        }

        if (!empty($filtros['tipo_persona'])) {
            $query->whereExists(function ($q) use ($filtros) {
                $q->select(DB::raw(1))
                  ->from('core.persona_tipo_detalle as ptd')
                  ->join('core.persona_tipos as pt', 'pt.id', '=', 'ptd.tipo_id')
                  ->whereColumn('ptd.persona_id', 'p.id')
                  ->where('pt.codigo', $filtros['tipo_persona'])
                  ->where('ptd.activo', true);
            });
        }

        if (!empty($filtros['sede_id'])) {
            $query->whereExists(function ($q) use ($filtros) {
                $q->select(DB::raw(1))
                  ->from('socios.socios as s')
                  ->whereColumn('s.persona_id', 'p.id')
                  ->where('s.sede_id', $filtros['sede_id']);
            });
        }

        if (!empty($filtros['estado_membresia'])) {
            $estadoStr = $filtros['estado_membresia'];
            if ($estadoStr === 'SIN_MEMBRESIA') {
                $query->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                      ->from('socios.socios as s')
                      ->join('socios.socio_membresias as sm', 'sm.socio_id', '=', 's.id')
                      ->whereColumn('s.persona_id', 'p.id');
                });
            } else {
                $query->whereExists(function ($q) use ($estadoStr) {
                    $q->select(DB::raw(1))
                      ->from('socios.socios as s')
                      ->join('socios.socio_membresias as sm', 'sm.socio_id', '=', 's.id')
                      ->join('core.estados as me', 'me.id', '=', 'sm.estado_id')
                      ->whereColumn('s.persona_id', 'p.id')
                      ->whereRaw("sm.id = (SELECT id FROM socios.socio_membresias WHERE socio_id = s.id ORDER BY id DESC LIMIT 1)");
                      
                    if ($estadoStr === 'ACTIVO') {
                        $q->where('me.codigo', 'ACTIVO')
                          ->whereDate('sm.fecha_fin', '>', now()->addDays(7));
                    } elseif ($estadoStr === 'POR_VENCER') {
                        $q->where('me.codigo', 'ACTIVO')
                          ->whereDate('sm.fecha_fin', '>=', now())
                          ->whereDate('sm.fecha_fin', '<=', now()->addDays(7));
                    } elseif ($estadoStr === 'VENCIDO') {
                        $q->where(function($q2) {
                            $q2->where('me.codigo', '!=', 'ACTIVO')
                               ->orWhereDate('sm.fecha_fin', '<', now());
                        });
                    }
                });
            }
        }

        $personas = $query->orderBy('p.nombres')->orderBy('p.apellidos')->get();

        return $personas->map(function ($p) {
            $tipos = DB::table('core.persona_tipo_detalle as ptd')
                ->join('core.persona_tipos as pt', 'pt.id', '=', 'ptd.tipo_id')
                ->where('ptd.persona_id', $p->id)
                ->where('ptd.activo', true)
                ->get(['pt.codigo', 'pt.nombre'])
                ->all();

            $socio = DB::table('socios.socios as s')
                ->leftJoin('socios.socio_membresias as sm', function($join) {
                    $join->on('sm.socio_id', '=', 's.id');
                })
                ->leftJoin('core.sedes as se', 'se.id', '=', 's.sede_id')
                ->leftJoin('core.estados as e', 'e.id', '=', 'sm.estado_id')
                ->where('s.persona_id', $p->id)
                ->orderByDesc('sm.id')
                ->select(
                    's.codigo_socio', 
                    's.sede_id',
                    'se.nombre as sede_nombre', 
                    'e.codigo as membresia_estado_codigo', 
                    'sm.fecha_fin'
                )
                ->first();

            $estadoMembresia = 'SIN_MEMBRESIA';
            if ($socio && $socio->membresia_estado_codigo) {
                if ($socio->membresia_estado_codigo === 'ACTIVO') {
                    $fechaFin = \Carbon\Carbon::parse($socio->fecha_fin);
                    if ($fechaFin->isPast() && !$fechaFin->isToday()) {
                        $estadoMembresia = 'VENCIDO';
                    } elseif ($fechaFin->diffInDays(now()) <= 7 && ($fechaFin->isFuture() || $fechaFin->isToday())) {
                        $estadoMembresia = 'POR_VENCER';
                    } else {
                        $estadoMembresia = 'ACTIVO';
                    }
                } else {
                    $estadoMembresia = 'VENCIDO';
                }
            }

            return [
                'id' => (int) $p->id,
                'cedula' => $p->cedula,
                'nombres' => trim($p->nombres . ' ' . ($p->apellidos ?? '')),
                'nombres_base' => $p->nombres,
                'apellidos' => $p->apellidos,
                'email' => $p->email,
                'telefono' => $p->telefono,
                'fecha_nacimiento' => $p->fecha_nacimiento,
                'sexo' => $p->sexo,
                'foto_url' => $p->foto_url,
                'ciudad' => $p->ciudad,
                'provincia' => $p->provincia,
                'direccion' => $p->direccion,
                'estado' => $p->estado ?? 'Activo',
                'estado_codigo' => $p->estado_codigo ?? 'ACTIVO',
                'tipos' => $tipos,
                'es_socio' => (bool) $socio,
                'codigo_socio' => $socio?->codigo_socio ?? null,
                'sede_id' => $socio?->sede_id ?? null,
                'sede_nombre' => $socio?->sede_nombre ?? null,
                'estado_membresia' => $estadoMembresia,
                'membresia_fecha_fin' => $socio?->fecha_fin ?? null,
            ];
        })->all();
    }

    public function obtenerPorId(int $id): ?array
    {
        $row = DB::table('core.personas as p')
            ->leftJoin('core.estados as e', 'e.id', '=', 'p.estado_id')
            ->selectRaw("
                p.id,
                p.numero_identificacion,
                p.tipo_identificacion,
                p.nombres,
                p.apellidos,
                p.email,
                p.telefono,
                p.direccion,
                p.foto_url,
                p.ciudad,
                p.provincia,
                p.fecha_nacimiento,
                p.sexo,
                e.codigo as estado_codigo,
                e.nombre as estado_nombre
            ")
            ->where('p.id', $id)
            ->first();

        if (!$row) {
            return null;
        }

        $tipos = DB::table('core.persona_tipo_detalle as ptd')
            ->join('core.persona_tipos as pt', 'pt.id', '=', 'ptd.tipo_id')
            ->where('ptd.persona_id', $row->id)
            ->where('ptd.activo', true)
            ->orderBy('pt.nombre')
            ->get(['pt.codigo', 'pt.nombre'])
            ->map(fn ($tipo) => [
                'codigo' => $tipo->codigo,
                'nombre' => $tipo->nombre,
            ])
            ->all();

        $socio = DB::table('socios.socios as s')
            ->leftJoin('socios.socio_membresias as sm', function ($join) {
                $join->on('sm.socio_id', '=', 's.id');
            })
            ->leftJoin('socios.membresias as m', 'm.id', '=', 'sm.membresia_id')
            ->leftJoin('core.estados as e', 'e.id', '=', 's.estado_id')
            ->leftJoin('core.estados as eme', 'eme.id', '=', 'sm.estado_id')
            ->where('s.persona_id', $row->id)
            ->orderByDesc('sm.id')
            ->selectRaw("
                s.id,
                s.codigo_socio,
                s.sede_id,
                s.fecha_alta,
                e.nombre as estado_nombre,
                e.codigo as estado_codigo,
                m.nombre as membresia_nombre,
                sm.fecha_inicio,
                sm.fecha_fin,
                eme.codigo as membresia_estado_codigo
            ")
            ->first();

        $historialMediciones = DB::table('salud.fichas_tecnicas as ft')
            ->leftJoin('salud.ficha_mediciones as fm', 'fm.ficha_tecnica_id', '=', 'ft.id')
            ->where('ft.persona_id', $row->id)
            ->orderByDesc('ft.fecha_ficha')
            ->orderByDesc('fm.id')
            ->selectRaw("
                ft.id as ficha_id,
                ft.fecha_ficha,
                ft.objetivo,
                ft.actividad_fisica,
                ft.observaciones,
                fm.peso_kg,
                fm.talla_cm,
                fm.imc,
                fm.cintura_cm,
                fm.grasa_corporal_pct,
                fm.masa_magra_kg
            ")
            ->get()
            ->map(fn ($m) => [
                'ficha_id' => (int) $m->ficha_id,
                'fecha_ficha' => $m->fecha_ficha,
                'objetivo' => $m->objetivo,
                'actividad_fisica' => $m->actividad_fisica,
                'observaciones' => $m->observaciones,
                'peso_kg' => $m->peso_kg !== null ? (float) $m->peso_kg : null,
                'talla_cm' => $m->talla_cm !== null ? (float) $m->talla_cm : null,
                'imc' => $m->imc !== null ? (float) $m->imc : null,
                'cintura_cm' => $m->cintura_cm !== null ? (float) $m->cintura_cm : null,
                'grasa_corporal_pct' => $m->grasa_corporal_pct !== null ? (float) $m->grasa_corporal_pct : null,
                'masa_magra_kg' => $m->masa_magra_kg !== null ? (float) $m->masa_magra_kg : null,
            ])
            ->all();

        $membresiaActiva = $this->isMembresiaActiva($socio);
        $deudaResumen = $this->buildDeudaResumen((int) $row->id);

        return [
            'id' => (int) $row->id,
            'cedula' => $row->numero_identificacion,
            'tipo_identificacion' => $row->tipo_identificacion,
            'nombres' => trim($row->nombres . ' ' . ($row->apellidos ?? '')),
            'nombres_base' => $row->nombres,
            'apellidos' => $row->apellidos,
            'email' => $row->email,
            'telefono' => $row->telefono,
            'direccion' => $row->direccion,
            'foto_url' => $row->foto_url,
            'ciudad' => $row->ciudad,
            'provincia' => $row->provincia,
            'fecha_nacimiento' => $row->fecha_nacimiento,
            'sexo' => $row->sexo,
            'estado' => $row->estado_nombre ?? 'Activo',
            'estado_codigo' => $row->estado_codigo ?? 'ACTIVO',
            'tipos' => $tipos,
            'es_socio' => (bool) $socio,
            'socio' => $socio ? [
                'id' => (int) $socio->id,
                'codigo_socio' => $socio->codigo_socio,
                'sede_id' => $socio->sede_id ? (int) $socio->sede_id : null,
                'fecha_alta' => $socio->fecha_alta,
                'estado' => $socio->estado_nombre,
                'estado_codigo' => $socio->estado_codigo,
                'membresia_nombre' => $socio->membresia_nombre,
                'fecha_inicio' => $socio->fecha_inicio,
                'fecha_fin' => $socio->fecha_fin,
                'membresia_estado_codigo' => $socio->membresia_estado_codigo,
                'membresia_activa' => $membresiaActiva,
            ] : null,
            'deuda' => $deudaResumen,
            'historial_fichas' => $historialMediciones,
        ];
    }

    private function isMembresiaActiva(object|null $socio): bool
    {
        if (!$socio || empty($socio->fecha_fin)) {
            return false;
        }

        if (strtoupper((string) ($socio->membresia_estado_codigo ?? '')) !== 'ACTIVO') {
            return false;
        }

        return \Carbon\Carbon::parse($socio->fecha_fin)->gte(now()->startOfDay());
    }

    private function buildDeudaResumen(int $personaId): array
    {
        if (
            !$this->hasVentasDebtColumns()
        ) {
            return [
                'tiene_deuda' => false,
                'cantidad' => 0,
                'saldo_total' => 0,
                'saldo_consumo' => 0,
                'saldo_membresia' => 0,
                'items' => [],
            ];
        }

        try {
            $items = DB::table('ventas.ventas')
                ->where('persona_id', $personaId)
                ->whereIn('estado_pago', ['PENDIENTE', 'ABONADO'])
                ->where('saldo_pendiente', '>', 0)
                ->orderByDesc('fecha_consumo')
                ->orderByDesc('id')
                ->get(['id', 'tipo_venta', 'saldo_pendiente', 'fecha_consumo', 'referencia']);
        } catch (QueryException) {
            return [
                'tiene_deuda' => false,
                'cantidad' => 0,
                'saldo_total' => 0,
                'saldo_consumo' => 0,
                'saldo_membresia' => 0,
                'items' => [],
            ];
        }

        $saldoConsumo = (float) $items->filter(fn ($item) => $item->tipo_venta === 'CONSUMO')->sum('saldo_pendiente');
        $saldoMembresia = (float) $items->filter(fn ($item) => $item->tipo_venta === 'MEMBRESIA')->sum('saldo_pendiente');

        return [
            'tiene_deuda' => $items->isNotEmpty(),
            'cantidad' => $items->count(),
            'saldo_total' => round((float) $items->sum('saldo_pendiente'), 2),
            'saldo_consumo' => round($saldoConsumo, 2),
            'saldo_membresia' => round($saldoMembresia, 2),
            'items' => $items->take(5)->map(fn ($item) => [
                'venta_id' => (int) $item->id,
                'tipo_venta' => $item->tipo_venta,
                'saldo_pendiente' => (float) $item->saldo_pendiente,
                'fecha_consumo' => $item->fecha_consumo,
                'referencia' => $item->referencia,
            ])->all(),
        ];
    }

    private function hasVentasDebtColumns(): bool
    {
        return Schema::hasColumn('ventas.ventas', 'tipo_venta')
            && Schema::hasColumn('ventas.ventas', 'estado_pago')
            && Schema::hasColumn('ventas.ventas', 'saldo_pendiente')
            && Schema::hasColumn('ventas.ventas', 'fecha_consumo');
    }
}
