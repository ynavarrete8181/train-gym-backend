<?php

namespace App\Http\Controllers\Personas;

use App\Http\Controllers\Controller;
use App\Queries\Personas\MembresiaQuery;
use App\Services\Audit\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MembresiaController extends Controller
{
    public function __construct(
        private MembresiaQuery $membresiaQuery,
        private AuditService $auditService
    )
    {
    }

    public function catalogo()
    {
        $sedeId = request()->query('sede_id');

        return response()->json($this->membresiaQuery->listarCatalogo($sedeId ? (int) $sedeId : null));
    }

    public function storeCatalogo(Request $request)
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'descripcion' => ['nullable', 'string'],
            'duracion_dias' => ['required', 'integer', 'min:1'],
            'precio' => ['required', 'numeric', 'min:0'],
            'activa' => ['nullable', 'boolean'],
            'precios_sede' => ['nullable', 'array'],
            'precios_sede.*.sede_id' => ['required_with:precios_sede', 'integer'],
            'precios_sede.*.precio' => ['required_with:precios_sede', 'numeric', 'min:0'],
            'precios_sede.*.activa' => ['nullable', 'boolean'],
        ]);

        $id = DB::transaction(function () use ($data) {
            $id = DB::table('socios.membresias')->insertGetId([
                'nombre' => $data['nombre'],
                'descripcion' => $data['descripcion'] ?? null,
                'duracion_dias' => $data['duracion_dias'],
                'precio' => $data['precio'],
                'activa' => $data['activa'] ?? true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->syncPreciosSede($id, $data['precios_sede'] ?? []);

            return $id;
        });

        return response()->json([
            'message' => 'Membresía registrada exitosamente.',
            'id' => $id,
        ], 201);
    }

    public function updateCatalogo(Request $request, $id)
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'descripcion' => ['nullable', 'string'],
            'duracion_dias' => ['required', 'integer', 'min:1'],
            'precio' => ['required', 'numeric', 'min:0'],
            'activa' => ['required', 'boolean'],
            'precios_sede' => ['nullable', 'array'],
            'precios_sede.*.sede_id' => ['required_with:precios_sede', 'integer'],
            'precios_sede.*.precio' => ['required_with:precios_sede', 'numeric', 'min:0'],
            'precios_sede.*.activa' => ['nullable', 'boolean'],
        ]);

        $updated = DB::transaction(function () use ($id, $data) {
            $updated = DB::table('socios.membresias')
                ->where('id', $id)
                ->update([
                    'nombre' => $data['nombre'],
                    'descripcion' => $data['descripcion'] ?? null,
                    'duracion_dias' => $data['duracion_dias'],
                    'precio' => $data['precio'],
                    'activa' => $data['activa'],
                    'updated_at' => now(),
                ]);

            if ($updated) {
                $this->syncPreciosSede((int) $id, $data['precios_sede'] ?? []);
            }

            return $updated;
        });

        if (!$updated) {
            return response()->json([
                'message' => 'No se encontró la membresía a actualizar.',
            ], 404);
        }

        return response()->json([
            'message' => 'Membresía actualizada exitosamente.',
        ]);
    }

    public function precios(int $id)
    {
        if (!$this->hasPreciosSedeTable()) {
            return response()->json([]);
        }

        return response()->json($this->listarPreciosMembresia($id));
    }

    public function storePrecio(Request $request, int $id)
    {
        $data = $request->validate([
            'sede_id' => ['required', 'integer'],
            'precio' => ['required', 'numeric', 'min:0'],
            'vigencia_inicio' => ['nullable', 'date'],
            'vigencia_fin' => ['nullable', 'date', 'after_or_equal:vigencia_inicio'],
            'activa' => ['nullable', 'boolean'],
        ]);

        if (!$this->hasPreciosSedeTable()) {
            return response()->json(['message' => 'La tabla de precios por sede no está migrada.'], 422);
        }

        $exists = DB::table('socios.membresias')->where('id', $id)->exists();
        $sedeExists = DB::table('core.sedes')->where('id', $data['sede_id'])->where('activa', true)->exists();

        if (!$exists || !$sedeExists) {
            return response()->json(['message' => 'No se encontró la membresía o la sede seleccionada.'], 422);
        }

        $precioId = DB::transaction(function () use ($id, $data) {
            DB::table('socios.membresia_precios_sede')
                ->where('membresia_id', $id)
                ->where('sede_id', $data['sede_id'])
                ->where('activa', true)
                ->update([
                    'activa' => false,
                    'updated_at' => now(),
                ]);

            return DB::table('socios.membresia_precios_sede')->insertGetId([
                'membresia_id' => $id,
                'sede_id' => $data['sede_id'],
                'precio' => $data['precio'],
                'vigencia_inicio' => $data['vigencia_inicio'] ?? now()->toDateString(),
                'vigencia_fin' => $data['vigencia_fin'] ?? null,
                'activa' => $data['activa'] ?? true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $created = DB::table('socios.membresia_precios_sede')->where('id', $precioId)->first();
        $this->auditService->created($request, 'membresia_precios_sede', $precioId, $created, [
            'esquema' => 'socios',
            'modulo' => 'personas',
            'accion' => 'registrar_precio_membresia',
            'sede_id' => $data['sede_id'],
        ]);

        return response()->json($this->mapPrecioMembresia($created), 201);
    }

    public function updatePrecio(Request $request, int $id)
    {
        $data = $request->validate([
            'sede_id' => ['required', 'integer'],
            'precio' => ['required', 'numeric', 'min:0'],
            'vigencia_inicio' => ['nullable', 'date'],
            'vigencia_fin' => ['nullable', 'date', 'after_or_equal:vigencia_inicio'],
            'activa' => ['nullable', 'boolean'],
        ]);

        if (!$this->hasPreciosSedeTable()) {
            return response()->json(['message' => 'La tabla de precios por sede no está migrada.'], 422);
        }

        $before = DB::table('socios.membresia_precios_sede')->where('id', $id)->first();

        if (!$before) {
            return response()->json(['message' => 'Precio no encontrado.'], 404);
        }

        DB::table('socios.membresia_precios_sede')->where('id', $id)->update([
            'sede_id' => $data['sede_id'],
            'precio' => $data['precio'],
            'vigencia_inicio' => $data['vigencia_inicio'] ?? now()->toDateString(),
            'vigencia_fin' => $data['vigencia_fin'] ?? null,
            'activa' => $data['activa'] ?? true,
            'updated_at' => now(),
        ]);

        $after = DB::table('socios.membresia_precios_sede')->where('id', $id)->first();
        $this->auditService->updated($request, 'membresia_precios_sede', $id, $before, $after, [
            'esquema' => 'socios',
            'modulo' => 'personas',
            'accion' => 'actualizar_precio_membresia',
            'sede_id' => $data['sede_id'],
        ]);

        return response()->json($this->mapPrecioMembresia($after));
    }

    public function destroyPrecio(Request $request, int $id)
    {
        if (!$this->hasPreciosSedeTable()) {
            return response()->json(['message' => 'La tabla de precios por sede no está migrada.'], 422);
        }

        $before = DB::table('socios.membresia_precios_sede')->where('id', $id)->first();

        if (!$before) {
            return response()->json(['message' => 'Precio no encontrado.'], 404);
        }

        DB::table('socios.membresia_precios_sede')->where('id', $id)->update([
            'activa' => false,
            'updated_at' => now(),
        ]);

        $after = DB::table('socios.membresia_precios_sede')->where('id', $id)->first();
        $this->auditService->deleted($request, 'membresia_precios_sede', $id, $before, $after, [
            'esquema' => 'socios',
            'modulo' => 'personas',
            'accion' => 'desactivar_precio_membresia',
            'sede_id' => $after->sede_id,
        ]);

        return response()->json([
            'message' => 'Precio desactivado correctamente.',
            'data' => $this->mapPrecioMembresia($after),
        ]);
    }

    private function syncPreciosSede(int $membresiaId, array $preciosSede): void
    {
        if (!$this->hasPreciosSedeTable()) {
            return;
        }

        $sedeIds = [];

        foreach ($preciosSede as $precioSede) {
            if (empty($precioSede['sede_id']) || !array_key_exists('precio', $precioSede)) {
                continue;
            }

            $sedeId = (int) $precioSede['sede_id'];
            $sedeIds[] = $sedeId;

            DB::table('socios.membresia_precios_sede')->updateOrInsert(
                [
                    'membresia_id' => $membresiaId,
                    'sede_id' => $sedeId,
                ],
                [
                    'precio' => $precioSede['precio'],
                    'activa' => $precioSede['activa'] ?? true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        DB::table('socios.membresia_precios_sede')
            ->where('membresia_id', $membresiaId)
            ->when($sedeIds, fn ($query) => $query->whereNotIn('sede_id', $sedeIds))
            ->delete();
    }

    private function hasPreciosSedeTable(): bool
    {
        $row = DB::selectOne("SELECT to_regclass('socios.membresia_precios_sede') as table_name");

        return !empty($row?->table_name);
    }

    private function listarPreciosMembresia(int $membresiaId): array
    {
        return DB::table('socios.membresia_precios_sede as mps')
            ->join('core.sedes as s', 's.id', '=', 'mps.sede_id')
            ->where('mps.membresia_id', $membresiaId)
            ->select('mps.*', 's.nombre as sede_nombre')
            ->orderByDesc('mps.activa')
            ->orderBy('s.nombre')
            ->orderByDesc('mps.vigencia_inicio')
            ->orderByDesc('mps.id')
            ->get()
            ->map(fn ($row) => $this->mapPrecioMembresia($row))
            ->all();
    }

    private function mapPrecioMembresia(object $row): array
    {
        $sedeNombre = $row->sede_nombre
            ?? DB::table('core.sedes')->where('id', $row->sede_id)->value('nombre');

        return [
            'id' => (int) $row->id,
            'membresia_id' => (int) $row->membresia_id,
            'sede_id' => (int) $row->sede_id,
            'sede_nombre' => $sedeNombre,
            'precio' => (float) $row->precio,
            'vigencia_inicio' => $row->vigencia_inicio,
            'vigencia_fin' => $row->vigencia_fin,
            'activa' => (bool) $row->activa,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    public function asignaciones(Request $request)
    {
        $filtros = $request->only(['buscar', 'sede_id', 'membresia_id']);
        return response()->json($this->membresiaQuery->listarAsignaciones($filtros));
    }

    public function socios()
    {
        return response()->json($this->membresiaQuery->listarSociosDisponibles());
    }

    public function storeAsignacion(Request $request)
    {
        $data = $request->validate([
            'socio_id' => ['required', 'integer'],
            'membresia_id' => ['required', 'integer'],
            'sede_id' => ['nullable', 'integer'],
            'precio_aplicado' => ['nullable', 'numeric', 'min:0'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after_or_equal:fecha_inicio'],
            'estado_id' => ['nullable', 'integer'],
        ]);

        $socio = DB::table('socios.socios as s')
            ->join('core.personas as p', 'p.id', '=', 's.persona_id')
            ->where('s.id', $data['socio_id'])
            ->select('p.numero_identificacion as cedula')
            ->first();

        $existsMembresia = DB::table('socios.membresias')->where('id', $data['membresia_id'])->exists();

        if (!$socio || !$existsMembresia) {
            return response()->json([
                'message' => 'No se encontró el socio o la membresía seleccionada.',
            ], 422);
        }

        $estadoId = $data['estado_id']
            ?? DB::table('core.estados')->where('codigo', 'ACTIVO')->value('id');

        $id = DB::table('socios.socio_membresias')->insertGetId([
            'socio_id' => $data['socio_id'],
            'membresia_id' => $data['membresia_id'],
            'sede_id' => $data['sede_id'] ?? null,
            'fecha_inicio' => $data['fecha_inicio'],
            'fecha_fin' => $data['fecha_fin'],
            'precio_aplicado' => $data['precio_aplicado'] ?? null,
            'estado_id' => $estadoId,
            'cedula' => $socio->cedula,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Asignación de membresía registrada exitosamente.',
            'id' => $id,
        ], 201);
    }

    public function storeAsignacionLote(Request $request)
    {
        $data = $request->validate([
            'persona_ids' => ['required', 'array', 'min:1'],
            'persona_ids.*' => ['integer'],
            'membresia_id' => ['required', 'integer'],
            'sede_id' => ['required', 'integer'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after_or_equal:fecha_inicio'],
            'precio_aplicado' => ['required', 'numeric', 'min:0'],
            'modo_conflicto' => ['nullable', 'string', 'in:RENOVAR,REEMPLAZAR'],
        ]);

        $membresia = DB::table('socios.membresias')->where('id', $data['membresia_id'])->first();
        $sedeExists = DB::table('core.sedes')->where('id', $data['sede_id'])->where('activa', true)->exists();

        if (!$membresia || !$sedeExists) {
            return response()->json([
                'message' => 'No se encontró la membresía o la sede seleccionada.',
            ], 422);
        }

        $estadoActivoId = DB::table('core.estados')->where('codigo', 'ACTIVO')->value('id');
        $estadoInactivoId = DB::table('core.estados')->whereIn('codigo', ['INACTIVO', 'ANULADO'])->orderBy('id')->value('id');
        $modo = $data['modo_conflicto'] ?? 'RENOVAR';
        $createdIds = [];

        DB::transaction(function () use ($data, $estadoActivoId, $estadoInactivoId, $modo, &$createdIds) {
            foreach (array_unique($data['persona_ids']) as $personaId) {
                // Find or create socio
                $socio = DB::table('socios.socios as s')
                    ->join('core.personas as p', 'p.id', '=', 's.persona_id')
                    ->where('s.persona_id', $personaId)
                    ->select('s.id', 'p.numero_identificacion as cedula')
                    ->first();

                if (!$socio) {
                    $persona = DB::table('core.personas')->where('id', $personaId)->first();
                    if (!$persona) continue;

                    $ultimoSocioId = DB::table('socios.socios')->max('id') ?? 0;
                    $codigoSocio = 'SOC-' . str_pad($ultimoSocioId + 1, 4, '0', STR_PAD_LEFT);

                    $newSocioId = DB::table('socios.socios')->insertGetId([
                        'persona_id' => $personaId,
                        'sede_id' => $data['sede_id'],
                        'codigo_socio' => $codigoSocio,
                        'fecha_alta' => now(),
                        'estado_id' => $estadoActivoId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $socio = (object)[
                        'id' => $newSocioId,
                        'cedula' => $persona->numero_identificacion
                    ];
                }

                $fechaInicio = $data['fecha_inicio'];
                $fechaFin = $data['fecha_fin'];

                $vigente = DB::table('socios.socio_membresias')
                    ->where('socio_id', $socio->id)
                    ->where('fecha_fin', '>=', $data['fecha_inicio'])
                    ->orderByDesc('fecha_fin')
                    ->orderByDesc('id')
                    ->first();

                if ($vigente && $modo === 'RENOVAR') {
                    $fechaInicio = date('Y-m-d', strtotime($vigente->fecha_fin . ' +1 day'));
                    $dias = max(1, (int) ((strtotime($data['fecha_fin']) - strtotime($data['fecha_inicio'])) / 86400) + 1);
                    $fechaFin = date('Y-m-d', strtotime($fechaInicio . ' +' . ($dias - 1) . ' days'));
                }

                if ($vigente && $modo === 'REEMPLAZAR') {
                    DB::table('socios.socio_membresias')
                        ->where('id', $vigente->id)
                        ->update([
                            'estado_id' => $estadoInactivoId ?: $vigente->estado_id,
                            'fecha_fin' => date('Y-m-d', strtotime($data['fecha_inicio'] . ' -1 day')),
                            'updated_at' => now(),
                        ]);
                }

                $createdIds[] = DB::table('socios.socio_membresias')->insertGetId([
                    'socio_id' => $socio->id,
                    'membresia_id' => $data['membresia_id'],
                    'sede_id' => $data['sede_id'],
                    'fecha_inicio' => $fechaInicio,
                    'fecha_fin' => $fechaFin,
                    'precio_aplicado' => $data['precio_aplicado'],
                    'estado_id' => $estadoActivoId,
                    'cedula' => $socio->cedula,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $this->auditService->activity($request, 'personas', 'asignar_membresias_lote', [
            'tabla' => 'socio_membresias',
            'esquema' => 'socios',
            'sede_id' => $data['sede_id'],
            'datos_despues' => [
                'membresia_id' => $data['membresia_id'],
                'persona_ids' => $data['persona_ids'],
                'asignacion_ids' => $createdIds,
                'modo_conflicto' => $modo,
            ],
        ]);

        return response()->json([
            'message' => count($createdIds) . ' asignación(es) creadas correctamente.',
            'ids' => $createdIds,
        ], 201);
    }

    public function updateAsignacion(Request $request, $id)
    {
        $data = $request->validate([
            'socio_id' => ['required', 'integer'],
            'membresia_id' => ['required', 'integer'],
            'sede_id' => ['nullable', 'integer'],
            'precio_aplicado' => ['nullable', 'numeric', 'min:0'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after_or_equal:fecha_inicio'],
            'estado_id' => ['nullable', 'integer'],
        ]);

        $socio = DB::table('socios.socios as s')
            ->join('core.personas as p', 'p.id', '=', 's.persona_id')
            ->where('s.id', $data['socio_id'])
            ->select('p.numero_identificacion as cedula')
            ->first();

        $updated = DB::table('socios.socio_membresias')
            ->where('id', $id)
            ->update([
                'socio_id' => $data['socio_id'],
                'membresia_id' => $data['membresia_id'],
                'sede_id' => $data['sede_id'] ?? null,
                'fecha_inicio' => $data['fecha_inicio'],
                'fecha_fin' => $data['fecha_fin'],
                'precio_aplicado' => $data['precio_aplicado'] ?? null,
                'estado_id' => $data['estado_id']
                    ?? DB::table('core.estados')->where('codigo', 'ACTIVO')->value('id'),
                'cedula' => $socio ? $socio->cedula : null,
                'updated_at' => now(),
            ]);

        if (!$updated) {
            return response()->json([
                'message' => 'No se encontró la asignación de membresía a actualizar.',
            ], 404);
        }

        return response()->json([
            'message' => 'Asignación de membresía actualizada exitosamente.',
        ]);
    }

    public function destroyAsignacion($id)
    {
        $deleted = DB::table('socios.socio_membresias')->where('id', $id)->delete();

        if (!$deleted) {
            return response()->json([
                'message' => 'No se encontró la asignación de membresía a eliminar.',
            ], 404);
        }

        return response()->json([
            'message' => 'Asignación de membresía eliminada exitosamente.',
        ]);
    }
}
