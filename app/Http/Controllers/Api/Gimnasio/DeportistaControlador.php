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

        $deportistas = DB::table('clientes.deportistas')
            ->leftJoin('personas.personas as p', 'clientes.deportistas.persona_id', '=', 'p.id')
            ->leftJoin('seguridad.users', 'clientes.deportistas.usuario_id', '=', 'seguridad.users.id')
            ->leftJoin('seguridad.cpu_userrole', 'seguridad.cpu_userrole.id_userrole', '=', 'seguridad.users.usr_tipo')
            ->leftJoin('institucional.sedes', 'clientes.deportistas.sede_principal_id', '=', 'institucional.sedes.id_sede')
            ->where(function ($q) {
                $q->whereNull('clientes.deportistas.usuario_id')
                    ->orWhere('seguridad.cpu_userrole.role', 'DEPORTISTA');
            })
            ->select(
                'clientes.deportistas.*',
                'p.identificacion as cedula',
                'p.nombres',
                'p.apellidos',
                'p.nombre_completo as name',
                'p.nombre_completo as usuario_nombre',
                'p.email as usuario_email',
                'institucional.sedes.nombre as sede_nombre'
            );

        if (!empty($busqueda)) {
            $busqueda = mb_strtolower($busqueda);
            $deportistas->where(function ($q) use ($busqueda) {
                $q->whereRaw('LOWER(clientes.deportistas.codigo_deportista) LIKE ?', ["%{$busqueda}%"])
                  ->orWhereRaw('LOWER(COALESCE(p.nombre_completo, seguridad.users.name)) LIKE ?', ["%{$busqueda}%"])
                  ->orWhereRaw('LOWER(COALESCE(p.nombres, seguridad.users.nombres)) LIKE ?', ["%{$busqueda}%"])
                  ->orWhereRaw('LOWER(COALESCE(p.apellidos, seguridad.users.apellidos)) LIKE ?', ["%{$busqueda}%"])
                  ->orWhereRaw('LOWER(COALESCE(p.identificacion, seguridad.users.cedula)) LIKE ?', ["%{$busqueda}%"])
                  ->orWhereRaw('LOWER(COALESCE(p.email, seguridad.users.email)) LIKE ?', ["%{$busqueda}%"])
                  ->orWhereRaw('LOWER(clientes.deportistas.telefono) LIKE ?', ["%{$busqueda}%"]);
            });
        }

        $this->aplicarFiltro($deportistas, 'clientes.deportistas.codigo_deportista', $codigo);
        $this->aplicarFiltro($deportistas, 'clientes.deportistas.telefono', $telefono);
        $this->aplicarFiltro($deportistas, 'institucional.sedes.nombre', $sede);
        $this->aplicarFiltro($deportistas, 'clientes.deportistas.estado', $estado);

        if (!empty($nombres)) {
            $valores = is_array($nombres) ? $nombres : [$nombres];
            $deportistas->where(function ($q) use ($valores) {
                foreach ($valores as $valor) {
                    $texto = mb_strtolower($valor);
                    $q->orWhereRaw('LOWER(COALESCE(p.nombre_completo, seguridad.users.name)) LIKE ?', ["%{$texto}%"])
                      ->orWhereRaw("LOWER(CONCAT(COALESCE(seguridad.users.nombres, ''), ' ', COALESCE(seguridad.users.apellidos, ''))) LIKE ?", ["%{$texto}%"]);
                }
            });
        }

        $paginador = $deportistas
            ->orderBy('clientes.deportistas.created_at', 'desc')
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
        $validados = $this->validarDatosCliente($request);

        if (! empty($validados['usuario_id'])) {
            $this->validarRolDeportista((int) $validados['usuario_id']);
        }

        $validados['usuario_id'] = $validados['usuario_id'] ?? null;
        $deportista = $this->deportistaServicio->crear($validados);

        return ApiResponse::exito('Cliente creado correctamente.', (array) $deportista, [], 201);
    }

    public function show($id)
    {
        if (! is_numeric($id)) {
            return response()->json(['mensaje' => 'Identificador de cliente inválido.'], 404);
        }

        $deportista = $this->deportistaServicio->obtenerDeportistaConRelaciones((int) $id);
        
        if (!$deportista) {
            return response()->json(['mensaje' => 'Cliente no encontrado'], 404);
        }

        $membresias = DB::table('gimnasio.membresias')
            ->join('gimnasio.planes', 'gimnasio.membresias.plan_id', '=', 'gimnasio.planes.id')
            ->leftJoin('membresias.plan_modalidades as modalidad', 'gimnasio.membresias.modalidad_id', '=', 'modalidad.id')
            ->where('deportista_id', $id)
            ->select(
                'gimnasio.membresias.*',
                'gimnasio.planes.nombre as plan_nombre',
                'modalidad.nombre as modalidad_nombre',
                'modalidad.dias_por_semana as modalidad_dias_por_semana',
                'modalidad.usos_por_semana as modalidad_usos_por_semana',
                'modalidad.uso_ilimitado as modalidad_uso_ilimitado',
                'modalidad.tipo_duracion as modalidad_tipo_duracion',
                'modalidad.duracion as modalidad_duracion'
            )
            ->get();
            
        $deportista->membresias = $membresias;

        return ApiResponse::exito('Cliente consultado.', (array) $deportista);
    }

    public function update(Request $request, $id)
    {
        if (! is_numeric($id)) {
            return response()->json(['mensaje' => 'Identificador de cliente inválido.'], 404);
        }

        $id = (int) $id;
        $deportista = DB::table('clientes.deportistas')->where('id', $id)->first();

        if (! $deportista) {
            return response()->json(['mensaje' => 'Cliente no encontrado'], 404);
        }

        $validados = $this->validarDatosCliente($request, (int) $id);

        if (! empty($validados['usuario_id'])) {
            $this->validarRolDeportista((int) $validados['usuario_id']);
        }

        $validados['persona_id'] = $deportista->persona_id;
        $validados['usuario_id'] = $deportista->usuario_id;
        $deportistaActualizado = $this->deportistaServicio->actualizar((int) $id, $validados);

        return ApiResponse::exito('Cliente actualizado correctamente.', (array) $deportistaActualizado);
    }

    private function validarDatosCliente(Request $request, ?int $id = null): array
    {
        $reglaPersonaUnica = $id
            ? 'unique:pgsql.clientes.deportistas,persona_id,' . $id . ',id'
            : 'unique:pgsql.clientes.deportistas,persona_id';
        $reglaUsuarioUnico = $id
            ? 'unique:pgsql.clientes.deportistas,usuario_id,' . $id . ',id'
            : 'unique:pgsql.clientes.deportistas,usuario_id';
        $reglaCodigoUnico = $id
            ? 'unique:pgsql.clientes.deportistas,codigo_deportista,' . $id . ',id'
            : 'unique:pgsql.clientes.deportistas,codigo_deportista';

        $validados = $request->validate([
            'persona' => 'required|array',
            'persona.tipo_identificacion' => 'nullable|string|max:30',
            'persona.identificacion' => 'nullable|string|max:50',
            'persona.nombres' => 'required|string|max:150',
            'persona.apellidos' => 'nullable|string|max:150',
            'persona.fecha_nacimiento' => 'nullable|date',
            'persona.genero' => 'nullable|string|max:30',
            'persona.telefono' => 'nullable|string|max:30',
            'persona.email' => 'nullable|email|max:190',
            'persona.direccion' => 'nullable|string',

            'persona_id' => ['nullable', 'exists:pgsql.personas.personas,id', $reglaPersonaUnica],
            'usuario_id' => ['nullable', 'exists:pgsql.seguridad.users,id', $reglaUsuarioUnico],
            'codigo_deportista' => ['required', 'string', 'max:40', $reglaCodigoUnico],
            'fecha_nacimiento' => 'nullable|date',
            'genero' => 'nullable|string|max:20',
            'telefono' => 'nullable|string|max:30',
            'contacto_emergencia_nombre' => 'nullable|string|max:150',
            'contacto_emergencia_telefono' => 'nullable|string|max:30',
            'observaciones_medicas' => 'nullable|string',
            'sede_principal_id' => 'nullable|exists:pgsql.institucional.sedes,id_sede',
            'estado' => 'string|in:PROSPECTO,ACTIVO,INACTIVO,SUSPENDIDO',
            'requiere_representante_legal' => 'boolean',

            'representante_legal' => 'nullable|array',
            'representante_legal.tipo_identificacion' => 'nullable|string|max:30',
            'representante_legal.identificacion' => 'nullable|string|max:50',
            'representante_legal.nombres' => 'nullable|string|max:150',
            'representante_legal.apellidos' => 'nullable|string|max:150',
            'representante_legal.telefono' => 'nullable|string|max:30',
            'representante_legal.email' => 'nullable|email|max:190',
            'representante_legal.direccion' => 'nullable|string',
            'representante_legal.tipo_relacion' => 'nullable|string|in:REPRESENTANTE_LEGAL,MADRE,PADRE,TUTOR,OTRO',
            'representante_legal.responsable_pago' => 'boolean',
        ]);

        $validados['requiere_representante_legal'] = (bool) ($validados['requiere_representante_legal'] ?? false);

        if ($validados['requiere_representante_legal'] === true) {
            $representante = $validados['representante_legal'] ?? [];
            $nombre = trim((string) ($representante['nombres'] ?? ''));
            $apellidos = trim((string) ($representante['apellidos'] ?? ''));

            if ($nombre === '' && $apellidos === '') {
                throw ValidationException::withMessages([
                    'representante_legal.nombres' => 'Ingresa los nombres o apellidos del representante legal.',
                ]);
            }
        } else {
            unset($validados['representante_legal']);
        }

        return $validados;
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
        $base = DB::table('clientes.deportistas')
            ->leftJoin('personas.personas as p', 'clientes.deportistas.persona_id', '=', 'p.id')
            ->leftJoin('seguridad.users', 'clientes.deportistas.usuario_id', '=', 'seguridad.users.id')
            ->leftJoin('seguridad.cpu_userrole', 'seguridad.cpu_userrole.id_userrole', '=', 'seguridad.users.usr_tipo')
            ->leftJoin('institucional.sedes', 'clientes.deportistas.sede_principal_id', '=', 'institucional.sedes.id_sede')
            ->where(function ($q) {
                $q->whereNull('clientes.deportistas.usuario_id')
                    ->orWhere('seguridad.cpu_userrole.role', 'DEPORTISTA');
            });

        return [
            'codigo' => (clone $base)
                ->whereNotNull('clientes.deportistas.codigo_deportista')
                ->distinct()
                ->orderBy('clientes.deportistas.codigo_deportista')
                ->pluck('clientes.deportistas.codigo_deportista')
                ->values(),
            'nombres' => (clone $base)
                ->whereNotNull('p.nombre_completo')
                ->distinct()
                ->orderBy('p.nombre_completo')
                ->pluck('p.nombre_completo')
                ->values(),
            'telefono' => (clone $base)
                ->whereNotNull('clientes.deportistas.telefono')
                ->distinct()
                ->orderBy('clientes.deportistas.telefono')
                ->pluck('clientes.deportistas.telefono')
                ->values(),
            'sede' => (clone $base)
                ->whereNotNull('institucional.sedes.nombre')
                ->distinct()
                ->orderBy('institucional.sedes.nombre')
                ->pluck('institucional.sedes.nombre')
                ->values(),
        ];
    }
}
