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

        $consulta = DB::table('gimnasio.membresias')
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
                'institucional.sedes.nombre as sede_nombre',
                'entrenador_user.name as entrenador_nombre',
                'entrenador_user.nombres as entrenador_nombres',
                'entrenador_user.apellidos as entrenador_apellidos',
                'estado_cfg.codigo as estado_codigo',
                'estado_cfg.valor_interno as estado_valor',
                'estado_cfg.nombre as estado_nombre',
                'estado_cfg.color as estado_color'
            );

        if ($request->has('deportista_id')) {
            $consulta->where('gimnasio.membresias.deportista_id', $request->deportista_id);
        }

        if (!empty($busqueda)) {
            $busqueda = mb_strtolower($busqueda);
            $consulta->where(function ($q) use ($busqueda) {
                $q->whereRaw('LOWER(gimnasio.membresias.codigo_contrato) LIKE ?', ["%{$busqueda}%"])
                  ->orWhereRaw('LOWER(seguridad.users.name) LIKE ?', ["%{$busqueda}%"])
                  ->orWhereRaw('LOWER(seguridad.users.email) LIKE ?', ["%{$busqueda}%"])
                  ->orWhereRaw('LOWER(gimnasio.deportistas.codigo_deportista) LIKE ?', ["%{$busqueda}%"])
                  ->orWhereRaw('LOWER(gimnasio.planes.nombre) LIKE ?', ["%{$busqueda}%"])
                  ->orWhereRaw('LOWER(COALESCE(estado_cfg.nombre, gimnasio.membresias.estado)) LIKE ?', ["%{$busqueda}%"]);
            });
        }

        $this->aplicarFiltro($consulta, 'gimnasio.membresias.codigo_contrato', $codigo);
        $this->aplicarFiltro($consulta, 'seguridad.users.name', $cliente);
        $this->aplicarFiltro($consulta, 'gimnasio.planes.nombre', $plan);
        $this->aplicarFiltro($consulta, 'gimnasio.membresias.estado', $estado);

        $membresias = $consulta
            ->orderBy('gimnasio.membresias.created_at', 'desc')
            ->paginate($porPagina, ['*'], 'page', $pagina);

        return ApiResponse::exito('Membresías consultadas', $membresias->items(), [
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
            'deportista_id' => 'required|exists:pgsql.gimnasio.deportistas,id',
            'plan_id' => 'required|exists:pgsql.gimnasio.planes,id',
            'sede_id' => 'required|exists:pgsql.institucional.sedes,id_sede',
            'entrenador_id' => 'nullable|exists:pgsql.gimnasio.entrenadores,id',
            'fecha_inicio' => 'required|date',
            'dias_gracia' => 'integer|min:0',
            'renovacion_automatica' => 'boolean',
            'generar_venta' => 'boolean',
        ]);

        $this->validarDeportistaActual((int) $validados['deportista_id']);
        $this->validarEntrenadorSede($validados['entrenador_id'] ?? null, (int) $validados['sede_id']);
        $generarVenta = (bool) ($validados['generar_venta'] ?? false);
        unset($validados['generar_venta']);

        [$membresia, $venta] = DB::transaction(function () use ($validados, $generarVenta, $request): array {
            $membresia = $this->membresiaServicio->crear($validados);
            $venta = null;
            $plan = DB::table('gimnasio.planes')->where('id', $membresia->plan_id)->first();

            if ($generarVenta && ($plan->generar_venta ?? true)) {
                $venta = $this->ventaServicio->guardarVenta([
                    'cliente_id' => $membresia->deportista_id,
                    'membresia_id' => $membresia->id,
                    'caja_id' => null,
                    'tipo_venta' => 'MEMBRESIA',
                    'concepto' => $plan->nombre . ' - ' . $membresia->codigo_contrato,
                    'subtotal' => $membresia->precio_aplicado,
                    'descuento' => 0,
                    'impuesto' => 0,
                    'total' => $membresia->precio_aplicado,
                    'estado' => 'PENDIENTE',
                    'observaciones' => 'Venta generada automáticamente desde Membresías.',
                    'detalle' => [
                        'descripcion' => $plan->nombre,
                        'cantidad' => 1,
                        'precio_unitario' => $membresia->precio_aplicado,
                        'total_linea' => $membresia->precio_aplicado,
                    ],
                ], null, $request->user()?->id);
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

    public function show($id)
    {
        $membresia = $this->membresiaServicio->obtenerMembresiaConRelaciones($id);
        if (!$membresia) return response()->json(['mensaje' => 'Membresía no encontrada'], 404);
        return ApiResponse::exito('Membresía consultada.', (array) $membresia);
    }

    public function update(Request $request, $id)
    {
        $membresia = DB::table('gimnasio.membresias')->where('id', $id)->first();
        if (!$membresia) return response()->json(['mensaje' => 'Membresía no encontrada'], 404);

        $validados = $request->validate([
            'sede_id' => 'nullable|exists:pgsql.institucional.sedes,id_sede',
            'entrenador_id' => 'nullable|exists:pgsql.gimnasio.entrenadores,id',
            'fecha_inicio' => 'required|date',
            'estado' => [
                'required',
                'string',
                Rule::exists('configuracion.estados_catalogo', 'valor_interno')
                    ->where(fn ($q) => $q->where('entidad', 'MEMBRESIA')->where('activo', true)),
            ],
            'dias_gracia' => 'integer|min:0',
            'renovacion_automatica' => 'boolean',
            'fecha_congelacion_inicio' => 'nullable|date',
            'fecha_congelacion_fin' => 'nullable|date|after_or_equal:fecha_congelacion_inicio',
        ]);

        if ($membresia->sede_id === null) {
            if (empty($validados['sede_id'])) {
                throw ValidationException::withMessages(['sede_id' => 'Debes seleccionar una sede para completar esta membresía histórica.']);
            }
        } else {
            if (array_key_exists('sede_id', $validados) && (int) $validados['sede_id'] !== (int) $membresia->sede_id) {
                throw ValidationException::withMessages(['sede_id' => 'La sede de una membresía ya registrada no puede modificarse.']);
            }
            unset($validados['sede_id']);
        }

        $sedeValidacion = (int) ($validados['sede_id'] ?? $membresia->sede_id);
        $entrenadorNuevo = $validados['entrenador_id'] ?? $membresia->entrenador_id;
        $cambioEntrenador = (int) ($entrenadorNuevo ?? 0) !== (int) ($membresia->entrenador_id ?? 0);
        $cambioSede = array_key_exists('sede_id', $validados) && (int) $validados['sede_id'] !== (int) ($membresia->sede_id ?? 0);
        if ($cambioEntrenador || $cambioSede) {
            $this->validarEntrenadorSede($entrenadorNuevo, $sedeValidacion);
        }

        $membresiaActualizada = $this->membresiaServicio->actualizar($id, $validados);
        return ApiResponse::exito('Membresía actualizada correctamente.', (array) $membresiaActualizada);
    }

    public function destroy($id)
    {
        $membresia = DB::table('gimnasio.membresias')->where('id', $id)->first();
        if (!$membresia) return response()->json(['mensaje' => 'Membresía no encontrada'], 404);
        $this->membresiaServicio->eliminar((int) $id);
        return ApiResponse::exito('Membresía eliminada correctamente.');
    }

    private function validarEntrenadorSede(mixed $entrenadorId, int $sedeId): void
    {
        if (! $entrenadorId) {
            return;
        }

        $entrenador = DB::table('gimnasio.entrenadores as e')
            ->join('seguridad.users as u', 'u.id', '=', 'e.usuario_id')
            ->join('seguridad.cpu_userrole as r', 'r.id_userrole', '=', 'u.usr_tipo')
            ->where('e.id', (int) $entrenadorId)
            ->where('e.estado', 'ACTIVO')
            ->where('r.role', 'ENTRENADOR')
            ->first();

        if (! $entrenador) {
            throw ValidationException::withMessages([
                'entrenador_id' => 'El entrenador seleccionado no está activo.',
            ]);
        }

        $tieneHorarioSede = DB::table('gimnasio.horario_entrenadores as he')
            ->join('gimnasio.horarios_servicio as hs', 'hs.horario_bloque_id', '=', 'he.horario_bloque_id')
            ->where('he.entrenador_id', (int) $entrenadorId)
            ->where('he.activo', true)
            ->where('hs.activo', true)
            ->where('hs.sede_id', $sedeId)
            ->exists();

        if (! $tieneHorarioSede) {
            throw ValidationException::withMessages([
                'entrenador_id' => 'El entrenador seleccionado no tiene horarios activos configurados en la sede de la membresía.',
            ]);
        }
    }

    private function validarDeportistaActual(int $deportistaId): void
    {
        $rol = DB::table('gimnasio.deportistas')
            ->join('seguridad.users', 'gimnasio.deportistas.usuario_id', '=', 'seguridad.users.id')
            ->join('seguridad.cpu_userrole', 'seguridad.cpu_userrole.id_userrole', '=', 'seguridad.users.usr_tipo')
            ->where('gimnasio.deportistas.id', $deportistaId)
            ->value('seguridad.cpu_userrole.role');

        if ($rol !== 'DEPORTISTA') {
            throw ValidationException::withMessages(['deportista_id' => 'La membresía solo puede asignarse a un cliente con rol Deportista.']);
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
        $base = DB::table('gimnasio.membresias')
            ->join('gimnasio.deportistas', 'gimnasio.membresias.deportista_id', '=', 'gimnasio.deportistas.id')
            ->join('seguridad.users', 'gimnasio.deportistas.usuario_id', '=', 'seguridad.users.id')
            ->join('gimnasio.planes', 'gimnasio.membresias.plan_id', '=', 'gimnasio.planes.id')
            ->leftJoin('institucional.sedes', 'gimnasio.membresias.sede_id', '=', 'institucional.sedes.id_sede');

        return [
            'codigo' => (clone $base)->whereNotNull('codigo_contrato')->distinct()->orderBy('codigo_contrato')->pluck('codigo_contrato')->values(),
            'cliente' => (clone $base)->whereNotNull('seguridad.users.name')->distinct()->orderBy('seguridad.users.name')->pluck('seguridad.users.name')->values(),
            'plan' => (clone $base)->whereNotNull('gimnasio.planes.nombre')->distinct()->orderBy('gimnasio.planes.nombre')->pluck('gimnasio.planes.nombre')->values(),
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
