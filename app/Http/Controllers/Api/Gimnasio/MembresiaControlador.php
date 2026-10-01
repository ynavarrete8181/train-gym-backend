<?php

namespace App\Http\Controllers\Api\Gimnasio;

use App\Http\Controllers\Controller;
use App\Services\Gimnasio\MembresiaServicio;
use App\Services\Ventas\VentaServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MembresiaControlador extends Controller
{
    public function __construct(
        protected MembresiaServicio $membresiaServicio,
        protected VentaServicio $ventaServicio,
    ) {
    }

    public function index(Request $request)
    {
        $porPagina = $request->input('per_page', 5);
        $pagina = $request->input('page', 1);
        $busqueda = $request->input('busqueda');
        $codigo = $request->input('codigo');
        $cliente = $request->input('cliente');
        $plan = $request->input('plan');
        $estado = $request->input('estado');

        $consulta = DB::table('membresias.membresias')
            ->join('clientes.deportistas', 'membresias.membresias.deportista_id', '=', 'clientes.deportistas.id')
            ->leftJoin('personas.personas as p', 'clientes.deportistas.persona_id', '=', 'p.id')
            ->leftJoin('seguridad.users', 'clientes.deportistas.usuario_id', '=', 'seguridad.users.id')
            ->join('membresias.planes', 'membresias.membresias.plan_id', '=', 'membresias.planes.id')
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
                'institucional.sedes.nombre as sede_nombre',
                'estado_cfg.codigo as estado_codigo',
                'estado_cfg.valor_interno as estado_valor',
                'estado_cfg.nombre as estado_nombre',
                'estado_cfg.color as estado_color'
            );

        if ($request->has('deportista_id')) {
            $consulta->where('membresias.membresias.deportista_id', $request->deportista_id);
        }

        if (!empty($busqueda)) {
            $busqueda = mb_strtolower($busqueda);
            $consulta->where(function ($q) use ($busqueda) {
                $q->whereRaw('LOWER(membresias.membresias.codigo_contrato) LIKE ?', ["%{$busqueda}%"])
                  ->orWhereRaw('LOWER(COALESCE(p.nombre_completo, seguridad.users.name)) LIKE ?', ["%{$busqueda}%"])
                  ->orWhereRaw('LOWER(COALESCE(p.email, seguridad.users.email)) LIKE ?', ["%{$busqueda}%"])
                  ->orWhereRaw('LOWER(clientes.deportistas.codigo_deportista) LIKE ?', ["%{$busqueda}%"])
                  ->orWhereRaw('LOWER(membresias.planes.nombre) LIKE ?', ["%{$busqueda}%"])
                  ->orWhereRaw('LOWER(COALESCE(estado_cfg.nombre, membresias.membresias.estado)) LIKE ?', ["%{$busqueda}%"]);
            });
        }

        $this->aplicarFiltro($consulta, 'membresias.membresias.codigo_contrato', $codigo);
        $this->aplicarFiltro($consulta, 'seguridad.users.name', $cliente);
        $this->aplicarFiltro($consulta, 'membresias.planes.nombre', $plan);
        $this->aplicarFiltro($consulta, 'membresias.membresias.estado', $estado);

        $membresias = $consulta
            ->orderBy('membresias.membresias.created_at', 'desc')
            ->paginate($porPagina, ['*'], 'page', $pagina);

        $items = collect($membresias->items())
            ->map(fn ($membresia) => $this->membresiaServicio->obtenerMembresiaConRelaciones((int) $membresia->id))
            ->filter()
            ->values();

        return ApiResponse::exito('Membresías consultadas', $items, [
            'pagina_actual' => $membresias->currentPage(),
            'por_pagina' => $membresias->perPage(),
            'total' => $membresias->total(),
            'ultima_pagina' => $membresias->lastPage(),
            'opciones_filtro' => $this->opcionesFiltro(),
            'estados' => $this->estadosMembresia(),
        ]);
    }

    public function store(Request $request)
    {
        $validados = $request->validate([
            'deportista_id' => 'required|exists:pgsql.clientes.deportistas,id',
            'plan_id' => 'required|exists:pgsql.membresias.planes,id',
            'sede_id' => 'nullable|exists:pgsql.institucional.sedes,id_sede',
            'sedes_habilitadas' => 'required|array|min:1',
            'sedes_habilitadas.*' => 'required|integer|distinct|exists:pgsql.institucional.sedes,id_sede',
            'asignaciones_entrenador' => 'nullable|array',
            'asignaciones_entrenador.*.sede_id' => 'required|integer|exists:pgsql.institucional.sedes,id_sede',
            'asignaciones_entrenador.*.entrenador_id' => 'required|integer|exists:pgsql.entrenamiento.entrenadores,id',
            'asignaciones_entrenador.*.horario_bloque_id' => 'required|integer|exists:pgsql.agenda.horario_bloques,id',
            'fecha_inicio' => 'required|date',
            'dias_gracia' => 'integer|min:0',
            'dia_pago' => 'nullable|integer|min:1|max:31|required_if:generar_venta_automatica,true',
            'generar_venta_automatica' => 'boolean',
            'renovacion_automatica' => 'boolean',
            'fecha_congelacion_inicio' => 'nullable|required_with:fecha_congelacion_fin|date',
            'fecha_congelacion_fin' => 'nullable|required_with:fecha_congelacion_inicio|date|after_or_equal:fecha_congelacion_inicio',
            'generar_venta' => 'boolean',
        ]);

        $validados['sede_id'] = (int) collect($validados['sedes_habilitadas'])->first();
        $this->validarDeportistaActual((int) $validados['deportista_id']);
        $this->validarConfiguracionMembresia((int) $validados['plan_id'], (int) $validados['sede_id'], $validados['sedes_habilitadas'], $validados['asignaciones_entrenador'] ?? []);
        $generarVenta = (bool) ($validados['generar_venta'] ?? false);
        unset($validados['generar_venta']);

        [$membresia, $venta] = DB::transaction(function () use ($validados, $generarVenta, $request): array {
            $membresia = $this->membresiaServicio->crear($validados);
            $venta = null;
            $plan = DB::table('membresias.planes')->where('id', $membresia->plan_id)->first();

            if ($generarVenta && ($plan->generar_venta ?? true)) {
                $venta = $this->ventaServicio->guardarVenta([
                    'cliente_id' => $membresia->deportista_id,
                    'membresia_id' => $membresia->id,
                    'caja_id' => null,
                    'tipo_venta' => ($plan->tipo_producto ?? 'MEMBRESIA') === 'PASE_DIARIO' ? 'SERVICIO' : 'MEMBRESIA',
                    'concepto' => $plan->nombre . ' - ' . $membresia->codigo_contrato,
                    'subtotal' => $membresia->precio_aplicado,
                    'descuento' => 0,
                    'impuesto' => 0,
                    'total' => $membresia->precio_aplicado,
                    'estado' => 'PENDIENTE',
                    'observaciones' => 'Venta generada automáticamente desde Membresías.',
                    'detalle' => [
                        'tipo_item' => 'MEMBRESIA',
                        'referencia_id' => (int) $plan->id,
                        'descripcion' => $plan->nombre,
                        'cantidad' => 1,
                        'precio_unitario' => $membresia->precio_aplicado,
                        'total_linea' => $membresia->precio_aplicado,
                    ],
                ], null, $request->user()?->id);

                $periodoId = DB::table('membresias.membresia_periodos')
                    ->where('membresia_id', $membresia->id)
                    ->orderByDesc('numero_periodo')
                    ->value('id');

                if ($periodoId) {
                    $this->membresiaServicio->vincularVentaPeriodo((int) $periodoId, (int) $venta->id);
                }
            }

            return [$membresia, $venta];
        });

        $respuesta = (array) $membresia;
        if ($venta) {
            $respuesta['venta_id'] = $venta->id;
            $respuesta['venta_numero'] = $venta->numero;
        }

        return ApiResponse::exito(
            $venta ? 'Membresía creada y venta generada correctamente.' : 'Membresía creada correctamente.',
            $respuesta,
            [],
            201
        );
    }

    public function renovar(Request $request, int $id)
    {
        $membresia = DB::table('membresias.membresias')->where('id', $id)->first();
        if (! $membresia) {
            return response()->json(['mensaje' => 'Membresía no encontrada'], 404);
        }

        $plan = DB::table('membresias.planes')->where('id', $membresia->plan_id)->first();
        if (! $plan || ! ($plan->renovable ?? false)) {
            throw ValidationException::withMessages([
                'membresia_id' => 'El plan de esta membresía no permite renovación.',
            ]);
        }

        if (in_array(strtoupper((string) $membresia->estado), ['CANCELADA'], true)) {
            throw ValidationException::withMessages([
                'membresia_id' => 'Una membresía cancelada no puede renovarse.',
            ]);
        }

        $periodoActual = DB::table('membresias.membresia_periodos')
            ->where('membresia_id', $id)
            ->orderByDesc('numero_periodo')
            ->first();

        if ($periodoActual?->venta_id) {
            $ventaActual = DB::table('ventas.ventas')->where('id', $periodoActual->venta_id)->first();

            if ($ventaActual && ! in_array(strtoupper((string) $ventaActual->estado), ['PAGADA', 'ANULADA'], true)) {
                throw ValidationException::withMessages([
                    'membresia_id' => 'El período actual todavía tiene un cobro pendiente. Debe pagarse antes de generar la siguiente renovación.',
                ]);
            }
        }

        [$periodo, $venta] = DB::transaction(function () use ($id, $plan, $request): array {
            $periodo = $this->membresiaServicio->crearSiguientePeriodo($id);
            $membresiaActualizada = DB::table('membresias.membresias')->where('id', $id)->first();
            $venta = null;

            if ($plan->generar_venta ?? true) {
                $venta = $this->ventaServicio->guardarVenta([
                    'cliente_id' => $membresiaActualizada->deportista_id,
                    'membresia_id' => $id,
                    'caja_id' => null,
                    'tipo_venta' => 'MEMBRESIA',
                    'concepto' => $plan->nombre
                        . ' - ' . $membresiaActualizada->codigo_contrato
                        . ' - Período ' . $periodo->numero_periodo
                        . ' (' . $periodo->fecha_inicio . ' al ' . $periodo->fecha_fin . ')',
                    'subtotal' => $periodo->precio,
                    'descuento' => 0,
                    'impuesto' => 0,
                    'total' => $periodo->precio,
                    'estado' => 'PENDIENTE',
                    'observaciones' => 'Cobro generado por renovación de membresía.',
                    'detalle' => [
                        'tipo_item' => 'MEMBRESIA',
                        'referencia_id' => (int) $plan->id,
                        'descripcion' => $plan->nombre . ' - Período ' . $periodo->numero_periodo,
                        'cantidad' => 1,
                        'precio_unitario' => $periodo->precio,
                        'total_linea' => $periodo->precio,
                    ],
                ], null, $request->user()?->id);

                $this->membresiaServicio->vincularVentaPeriodo((int) $periodo->id, (int) $venta->id);
            }

            return [$periodo, $venta];
        });

        $respuesta = [
            'membresia' => $this->membresiaServicio->obtenerMembresiaConRelaciones($id),
            'periodo' => $periodo,
            'venta_id' => $venta?->id,
            'venta_numero' => $venta?->numero,
        ];

        return ApiResponse::exito(
            $venta
                ? 'Membresía renovada y nuevo cobro generado.'
                : 'Membresía renovada correctamente.',
            $respuesta
        );
    }

    public function show($id)
    {
        $membresia = $this->membresiaServicio->obtenerMembresiaConRelaciones($id);
        if (!$membresia) return response()->json(['mensaje' => 'Membresía no encontrada'], 404);
        return ApiResponse::exito('Membresía consultada.', (array) $membresia);
    }

    public function update(Request $request, $id)
    {
        $membresia = DB::table('membresias.membresias')->where('id', $id)->first();
        if (!$membresia) return response()->json(['mensaje' => 'Membresía no encontrada'], 404);

        if ($request->filled('estado')) {
            $request->merge(['estado' => strtoupper((string) $request->input('estado'))]);
        }

        $validados = $request->validate([
            'sede_id' => 'nullable|exists:pgsql.institucional.sedes,id_sede',
            'sedes_habilitadas' => 'required|array|min:1',
            'sedes_habilitadas.*' => 'required|integer|distinct|exists:pgsql.institucional.sedes,id_sede',
            'asignaciones_entrenador' => 'nullable|array',
            'asignaciones_entrenador.*.sede_id' => 'required|integer|exists:pgsql.institucional.sedes,id_sede',
            'asignaciones_entrenador.*.entrenador_id' => 'required|integer|exists:pgsql.entrenamiento.entrenadores,id',
            'asignaciones_entrenador.*.horario_bloque_id' => 'required|integer|exists:pgsql.agenda.horario_bloques,id',
            'fecha_inicio' => 'required|date',
            'estado' => [
                'required',
                'string',
                Rule::exists('configuracion.estados_catalogo', 'valor_interno')
                    ->where(fn ($q) => $q->where('entidad', 'MEMBRESIA')->where('activo', true)),
            ],
            'dias_gracia' => 'integer|min:0',
            'dia_pago' => 'nullable|integer|min:1|max:31|required_if:generar_venta_automatica,true',
            'generar_venta_automatica' => 'boolean',
            'renovacion_automatica' => 'boolean',
            'generar_venta' => 'boolean',
            'fecha_congelacion_inicio' => 'nullable|required_with:fecha_congelacion_fin|date',
            'fecha_congelacion_fin' => 'nullable|required_with:fecha_congelacion_inicio|date|after_or_equal:fecha_congelacion_inicio',
        ]);

        $generarVenta = array_key_exists('generar_venta', $validados) ? (bool) $validados['generar_venta'] : null;
        unset($validados['generar_venta']);

        $sedesHabilitadas = collect($validados['sedes_habilitadas'])->map(fn ($id) => (int) $id)->values();
        $sedeActual = (int) ($membresia->sede_id ?? 0);
        $validados['sede_id'] = $sedeActual && $sedesHabilitadas->contains($sedeActual)
            ? $sedeActual
            : (int) $sedesHabilitadas->first();
        $sedeValidacion = (int) $validados['sede_id'];
        $this->validarConfiguracionMembresia(
            (int) $membresia->plan_id,
            $sedeValidacion,
            $validados['sedes_habilitadas'],
            $validados['asignaciones_entrenador'] ?? []
        );

        $membresiaActualizada = DB::transaction(function () use ($id, $validados, $generarVenta, $request) {
            $actualizada = $this->membresiaServicio->actualizar($id, $validados);

            if ($generarVenta !== null) {
                $this->sincronizarFacturacion((int) $id, $generarVenta, $request);
                $actualizada = $this->membresiaServicio->obtenerMembresiaConRelaciones((int) $id);
            }

            return $actualizada;
        });

        return ApiResponse::exito('Membresía actualizada correctamente.', (array) $membresiaActualizada);
    }

    private function sincronizarFacturacion(int $membresiaId, bool $generarVenta, Request $request): void
    {
        $membresia = DB::table('membresias.membresias')->where('id', $membresiaId)->first();
        $plan = DB::table('membresias.planes')->where('id', $membresia->plan_id)->first();

        $venta = DB::table('ventas.ventas')
            ->where('membresia_id', $membresiaId)
            ->where('estado', '!=', 'ANULADA')
            ->orderByDesc('id')
            ->first();

        if ($generarVenta) {
            if ($venta || ! ($plan->generar_venta ?? true)) {
                return;
            }

            $ventaCreada = $this->ventaServicio->guardarVenta([
                'cliente_id' => $membresia->deportista_id,
                'membresia_id' => $membresiaId,
                'caja_id' => null,
                'tipo_venta' => ($plan->tipo_producto ?? 'MEMBRESIA') === 'PASE_DIARIO' ? 'SERVICIO' : 'MEMBRESIA',
                'concepto' => $plan->nombre . ' - ' . $membresia->codigo_contrato,
                'subtotal' => $membresia->precio_aplicado,
                'descuento' => 0,
                'impuesto' => 0,
                'total' => $membresia->precio_aplicado,
                'estado' => 'PENDIENTE',
                'observaciones' => 'Venta generada desde edición de Membresías.',
                'detalle' => [
                    'tipo_item' => 'MEMBRESIA',
                    'referencia_id' => (int) $plan->id,
                    'descripcion' => $plan->nombre,
                    'cantidad' => 1,
                    'precio_unitario' => $membresia->precio_aplicado,
                    'total_linea' => $membresia->precio_aplicado,
                ],
            ], null, $request->user()?->id);

            $periodoId = DB::table('membresias.membresia_periodos')
                ->where('membresia_id', $membresiaId)
                ->orderByDesc('numero_periodo')
                ->value('id');

            if ($periodoId) {
                $this->membresiaServicio->vincularVentaPeriodo((int) $periodoId, (int) $ventaCreada->id);
            }

            return;
        }

        if (! $venta) {
            return;
        }

        $pagado = (float) DB::table('ventas.pagos')
            ->where('venta_id', $venta->id)
            ->where('estado', 'CONFIRMADO')
            ->sum('monto');

        if ($pagado > 0 || in_array(strtoupper((string) $venta->estado), ['PARCIAL', 'PAGADA'], true)) {
            throw ValidationException::withMessages([
                'generar_venta' => 'No se puede desactivar la facturación porque la venta ya tiene pagos confirmados.',
            ]);
        }

        $estadoAnuladaId = DB::table('configuracion.estados_catalogo')
            ->where('entidad', 'VENTA')
            ->where('valor_interno', 'ANULADA')
            ->where('activo', true)
            ->value('id');

        DB::table('ventas.ventas')->where('id', $venta->id)->update([
            'estado' => 'ANULADA',
            'estado_id' => $estadoAnuladaId,
            'observaciones' => trim(($venta->observaciones ? $venta->observaciones . ' ' : '') . 'Facturación desactivada desde Membresías.'),
            'updated_at' => now(),
        ]);

        DB::table('ventas.comprobantes')->where('venta_id', $venta->id)->update([
            'estado' => 'ANULADO',
            'updated_at' => now(),
        ]);
    }

    public function destroy($id)
    {
        $membresia = DB::table('membresias.membresias')->where('id', $id)->first();
        if (!$membresia) return response()->json(['mensaje' => 'Membresía no encontrada'], 404);
        $this->membresiaServicio->eliminar((int) $id);
        return ApiResponse::exito('Membresía eliminada correctamente.');
    }

    private function validarConfiguracionMembresia(int $planId, int $sedePrincipalId, array $sedesHabilitadas, array $asignaciones): void
    {
        $sedes = collect($sedesHabilitadas)->map(fn ($id) => (int) $id)->unique()->values();

        if (! $sedes->contains($sedePrincipalId)) {
            throw ValidationException::withMessages([
                'sedes_habilitadas' => 'La sede de contratación debe estar incluida entre las sedes habilitadas.',
            ]);
        }

        $plan = DB::table('membresias.planes')->where('id', $planId)->first();
        if (! $plan) {
            throw ValidationException::withMessages(['plan_id' => 'El plan seleccionado no existe.']);
        }

        if (($plan->requiere_entrenador ?? false) && empty($asignaciones)) {
            throw ValidationException::withMessages([
                'asignaciones_entrenador' => 'Este plan requiere al menos una asignación de entrenador.',
            ]);
        }

        foreach ($asignaciones as $indice => $asignacion) {
            $sedeId = (int) ($asignacion['sede_id'] ?? 0);
            $entrenadorId = (int) ($asignacion['entrenador_id'] ?? 0);
            $horarioBloqueId = (int) ($asignacion['horario_bloque_id'] ?? 0);

            if (! $sedes->contains($sedeId)) {
                throw ValidationException::withMessages([
                    "asignaciones_entrenador.{$indice}.sede_id" => 'La asignación debe pertenecer a una sede habilitada en la membresía.',
                ]);
            }

            $valido = DB::table('entrenamiento.entrenadores as e')
                ->leftJoin('seguridad.users as u', 'u.id', '=', 'e.usuario_id')
                ->leftJoin('seguridad.cpu_userrole as r', 'r.id_userrole', '=', 'u.usr_tipo')
                ->join('agenda.horario_entrenadores as he', function ($join) use ($horarioBloqueId): void {
                    $join->on('he.entrenador_id', '=', 'e.id')
                        ->where('he.horario_bloque_id', '=', $horarioBloqueId)
                        ->where('he.activo', '=', true);
                })
                ->join('agenda.horarios_servicio as hs', function ($join) use ($sedeId, $horarioBloqueId): void {
                    $join->on('hs.horario_bloque_id', '=', 'he.horario_bloque_id')
                        ->where('hs.horario_bloque_id', '=', $horarioBloqueId)
                        ->where('hs.sede_id', '=', $sedeId)
                        ->where('hs.activo', '=', true);
                })
                ->where('e.id', $entrenadorId)
                ->where('e.estado', 'ACTIVO')
                ->where('r.role', 'ENTRENADOR')
                ->exists();

            if (! $valido) {
                throw ValidationException::withMessages([
                    "asignaciones_entrenador.{$indice}.entrenador_id" => 'El entrenador y horario seleccionados no están disponibles en esa sede.',
                ]);
            }
        }
    }

    private function validarDeportistaActual(int $deportistaId): void
    {
        $deportista = DB::table('clientes.deportistas')
            ->where('id', $deportistaId)
            ->first(['id', 'usuario_id', 'estado']);

        if (! $deportista) {
            throw ValidationException::withMessages([
                'deportista_id' => 'El cliente seleccionado no existe.',
            ]);
        }

        if (strtoupper((string) $deportista->estado) === 'INACTIVO') {
            throw ValidationException::withMessages([
                'deportista_id' => 'No se puede asignar una membresía a un cliente inactivo.',
            ]);
        }

        if (! $deportista->usuario_id) {
            return;
        }

        $rol = DB::table('seguridad.users')
            ->join('seguridad.cpu_userrole', 'seguridad.cpu_userrole.id_userrole', '=', 'seguridad.users.usr_tipo')
            ->where('seguridad.users.id', $deportista->usuario_id)
            ->value('seguridad.cpu_userrole.role');

        if ($rol !== 'DEPORTISTA') {
            throw ValidationException::withMessages([
                'deportista_id' => 'La cuenta asociada al cliente debe tener rol Deportista.',
            ]);
        }
    }

    private function aplicarFiltro($query, string $columna, mixed $valor): void
    {
        if (empty($valor)) return;
        $valores = is_array($valor) ? array_filter($valor) : [$valor];
        if (empty($valores)) return;
        if (is_array($valor)) { $query->whereIn($columna, $valores); return; }
        $texto = mb_strtolower($valor);
        $query->whereRaw("LOWER({$columna}) LIKE ?", ["%{$texto}%"]);
    }

    private function opcionesFiltro(): array
    {
        $base = DB::table('membresias.membresias')
            ->join('clientes.deportistas', 'membresias.membresias.deportista_id', '=', 'clientes.deportistas.id')
            ->leftJoin('personas.personas as p', 'clientes.deportistas.persona_id', '=', 'p.id')
            ->leftJoin('seguridad.users', 'clientes.deportistas.usuario_id', '=', 'seguridad.users.id')
            ->join('membresias.planes', 'membresias.membresias.plan_id', '=', 'membresias.planes.id')
            ->leftJoin('institucional.sedes', 'membresias.membresias.sede_id', '=', 'institucional.sedes.id_sede');

        return [
            'codigo' => (clone $base)->whereNotNull('codigo_contrato')->distinct()->orderBy('codigo_contrato')->pluck('codigo_contrato')->values(),
            'cliente' => (clone $base)
                ->selectRaw('COALESCE(p.nombre_completo, seguridad.users.name) as cliente_nombre')
                ->whereRaw('COALESCE(p.nombre_completo, seguridad.users.name) IS NOT NULL')
                ->distinct()
                ->orderBy('cliente_nombre')
                ->pluck('cliente_nombre')
                ->values(),
            'plan' => (clone $base)->whereNotNull('membresias.planes.nombre')->distinct()->orderBy('membresias.planes.nombre')->pluck('membresias.planes.nombre')->values(),
            'estado' => $this->estadosMembresia()->pluck('valor_interno')->values(),
        ];
    }

    private function estadosMembresia()
    {
        return DB::table('configuracion.estados_catalogo')
            ->where('entidad', 'MEMBRESIA')
            ->where('activo', true)
            ->orderBy('orden')
            ->get(['id', 'codigo', 'valor_interno', 'nombre', 'color', 'es_inicial', 'es_final']);
    }
}
