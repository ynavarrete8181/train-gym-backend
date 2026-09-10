<?php

namespace App\Http\Controllers\Api\Gimnasio;

use App\Http\Controllers\Controller;
use App\Services\Gimnasio\DeportistaServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeportistaControlador extends Controller
{
    protected DeportistaServicio $deportistaServicio;

    public function __construct(DeportistaServicio $deportistaServicio)
    {
        $this->deportistaServicio = $deportistaServicio;
    }

    public function index(Request $request)
    {
        $porPagina = $request->input('per_page', 15);
        $pagina = $request->input('page', 1);
        $busqueda = $request->input('busqueda');
        $codigo = $request->input('codigo');
        $nombres = $request->input('nombres');
        $telefono = $request->input('telefono');
        $sede = $request->input('sede');
        $estado = $request->input('estado');

        $deportistas = DB::table('gimnasio.deportistas')
            ->join('seguridad.users', 'gimnasio.deportistas.usuario_id', '=', 'seguridad.users.id')
            ->join('seguridad.cpu_userrole', 'seguridad.cpu_userrole.id_userrole', '=', 'seguridad.users.usr_tipo')
            ->leftJoin('institucional.sedes', 'gimnasio.deportistas.sede_principal_id', '=', 'institucional.sedes.id_sede')
            ->where('seguridad.cpu_userrole.role', 'DEPORTISTA')
            ->select(
                'gimnasio.deportistas.*',
                'seguridad.users.name',
                'seguridad.users.nombres',
                'seguridad.users.apellidos',
                'seguridad.users.cedula',
                'seguridad.users.name as usuario_nombre',
                'seguridad.users.email as usuario_email',
                'institucional.sedes.nombre as sede_nombre'
            );

        if (!empty($busqueda)) {
            $busqueda = mb_strtolower($busqueda);
            $deportistas->where(function ($q) use ($busqueda) {
                $q->whereRaw('LOWER(gimnasio.deportistas.codigo_deportista) LIKE ?', ["%{$busqueda}%"])
                  ->orWhereRaw('LOWER(seguridad.users.name) LIKE ?', ["%{$busqueda}%"])
                  ->orWhereRaw('LOWER(seguridad.users.nombres) LIKE ?', ["%{$busqueda}%"])
                  ->orWhereRaw('LOWER(seguridad.users.apellidos) LIKE ?', ["%{$busqueda}%"])
                  ->orWhereRaw('LOWER(seguridad.users.cedula) LIKE ?', ["%{$busqueda}%"])
                  ->orWhereRaw('LOWER(seguridad.users.email) LIKE ?', ["%{$busqueda}%"])
                  ->orWhereRaw('LOWER(gimnasio.deportistas.telefono) LIKE ?', ["%{$busqueda}%"]);
            });
        }

        $this->aplicarFiltro($deportistas, 'gimnasio.deportistas.codigo_deportista', $codigo);
        $this->aplicarFiltro($deportistas, 'gimnasio.deportistas.telefono', $telefono);
        $this->aplicarFiltro($deportistas, 'institucional.sedes.nombre', $sede);
        $this->aplicarFiltro($deportistas, 'gimnasio.deportistas.estado', $estado);

        if (!empty($nombres)) {
            $valores = is_array($nombres) ? $nombres : [$nombres];
            $deportistas->where(function ($q) use ($valores) {
                foreach ($valores as $valor) {
                    $texto = mb_strtolower($valor);
                    $q->orWhereRaw('LOWER(seguridad.users.name) LIKE ?', ["%{$texto}%"])
                      ->orWhereRaw("LOWER(CONCAT(COALESCE(seguridad.users.nombres, ''), ' ', COALESCE(seguridad.users.apellidos, ''))) LIKE ?", ["%{$texto}%"]);
                }
            });
        }

        $paginador = $deportistas
            ->orderBy('gimnasio.deportistas.created_at', 'desc')
            ->paginate($porPagina, ['*'], 'page', $pagina);

        return ApiResponse::exito('Clientes consultados', $paginador->items(), [
            'pagina_actual' => $paginador->currentPage(),
            'por_pagina' => $paginador->perPage(),
            'total' => $paginador->total(),
            'ultima_pagina' => $paginador->lastPage(),
            'opciones_filtro' => $this->opcionesFiltro(),
        ]);
    }

    public function store(Request $request)
    {
        $validados = $request->validate([
            'usuario_id' => 'required|exists:pgsql.seguridad.users,id|unique:pgsql.gimnasio.deportistas',
            'codigo_deportista' => 'required|string|max:40|unique:pgsql.gimnasio.deportistas',
            'fecha_nacimiento' => 'nullable|date',
            'genero' => 'nullable|string|max:20',
            'telefono' => 'nullable|string|max:30',
            'contacto_emergencia_nombre' => 'nullable|string|max:150',
            'contacto_emergencia_telefono' => 'nullable|string|max:30',
            'observaciones_medicas' => 'nullable|string',
            'sede_principal_id' => 'nullable|exists:pgsql.institucional.sedes,id_sede',
            'estado' => 'string|in:PROSPECTO,ACTIVO,INACTIVO,SUSPENDIDO'
        ]);

        $this->validarRolDeportista((int) $validados['usuario_id']);

        $deportista = $this->deportistaServicio->crear($validados);
        
        return ApiResponse::exito('Cliente creado correctamente.', (array) $deportista, [], 201);
    }

    public function show($id)
    {
        $deportista = $this->deportistaServicio->obtenerDeportistaConRelaciones($id);
        
        if (!$deportista) {
            return response()->json(['mensaje' => 'Cliente no encontrado'], 404);
        }

        $membresias = DB::table('gimnasio.membresias')
            ->join('gimnasio.planes', 'gimnasio.membresias.plan_id', '=', 'gimnasio.planes.id')
            ->where('deportista_id', $id)
            ->select('gimnasio.membresias.*', 'gimnasio.planes.nombre as plan_nombre')
            ->get();
            
        $deportista->membresias = $membresias;

        return ApiResponse::exito('Cliente consultado.', (array) $deportista);
    }

    public function update(Request $request, $id)
    {
        $deportista = DB::table('gimnasio.deportistas')->where('id', $id)->first();
        
        if (!$deportista) {
            return response()->json(['mensaje' => 'Cliente no encontrado'], 404);
        }

        $validados = $request->validate([
            'usuario_id' => 'required|exists:pgsql.seguridad.users,id|unique:pgsql.gimnasio.deportistas,usuario_id,' . $id,
            'codigo_deportista' => 'required|string|max:40|unique:pgsql.gimnasio.deportistas,codigo_deportista,' . $id,
            'fecha_nacimiento' => 'nullable|date',
            'genero' => 'nullable|string|max:20',
            'telefono' => 'nullable|string|max:30',
            'contacto_emergencia_nombre' => 'nullable|string|max:150',
            'contacto_emergencia_telefono' => 'nullable|string|max:30',
            'observaciones_medicas' => 'nullable|string',
            'sede_principal_id' => 'nullable|exists:pgsql.institucional.sedes,id_sede',
            'estado' => 'string|in:PROSPECTO,ACTIVO,INACTIVO,SUSPENDIDO'
        ]);

        $this->validarRolDeportista((int) $validados['usuario_id']);

        $deportistaActualizado = $this->deportistaServicio->actualizar($id, $validados);

        return ApiResponse::exito('Cliente actualizado correctamente.', (array) $deportistaActualizado);
    }

    private function validarRolDeportista(int $usuarioId): void
    {
        $rol = DB::table('seguridad.users')
            ->join('seguridad.cpu_userrole', 'seguridad.cpu_userrole.id_userrole', '=', 'seguridad.users.usr_tipo')
            ->where('seguridad.users.id', $usuarioId)
            ->value('seguridad.cpu_userrole.role');

        if ($rol !== 'DEPORTISTA') {
            throw ValidationException::withMessages([
                'usuario_id' => 'El usuario seleccionado debe tener el rol Deportista para poder registrarse como cliente.',
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
        $base = DB::table('gimnasio.deportistas')
            ->join('seguridad.users', 'gimnasio.deportistas.usuario_id', '=', 'seguridad.users.id')
            ->join('seguridad.cpu_userrole', 'seguridad.cpu_userrole.id_userrole', '=', 'seguridad.users.usr_tipo')
            ->leftJoin('institucional.sedes', 'gimnasio.deportistas.sede_principal_id', '=', 'institucional.sedes.id_sede')
            ->where('seguridad.cpu_userrole.role', 'DEPORTISTA');

        return [
            'codigo' => (clone $base)->whereNotNull('codigo_deportista')->distinct()->orderBy('codigo_deportista')->pluck('codigo_deportista')->values(),
            'nombres' => (clone $base)->whereNotNull('seguridad.users.name')->distinct()->orderBy('seguridad.users.name')->pluck('seguridad.users.name')->values(),
            'telefono' => (clone $base)->whereNotNull('telefono')->distinct()->orderBy('telefono')->pluck('telefono')->values(),
            'sede' => (clone $base)->whereNotNull('institucional.sedes.nombre')->distinct()->orderBy('institucional.sedes.nombre')->pluck('institucional.sedes.nombre')->values(),
        ];
    }
}
