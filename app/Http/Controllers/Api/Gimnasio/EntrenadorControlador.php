<?php

namespace App\Http\Controllers\Api\Gimnasio;

use App\Http\Controllers\Controller;
use App\Services\Gimnasio\EntrenadorServicio;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EntrenadorControlador extends Controller
{
    public function __construct(private readonly EntrenadorServicio $servicio) {}

    public function index(Request $request): JsonResponse
    {
        $porPagina = $request->input('per_page', 15);
        $busqueda = $request->input('busqueda');
        $estado = $request->input('estado');
        $tipo = $request->input('tipo');
        $especialidad = $request->input('especialidad');
        $persona = $request->input('persona');
        $usuario = $request->input('usuario');
        $sedeId = $request->input('sede_id');
        
        $query = DB::table('gimnasio.entrenadores as e')
            ->join('seguridad.users as u', 'e.usuario_id', '=', 'u.id')
            ->join('seguridad.cpu_userrole as r', 'u.usr_tipo', '=', 'r.id_userrole')
            ->where('r.role', 'ENTRENADOR')
            ->select([
                'e.*',
                'u.name',
                'u.nombres',
                'u.apellidos',
                'u.email',
                'u.cedula',
                'r.role',
            ]);

        if (!empty($busqueda)) {
            $busqueda = mb_strtolower($busqueda);
            $query->where(function ($q) use ($busqueda) {
                $q->whereRaw('LOWER(u.nombres) LIKE ?', ["%{$busqueda}%"])
                  ->orWhereRaw('LOWER(u.apellidos) LIKE ?', ["%{$busqueda}%"])
                  ->orWhereRaw('LOWER(u.cedula) LIKE ?', ["%{$busqueda}%"])
                  ->orWhereRaw('LOWER(u.email) LIKE ?', ["%{$busqueda}%"]);
            });
        }
        
        if (!empty($estado)) {
            $estados = is_array($estado) ? $estado : [$estado];
            $query->whereIn('e.estado', $estados);
        }

        if (!empty($tipo)) {
            $tipos = is_array($tipo) ? $tipo : [$tipo];
            $query->whereIn('e.tipo', $tipos);
        }

        if (!empty($especialidad)) {
            $especialidad = mb_strtolower($especialidad);
            $query->whereRaw('LOWER(e.especialidad) LIKE ?', ["%{$especialidad}%"]);
        }

        if (!empty($persona)) {
            $persona = mb_strtolower($persona);
            $query->where(function ($q) use ($persona) {
                $q->whereRaw('LOWER(u.nombres) LIKE ?', ["%{$persona}%"])
                  ->orWhereRaw('LOWER(u.apellidos) LIKE ?', ["%{$persona}%"])
                  ->orWhereRaw('LOWER(u.cedula) LIKE ?', ["%{$persona}%"]);
            });
        }

        if (!empty($usuario)) {
            $usuario = mb_strtolower($usuario);
            $query->whereRaw('LOWER(u.email) LIKE ?', ["%{$usuario}%"]);
        }

        if (!empty($sedeId)) {
            $query->whereExists(function ($sub) use ($sedeId): void {
                $sub->selectRaw('1')
                    ->from('gimnasio.horario_entrenadores as he')
                    ->join('gimnasio.horarios_servicio as hs', 'hs.horario_bloque_id', '=', 'he.horario_bloque_id')
                    ->whereColumn('he.entrenador_id', 'e.id')
                    ->where('he.activo', true)
                    ->where('hs.activo', true)
                    ->where('hs.sede_id', (int) $sedeId);
            });
        }

        $paginador = $query->orderBy('u.nombres')->paginate($porPagina);

        return ApiResponse::exito('Entrenadores obtenidos', $paginador->items(), [
            'pagina_actual' => $paginador->currentPage(),
            'por_pagina' => $paginador->perPage(),
            'total' => $paginador->total(),
            'ultima_pagina' => $paginador->lastPage(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'usuario_id' => 'required|integer|exists:pgsql.seguridad.users,id',
            'especialidad' => 'nullable|string|max:150',
            'tipo' => 'nullable|string|max:50',
            'estado' => 'nullable|string|max:30',
        ]);

        abort_if(! $this->usuarioTieneRolEntrenador((int) $datos['usuario_id']), 422, 'El usuario debe tener rol ENTRENADOR antes de crear el perfil de entrenador.');

        $entrenador = $this->servicio->crear($datos);
        
        return ApiResponse::exito('Entrenador creado correctamente.', (array) $entrenador, [], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $datos = $request->validate([
            'especialidad' => 'nullable|string|max:150',
            'tipo' => 'nullable|string|max:50',
            'estado' => 'nullable|string|max:30',
        ]);

        $entrenador = $this->servicio->actualizar($id, $datos);
        
        return ApiResponse::exito('Entrenador actualizado correctamente.', (array) $entrenador);
    }

    public function turnos(int $id): JsonResponse
    {
        return ApiResponse::exito('Turnos consultados.', $this->servicio->listarTurnos($id));
    }

    public function horariosDisponibles(int $id): JsonResponse
    {
        return ApiResponse::exito('Horarios disponibles consultados.', $this->servicio->listarHorariosDisponibles($id));
    }

    public function asignarHorario(Request $request, int $id): JsonResponse
    {
        $datos = $request->validate([
            'horario_bloque_id' => 'required|integer|exists:pgsql.gimnasio.horario_bloques,id',
        ]);

        $turno = $this->servicio->asignarHorario($id, (int) $datos['horario_bloque_id']);

        return ApiResponse::exito('Horario asignado correctamente.', (array) $turno, [], 201);
    }

    public function eliminarTurno(int $id, int $horarioBloqueId): JsonResponse
    {
        $this->servicio->quitarHorario($id, $horarioBloqueId);

        return ApiResponse::exito('Horario retirado del entrenador correctamente.', []);
    }

    private function usuarioTieneRolEntrenador(int $usuarioId): bool
    {
        return DB::table('seguridad.users as u')
            ->join('seguridad.cpu_userrole as r', 'u.usr_tipo', '=', 'r.id_userrole')
            ->where('u.id', $usuarioId)
            ->where('r.role', 'ENTRENADOR')
            ->exists();
    }
}
