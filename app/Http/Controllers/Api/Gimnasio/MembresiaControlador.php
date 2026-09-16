<?php

namespace App\Http\Controllers\Api\Gimnasio;

use App\Http\Controllers\Controller;
use App\Services\Gimnasio\MembresiaServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MembresiaControlador extends Controller
{
    protected MembresiaServicio $membresiaServicio;

    public function __construct(MembresiaServicio $membresiaServicio)
    {
        $this->membresiaServicio = $membresiaServicio;
    }

    public function index(Request $request)
    {
        $porPagina = $request->input('per_page', 15);
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
            ->select(
                'gimnasio.membresias.*',
                'gimnasio.deportistas.codigo_deportista',
                'seguridad.users.name as deportista_nombre',
                'seguridad.users.email as deportista_email',
                'gimnasio.planes.nombre as plan_nombre',
                'institucional.sedes.nombre as sede_nombre'
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
                  ->orWhereRaw('LOWER(gimnasio.planes.nombre) LIKE ?', ["%{$busqueda}%"]);
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
        ]);
    }

    public function store(Request $request)
    {
        $validados = $request->validate([
            'deportista_id' => 'required|exists:pgsql.gimnasio.deportistas,id',
            'plan_id' => 'required|exists:pgsql.gimnasio.planes,id',
            'sede_id' => 'required|exists:pgsql.institucional.sedes,id_sede',
            'codigo_contrato' => 'required|string|max:60|unique:pgsql.gimnasio.membresias',
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'required|date|after_or_equal:fecha_inicio',
            'estado' => 'required|string|in:PENDIENTE_PAGO,ACTIVA,VENCIDA,CONGELADA,CANCELADA',
            'dias_gracia' => 'integer|min:0',
            'renovacion_automatica' => 'boolean'
        ]);

        $this->validarDeportistaActual((int) $validados['deportista_id']);

        $membresia = $this->membresiaServicio->crear($validados);

        return ApiResponse::exito('Membresía creada correctamente.', (array) $membresia, [], 201);
    }

    public function show($id)
    {
        $membresia = $this->membresiaServicio->obtenerMembresiaConRelaciones($id);

        if (!$membresia) {
            return response()->json(['mensaje' => 'Membresía no encontrada'], 404);
        }

        return ApiResponse::exito('Membresía consultada.', (array) $membresia);
    }

    public function update(Request $request, $id)
    {
        $membresia = DB::table('gimnasio.membresias')->where('id', $id)->first();

        if (!$membresia) {
            return response()->json(['mensaje' => 'Membresía no encontrada'], 404);
        }

        $validados = $request->validate([
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'required|date|after_or_equal:fecha_inicio',
            'estado' => 'required|string|in:PENDIENTE_PAGO,ACTIVA,VENCIDA,CONGELADA,CANCELADA',
            'dias_gracia' => 'integer|min:0',
            'renovacion_automatica' => 'boolean',
            'fecha_congelacion_inicio' => 'nullable|date',
            'fecha_congelacion_fin' => 'nullable|date|after_or_equal:fecha_congelacion_inicio',
        ]);

        $membresiaActualizada = $this->membresiaServicio->actualizar($id, $validados);

        return ApiResponse::exito('Membresía actualizada correctamente.', (array) $membresiaActualizada);
    }

    public function destroy($id)
    {
        $membresia = DB::table('gimnasio.membresias')->where('id', $id)->first();

        if (!$membresia) {
            return response()->json(['mensaje' => 'Membresía no encontrada'], 404);
        }

        $this->membresiaServicio->eliminar((int) $id);

        return ApiResponse::exito('Membresía eliminada correctamente.');
    }

    private function validarDeportistaActual(int $deportistaId): void
    {
        $rol = DB::table('gimnasio.deportistas')
            ->join('seguridad.users', 'gimnasio.deportistas.usuario_id', '=', 'seguridad.users.id')
            ->join('seguridad.cpu_userrole', 'seguridad.cpu_userrole.id_userrole', '=', 'seguridad.users.usr_tipo')
            ->where('gimnasio.deportistas.id', $deportistaId)
            ->value('seguridad.cpu_userrole.role');

        if ($rol !== 'DEPORTISTA') {
            throw ValidationException::withMessages([
                'deportista_id' => 'La membresía solo puede asignarse a un cliente con rol Deportista.',
            ]);
        }
    }

    private function aplicarFiltro($query, string $columna, mixed $valor): void
    {
        if (empty($valor)) {
            return;
        }

        $valores = is_array($valor) ? array_filter($valor) : [$valor];

        if (empty($valores)) {
            return;
        }

        if (is_array($valor)) {
            $query->whereIn($columna, $valores);
            return;
        }

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
        ];
    }
}
