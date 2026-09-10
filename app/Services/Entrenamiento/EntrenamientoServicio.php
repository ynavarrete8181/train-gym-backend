<?php

namespace App\Services\Entrenamiento;

use App\Services\Concerns\RegistraAuditoria;
use Illuminate\Support\Facades\DB;

class EntrenamientoServicio
{
    use RegistraAuditoria;

    public function listarEjercicios(array $filtros)
    {
        $query = DB::table('entrenamiento.ejercicios');
        $this->buscar($query, $filtros['busqueda'] ?? null, ['nombre', 'grupo_muscular', 'equipamiento', 'tipo_entrenamiento']);
        $this->filtrarTexto($query, 'nombre', $filtros['nombre'] ?? null);
        $this->filtrarTexto($query, 'grupo_muscular', $filtros['grupo'] ?? null);
        $this->filtrarTexto($query, 'equipamiento', $filtros['equipamiento'] ?? null);
        $this->filtrarBooleano($query, 'activo', $filtros['estado'] ?? null);

        return $query->orderBy('nombre')->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function guardarEjercicio(array $datos, ?int $id = null): object
    {
        return $this->guardar('entrenamiento.ejercicios', $datos, $id);
    }

    public function listarPlanes(array $filtros)
    {
        $query = DB::table('entrenamiento.planes')
            ->join('gimnasio.deportistas', 'entrenamiento.planes.cliente_id', '=', 'gimnasio.deportistas.id')
            ->join('seguridad.users as clientes', 'gimnasio.deportistas.usuario_id', '=', 'clientes.id')
            ->leftJoin('seguridad.users as entrenadores', 'entrenamiento.planes.entrenador_id', '=', 'entrenadores.id')
            ->select('entrenamiento.planes.*', 'clientes.name as cliente_nombre', 'gimnasio.deportistas.codigo_deportista', 'entrenadores.name as entrenador_nombre');

        if (!empty($filtros['cliente_id'])) {
            $query->where('entrenamiento.planes.cliente_id', $filtros['cliente_id']);
        }

        $this->buscar($query, $filtros['busqueda'] ?? null, ['entrenamiento.planes.nombre', 'clientes.name', 'entrenadores.name', 'entrenamiento.planes.estado']);
        $this->filtrarTexto($query, 'clientes.name', $filtros['cliente'] ?? null);
        $this->filtrarTexto($query, 'entrenadores.name', $filtros['entrenador'] ?? null);
        $this->filtrarTexto($query, 'entrenamiento.planes.estado', $filtros['estado'] ?? null);

        return $query->orderByDesc('entrenamiento.planes.created_at')->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function guardarPlan(array $datos, ?int $id = null): object
    {
        return $this->obtenerPlan($this->guardarRetornandoId('entrenamiento.planes', $datos, $id));
    }

    public function listarRutinas(array $filtros)
    {
        $query = DB::table('entrenamiento.rutinas')
            ->join('entrenamiento.planes', 'entrenamiento.rutinas.plan_id', '=', 'entrenamiento.planes.id')
            ->join('entrenamiento.ejercicios', 'entrenamiento.rutinas.ejercicio_id', '=', 'entrenamiento.ejercicios.id')
            ->join('gimnasio.deportistas', 'entrenamiento.planes.cliente_id', '=', 'gimnasio.deportistas.id')
            ->join('seguridad.users as clientes', 'gimnasio.deportistas.usuario_id', '=', 'clientes.id')
            ->select('entrenamiento.rutinas.*', 'entrenamiento.planes.nombre as plan_nombre', 'clientes.name as cliente_nombre', 'entrenamiento.ejercicios.nombre as ejercicio_nombre');

        if (!empty($filtros['cliente_id'])) {
            $query->where('entrenamiento.planes.cliente_id', $filtros['cliente_id']);
        }
        if (!empty($filtros['plan_id'])) {
            $query->where('entrenamiento.rutinas.plan_id', $filtros['plan_id']);
        }

        $this->buscar($query, $filtros['busqueda'] ?? null, ['entrenamiento.planes.nombre', 'clientes.name', 'entrenamiento.ejercicios.nombre', 'entrenamiento.rutinas.dia', 'entrenamiento.rutinas.bloque']);
        $this->filtrarTexto($query, 'entrenamiento.planes.nombre', $filtros['plan'] ?? null);
        $this->filtrarTexto($query, 'entrenamiento.ejercicios.nombre', $filtros['ejercicio'] ?? null);
        $this->filtrarTexto($query, 'entrenamiento.rutinas.dia', $filtros['dia'] ?? null);

        return $query->orderBy('entrenamiento.rutinas.semana')->orderBy('entrenamiento.rutinas.dia')->orderBy('entrenamiento.rutinas.orden')
            ->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function guardarRutina(array $datos, ?int $id = null): object
    {
        return $this->obtenerRutina($this->guardarRetornandoId('entrenamiento.rutinas', $datos, $id));
    }

    public function listarRm(array $filtros)
    {
        $query = DB::table('entrenamiento.rm_registros')
            ->join('gimnasio.deportistas', 'entrenamiento.rm_registros.cliente_id', '=', 'gimnasio.deportistas.id')
            ->join('seguridad.users as clientes', 'gimnasio.deportistas.usuario_id', '=', 'clientes.id')
            ->join('entrenamiento.ejercicios', 'entrenamiento.rm_registros.ejercicio_id', '=', 'entrenamiento.ejercicios.id')
            ->select('entrenamiento.rm_registros.*', 'clientes.name as cliente_nombre', 'gimnasio.deportistas.codigo_deportista', 'entrenamiento.ejercicios.nombre as ejercicio_nombre');

        if (!empty($filtros['cliente_id'])) {
            $query->where('entrenamiento.rm_registros.cliente_id', $filtros['cliente_id']);
        }

        $this->buscar($query, $filtros['busqueda'] ?? null, ['clientes.name', 'gimnasio.deportistas.codigo_deportista', 'entrenamiento.ejercicios.nombre', 'entrenamiento.rm_registros.tipo_registro']);
        $this->filtrarTexto($query, 'clientes.name', $filtros['cliente'] ?? null);
        $this->filtrarTexto($query, 'entrenamiento.ejercicios.nombre', $filtros['ejercicio'] ?? null);
        $this->filtrarTexto($query, 'entrenamiento.rm_registros.tipo_registro', $filtros['tipo'] ?? null);

        return $query->orderByDesc('entrenamiento.rm_registros.fecha_registro')->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function guardarRm(array $datos, ?int $id = null): object
    {
        return $this->obtenerRm($this->guardarRetornandoId('entrenamiento.rm_registros', $datos, $id));
    }

    /**
     * Último RM registrado del cliente, por ejercicio. Se usa para calcular
     * el peso sugerido cuando una rutina tiene tipo_carga = PORCENTAJE_RM
     * (peso sugerido = carga_objetivo% x último rm_estimado de ese ejercicio).
     */
    public function ultimosRmPorCliente(int $clienteId): array
    {
        $registros = DB::table('entrenamiento.rm_registros as r1')
            ->join('entrenamiento.ejercicios', 'r1.ejercicio_id', '=', 'entrenamiento.ejercicios.id')
            ->select('r1.ejercicio_id', 'entrenamiento.ejercicios.nombre as ejercicio_nombre', 'r1.peso', 'r1.rm_estimado', 'r1.fecha_registro')
            ->where('r1.cliente_id', $clienteId)
            ->whereRaw('r1.fecha_registro = (select max(r2.fecha_registro) from entrenamiento.rm_registros r2 where r2.cliente_id = r1.cliente_id and r2.ejercicio_id = r1.ejercicio_id)')
            ->get();

        return $registros->keyBy('ejercicio_id')->toArray();
    }


    public function listarProgreso(array $filtros)
    {
        $query = DB::table('entrenamiento.progresos_corporales')
            ->join('gimnasio.deportistas', 'entrenamiento.progresos_corporales.cliente_id', '=', 'gimnasio.deportistas.id')
            ->join('seguridad.users as clientes', 'gimnasio.deportistas.usuario_id', '=', 'clientes.id')
            ->select('entrenamiento.progresos_corporales.*', 'clientes.name as cliente_nombre', 'gimnasio.deportistas.codigo_deportista');

        if (!empty($filtros['cliente_id'])) {
            $query->where('entrenamiento.progresos_corporales.cliente_id', $filtros['cliente_id']);
        }

        $this->buscar($query, $filtros['busqueda'] ?? null, ['clientes.name', 'gimnasio.deportistas.codigo_deportista']);
        $this->filtrarTexto($query, 'clientes.name', $filtros['cliente'] ?? null);

        return $query->orderByDesc('entrenamiento.progresos_corporales.fecha_registro')->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function guardarProgreso(array $datos, ?int $id = null): object
    {
        $datos = $this->conMetricasCorporales($datos);
        return $this->obtenerProgreso($this->guardarRetornandoId('entrenamiento.progresos_corporales', $datos, $id));
    }

    public function eliminarProgreso(int $id): void
    {
        $antes = $this->obtenerProgreso($id);
        DB::table('entrenamiento.progresos_corporales')->where('id', $id)->delete();
        $this->auditar('entrenamiento', 'ELIMINAR', 'entrenamiento.progresos_corporales', $id, $antes, null);
    }

    public function obtenerProgreso(int $id): object
    {
        return DB::table('entrenamiento.progresos_corporales')
            ->join('gimnasio.deportistas', 'entrenamiento.progresos_corporales.cliente_id', '=', 'gimnasio.deportistas.id')
            ->join('seguridad.users as clientes', 'gimnasio.deportistas.usuario_id', '=', 'clientes.id')
            ->select('entrenamiento.progresos_corporales.*', 'clientes.name as cliente_nombre', 'gimnasio.deportistas.codigo_deportista')
            ->where('entrenamiento.progresos_corporales.id', $id)->first();
    }

    /**
     * Calcula IMC y masa magra en el servidor (nunca se confía en un valor
     * enviado desde el frontend). Fórmulas estándar: IMC = peso / talla_m²,
     * masa magra = peso x (1 - %grasa / 100).
     */
    private function conMetricasCorporales(array $datos): array
    {
        $peso = isset($datos['peso_kg']) ? (float) $datos['peso_kg'] : null;
        $talla = isset($datos['talla_cm']) ? (float) $datos['talla_cm'] : null;
        $grasa = isset($datos['grasa_corporal_pct']) ? (float) $datos['grasa_corporal_pct'] : null;

        $datos['imc'] = ($peso && $talla && $talla > 0)
            ? round($peso / (($talla / 100) ** 2), 2)
            : null;

        $datos['masa_magra_kg'] = ($peso && $grasa !== null && $grasa >= 0 && $grasa <= 100)
            ? round($peso * (1 - ($grasa / 100)), 2)
            : null;

        return $datos;
    }

    public function catalogos(): array
    {
        return [
            'clientes' => DB::table('gimnasio.deportistas')->join('seguridad.users', 'gimnasio.deportistas.usuario_id', '=', 'seguridad.users.id')->orderBy('seguridad.users.name')->get(['gimnasio.deportistas.id', 'gimnasio.deportistas.codigo_deportista', 'seguridad.users.name as nombre']),
            'entrenadores' => DB::table('gimnasio.entrenadores')->join('seguridad.users', 'gimnasio.entrenadores.usuario_id', '=', 'seguridad.users.id')->orderBy('seguridad.users.name')->get(['seguridad.users.id', 'seguridad.users.name as nombre']),
            'ejercicios' => DB::table('entrenamiento.ejercicios')->where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'grupo_muscular']),
            'planes' => DB::table('entrenamiento.planes')->orderByDesc('created_at')->get(['id', 'nombre', 'cliente_id', 'estado']),
        ];
    }

    public function opcionesFiltro(): array
    {
        return [
            'nombre' => DB::table('entrenamiento.ejercicios')->distinct()->orderBy('nombre')->pluck('nombre')->values(),
            'grupo' => DB::table('entrenamiento.ejercicios')->distinct()->orderBy('grupo_muscular')->pluck('grupo_muscular')->values(),
            'equipamiento' => DB::table('entrenamiento.ejercicios')->distinct()->orderBy('equipamiento')->pluck('equipamiento')->values(),
            'cliente' => DB::table('gimnasio.deportistas')->join('seguridad.users', 'gimnasio.deportistas.usuario_id', '=', 'seguridad.users.id')->distinct()->orderBy('seguridad.users.name')->pluck('seguridad.users.name')->values(),
            'entrenador' => DB::table('gimnasio.entrenadores')->join('seguridad.users', 'gimnasio.entrenadores.usuario_id', '=', 'seguridad.users.id')->distinct()->orderBy('seguridad.users.name')->pluck('seguridad.users.name')->values(),
            'plan' => DB::table('entrenamiento.planes')->distinct()->orderBy('nombre')->pluck('nombre')->values(),
            'ejercicio' => DB::table('entrenamiento.ejercicios')->distinct()->orderBy('nombre')->pluck('nombre')->values(),
        ];
    }

    private function guardar(string $tabla, array $datos, ?int $id): object
    {
        $id = $this->guardarRetornandoId($tabla, $datos, $id);
        return DB::table($tabla)->where('id', $id)->first();
    }

    private function guardarRetornandoId(string $tabla, array $datos, ?int $id): int
    {
        $antes = $id ? DB::table($tabla)->where('id', $id)->first() : null;
        $datos['updated_at'] = now();
        if ($id) {
            DB::table($tabla)->where('id', $id)->update($datos);
            $this->auditar('entrenamiento', 'ACTUALIZAR', $tabla, $id, $antes, DB::table($tabla)->where('id', $id)->first());
            return $id;
        }

        $datos['created_at'] = now();
        $nuevoId = DB::table($tabla)->insertGetId($datos);
        $this->auditar('entrenamiento', 'CREAR', $tabla, $nuevoId, null, DB::table($tabla)->where('id', $nuevoId)->first());
        return $nuevoId;
    }

    private function obtenerPlan(int $id): object
    {
        return DB::table('entrenamiento.planes')
            ->join('gimnasio.deportistas', 'entrenamiento.planes.cliente_id', '=', 'gimnasio.deportistas.id')
            ->join('seguridad.users as clientes', 'gimnasio.deportistas.usuario_id', '=', 'clientes.id')
            ->leftJoin('seguridad.users as entrenadores', 'entrenamiento.planes.entrenador_id', '=', 'entrenadores.id')
            ->select('entrenamiento.planes.*', 'clientes.name as cliente_nombre', 'gimnasio.deportistas.codigo_deportista', 'entrenadores.name as entrenador_nombre')
            ->where('entrenamiento.planes.id', $id)->first();
    }

    private function obtenerRutina(int $id): object
    {
        return DB::table('entrenamiento.rutinas')
            ->join('entrenamiento.planes', 'entrenamiento.rutinas.plan_id', '=', 'entrenamiento.planes.id')
            ->join('entrenamiento.ejercicios', 'entrenamiento.rutinas.ejercicio_id', '=', 'entrenamiento.ejercicios.id')
            ->join('gimnasio.deportistas', 'entrenamiento.planes.cliente_id', '=', 'gimnasio.deportistas.id')
            ->join('seguridad.users as clientes', 'gimnasio.deportistas.usuario_id', '=', 'clientes.id')
            ->select('entrenamiento.rutinas.*', 'entrenamiento.planes.nombre as plan_nombre', 'clientes.name as cliente_nombre', 'entrenamiento.ejercicios.nombre as ejercicio_nombre')
            ->where('entrenamiento.rutinas.id', $id)->first();
    }

    private function obtenerRm(int $id): object
    {
        return DB::table('entrenamiento.rm_registros')
            ->join('gimnasio.deportistas', 'entrenamiento.rm_registros.cliente_id', '=', 'gimnasio.deportistas.id')
            ->join('seguridad.users as clientes', 'gimnasio.deportistas.usuario_id', '=', 'clientes.id')
            ->join('entrenamiento.ejercicios', 'entrenamiento.rm_registros.ejercicio_id', '=', 'entrenamiento.ejercicios.id')
            ->select('entrenamiento.rm_registros.*', 'clientes.name as cliente_nombre', 'gimnasio.deportistas.codigo_deportista', 'entrenamiento.ejercicios.nombre as ejercicio_nombre')
            ->where('entrenamiento.rm_registros.id', $id)->first();
    }

    private function buscar($query, ?string $busqueda, array $columnas): void
    {
        if (empty($busqueda)) {
            return;
        }

        $texto = mb_strtolower($busqueda);
        $query->where(function ($q) use ($columnas, $texto): void {
            foreach ($columnas as $columna) {
                $q->orWhereRaw("LOWER({$columna}) LIKE ?", ["%{$texto}%"]);
            }
        });
    }

    private function filtrarTexto($query, string $columna, mixed $valor): void
    {
        if (empty($valor)) {
            return;
        }

        $valores = is_array($valor) ? array_filter($valor) : [$valor];
        is_array($valor)
            ? $query->whereIn($columna, $valores)
            : $query->whereRaw("LOWER({$columna}) LIKE ?", ['%' . mb_strtolower($valor) . '%']);
    }

    private function filtrarBooleano($query, string $columna, mixed $valor): void
    {
        if ($valor === null || $valor === '') {
            return;
        }

        $booleanos = collect(is_array($valor) ? $valor : [$valor])
            ->map(fn ($item) => filter_var($item, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE))
            ->filter(fn ($item) => $item !== null)
            ->values();

        if ($booleanos->isNotEmpty()) {
            $query->whereIn($columna, $booleanos->all());
        }
    }
}
