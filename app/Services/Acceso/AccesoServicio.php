<?php

namespace App\Services\Acceso;

use App\Services\Concerns\RegistraAuditoria;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AccesoServicio
{
    use RegistraAuditoria;

    private const TOLERANCIA_MINUTOS_ANTES = 15;

    private const DIAS_SEMANA = ['DOMINGO', 'LUNES', 'MARTES', 'MIERCOLES', 'JUEVES', 'VIERNES', 'SABADO'];

    public function listarDispositivos(array $filtros) { return $this->listarSimple('acceso.dispositivos', $filtros, ['nombre', 'tipo', 'proveedor']); }
    public function guardarDispositivo(array $datos, ?int $id = null): object { return $this->guardar('acceso.dispositivos', $datos, $id); }

    public function listarCredenciales(array $filtros)
    {
        $query = DB::table('acceso.credenciales')
            ->join('gimnasio.deportistas', 'acceso.credenciales.cliente_id', '=', 'gimnasio.deportistas.id')
            ->join('seguridad.users', 'gimnasio.deportistas.usuario_id', '=', 'seguridad.users.id')
            ->select('acceso.credenciales.*', 'seguridad.users.name as cliente_nombre', 'seguridad.users.email as cliente_email', 'gimnasio.deportistas.codigo_deportista');

        $this->buscar($query, $filtros['busqueda'] ?? null, ['acceso.credenciales.codigo', 'acceso.credenciales.tipo', 'seguridad.users.name', 'gimnasio.deportistas.codigo_deportista']);
        $this->filtrarTexto($query, 'seguridad.users.name', $filtros['cliente'] ?? null);
        $this->filtrarTexto($query, 'acceso.credenciales.tipo', $filtros['tipo'] ?? null);
        $this->filtrarTexto($query, 'acceso.credenciales.estado', $filtros['estado'] ?? null);

        return $query->orderByDesc('acceso.credenciales.created_at')->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function guardarCredencial(array $datos, ?int $id = null): object
    {
        return $this->guardar('acceso.credenciales', $datos, $id);
    }

    public function listarEventos(array $filtros)
    {
        $query = DB::table('acceso.eventos')
            ->leftJoin('acceso.dispositivos', 'acceso.eventos.dispositivo_id', '=', 'acceso.dispositivos.id')
            ->leftJoin('gimnasio.deportistas', 'acceso.eventos.cliente_id', '=', 'gimnasio.deportistas.id')
            ->leftJoin('seguridad.users', 'gimnasio.deportistas.usuario_id', '=', 'seguridad.users.id')
            ->select('acceso.eventos.*', 'acceso.dispositivos.nombre as dispositivo_nombre', 'seguridad.users.name as cliente_nombre', 'gimnasio.deportistas.codigo_deportista');

        $this->buscar($query, $filtros['busqueda'] ?? null, ['acceso.eventos.codigo_credencial', 'acceso.eventos.tipo_evento', 'acceso.eventos.resultado', 'seguridad.users.name']);
        $this->filtrarTexto($query, 'seguridad.users.name', $filtros['cliente'] ?? null);
        $this->filtrarTexto($query, 'acceso.eventos.tipo_evento', $filtros['tipo'] ?? null);
        $this->filtrarTexto($query, 'acceso.eventos.resultado', $filtros['resultado'] ?? null);

        return $query->orderByDesc('acceso.eventos.fecha_hora')->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function registrarEvento(array $datos): object
    {
        return DB::transaction(function () use ($datos): object {
            $credencial = DB::table('acceso.credenciales')->where('codigo', $datos['codigo_credencial'] ?? '')->first();
            $clienteId = $datos['cliente_id'] ?? $credencial?->cliente_id;
            $sedeId = ! empty($datos['sede_id']) ? (int) $datos['sede_id'] : null;
            $membresia = $clienteId ? $this->membresiaVigente((int) $clienteId, $sedeId) : null;
            [$resultado, $motivo] = $this->evaluarAcceso($credencial, $membresia, $clienteId ? (int) $clienteId : null, $sedeId);

            $eventoId = DB::table('acceso.eventos')->insertGetId([
                'dispositivo_id' => $datos['dispositivo_id'] ?? null,
                'cliente_id' => $clienteId,
                'credencial_id' => $credencial?->id,
                'membresia_id' => $membresia?->id,
                'codigo_credencial' => $datos['codigo_credencial'] ?? null,
                'fecha_hora' => now(),
                'tipo_evento' => $datos['tipo_evento'] ?? 'INGRESO',
                'resultado' => $resultado,
                'motivo' => $motivo,
                'payload_raw' => isset($datos['payload_raw']) ? json_encode($datos['payload_raw']) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($credencial) {
                DB::table('acceso.credenciales')->where('id', $credencial->id)->update(['ultimo_uso_at' => now(), 'updated_at' => now()]);
            }

            if ($resultado === 'PERMITIDO' && $clienteId) {
                DB::table('acceso.asistencias')->insert([
                    'evento_id' => $eventoId,
                    'cliente_id' => $clienteId,
                    'membresia_id' => $membresia?->id,
                    'sede_id' => $datos['sede_id'] ?? null,
                    'tipo' => $datos['tipo_evento'] ?? 'INGRESO',
                    'metodo' => 'CREDENCIAL',
                    'estado' => 'VALIDA',
                    'fecha_hora' => now(),
                    'observaciones' => $motivo,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $this->obtenerEvento($eventoId);
        });
    }

    public function listarAsistencias(array $filtros)
    {
        $query = DB::table('acceso.asistencias')
            ->join('gimnasio.deportistas', 'acceso.asistencias.cliente_id', '=', 'gimnasio.deportistas.id')
            ->join('seguridad.users', 'gimnasio.deportistas.usuario_id', '=', 'seguridad.users.id')
            ->leftJoin('institucional.sedes', 'acceso.asistencias.sede_id', '=', 'institucional.sedes.id_sede')
            ->select('acceso.asistencias.*', 'seguridad.users.name as cliente_nombre', 'gimnasio.deportistas.codigo_deportista', 'institucional.sedes.nombre as sede_nombre');

        $this->buscar($query, $filtros['busqueda'] ?? null, ['seguridad.users.name', 'gimnasio.deportistas.codigo_deportista', 'acceso.asistencias.tipo', 'acceso.asistencias.estado']);
        $this->filtrarTexto($query, 'seguridad.users.name', $filtros['cliente'] ?? null);
        $this->filtrarTexto($query, 'acceso.asistencias.tipo', $filtros['tipo'] ?? null);
        $this->filtrarTexto($query, 'acceso.asistencias.estado', $filtros['estado'] ?? null);

        return $query->orderByDesc('acceso.asistencias.fecha_hora')->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function registrarAsistencia(array $datos): object
    {
        $id = DB::table('acceso.asistencias')->insertGetId($datos + ['created_at' => now(), 'updated_at' => now()]);
        return DB::table('acceso.asistencias')->where('id', $id)->first();
    }

    public function catalogos(): array
    {
        return [
            'clientes' => DB::table('gimnasio.deportistas')
                ->join('seguridad.users', 'gimnasio.deportistas.usuario_id', '=', 'seguridad.users.id')
                ->orderBy('seguridad.users.name')
                ->get(['gimnasio.deportistas.id', 'gimnasio.deportistas.codigo_deportista', 'seguridad.users.name as nombre']),
            'dispositivos' => DB::table('acceso.dispositivos')->where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'tipo']),
            'sedes' => DB::table('institucional.sedes')->where('activo', true)->orderBy('nombre')->get(['id_sede as id', 'nombre']),
        ];
    }

    public function opcionesFiltro(): array
    {
        return [
            'cliente' => DB::table('seguridad.users')->distinct()->orderBy('name')->pluck('name')->values(),
            'dispositivo' => DB::table('acceso.dispositivos')->distinct()->orderBy('nombre')->pluck('nombre')->values(),
            'tipo_credencial' => DB::table('acceso.credenciales')->distinct()->orderBy('tipo')->pluck('tipo')->values(),
        ];
    }

    private function listarSimple(string $tabla, array $filtros, array $columnas)
    {
        $query = DB::table($tabla)->leftJoin('institucional.sedes', "{$tabla}.sede_id", '=', 'institucional.sedes.id_sede')->select("{$tabla}.*", 'institucional.sedes.nombre as sede_nombre');
        $this->buscar($query, $filtros['busqueda'] ?? null, $columnas);
        $this->filtrarTexto($query, "{$tabla}.nombre", $filtros['nombre'] ?? null);
        $this->filtrarBooleano($query, "{$tabla}.activo", $filtros['estado'] ?? null);
        return $query->orderBy("{$tabla}.nombre")->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    private function membresiaVigente(int $clienteId, ?int $sedeId = null): ?object
    {
        return DB::table('gimnasio.membresias as m')
            ->when($sedeId, function ($query) use ($sedeId): void {
                $query->join('gimnasio.membresia_sedes as ms', function ($join) use ($sedeId): void {
                    $join->on('ms.membresia_id', '=', 'm.id')
                        ->where('ms.sede_id', '=', $sedeId)
                        ->where('ms.activo', '=', true);
                });
            })
            ->where('m.deportista_id', $clienteId)
            ->where('m.estado', 'ACTIVA')
            ->whereDate('m.fecha_inicio', '<=', now()->toDateString())
            ->whereDate('m.fecha_fin', '>=', now()->toDateString())
            ->orderByDesc('m.fecha_fin')
            ->select('m.*')
            ->first();
    }

    /**
     * Devuelve [resultado, motivo]. El resultado se mantiene en PERMITIDO/DENEGADO
     * (son los únicos valores que conoce el frontend); el motivo explica el porqué.
     */
    private function evaluarAcceso(?object $credencial, ?object $membresia, ?int $clienteId, ?int $sedeId = null): array
    {
        if (! $credencial) return ['DENEGADO', 'Acceso denegado: credencial no encontrada.'];
        if ($credencial->estado !== 'ACTIVA') return ['DENEGADO', 'Acceso denegado: credencial inactiva.'];
        if ($credencial->vigencia_inicio && now()->lessThan($credencial->vigencia_inicio)) return ['DENEGADO', 'Acceso denegado: credencial aún no vigente.'];
        if ($credencial->vigencia_fin && now()->greaterThan($credencial->vigencia_fin)) return ['DENEGADO', 'Acceso denegado: credencial vencida.'];
        if (! $membresia) return ['DENEGADO', 'Acceso denegado: sin membresía vigente.'];
        if ($clienteId && $membresia && ! $this->dentroDeHorarioAsignado($clienteId, (int) $membresia->id, $sedeId)) {
            return ['DENEGADO', 'Acceso denegado: fuera del horario asignado con su entrenador.'];
        }
        return ['PERMITIDO', 'Acceso permitido con membresía vigente.'];
    }

    /**
     * Un cliente sin horario/entrenador asignado tiene acceso general (solo depende
     * de la membresía). Si tiene una o más asignaciones activas con horario, el
     * acceso debe caer dentro de alguno de esos bloques (con tolerancia de entrada
     * anticipada) el día actual.
     */
    private function dentroDeHorarioAsignado(int $clienteId, int $membresiaId, ?int $sedeId = null): bool
    {
        $asignaciones = DB::table('gimnasio.asignaciones_entrenador_cliente as a')
            ->join('gimnasio.horarios_servicio as hs', function ($join): void {
                $join->on('hs.horario_bloque_id', '=', 'a.horario_bloque_id')
                    ->where('hs.activo', true);
            })
            ->where('a.deportista_id', $clienteId)
            ->where('a.membresia_id', $membresiaId)
            ->where('a.estado', 'ACTIVO')
            ->whereNotNull('a.horario_bloque_id')
            ->when($sedeId, function ($query) use ($sedeId): void {
                $query->where('a.sede_id', $sedeId)
                    ->where('hs.sede_id', $sedeId);
            })
            ->get(['hs.dia_semana', 'hs.hora_inicio', 'hs.hora_fin']);

        if ($asignaciones->isEmpty()) {
            return true;
        }

        $diaActual = self::DIAS_SEMANA[now()->dayOfWeek];
        $horaActual = now()->format('H:i:s');

        return $asignaciones->contains(function (object $horario) use ($diaActual, $horaActual): bool {
            if ($horario->dia_semana !== $diaActual) {
                return false;
            }
            $inicioConTolerancia = Carbon::createFromFormat('H:i:s', $horario->hora_inicio)
                ->subMinutes(self::TOLERANCIA_MINUTOS_ANTES)
                ->format('H:i:s');

            return $horaActual >= $inicioConTolerancia && $horaActual <= $horario->hora_fin;
        });
    }

    private function obtenerEvento(int $id): object
    {
        return DB::table('acceso.eventos')->where('id', $id)->first();
    }

    private function guardar(string $tabla, array $datos, ?int $id): object
    {
        $antes = $id ? DB::table($tabla)->where('id', $id)->first() : null;
        $datos['updated_at'] = now();
        if ($id) {
            DB::table($tabla)->where('id', $id)->update($datos);
            $despues = DB::table($tabla)->where('id', $id)->first();
            $this->auditar('acceso', $id ? 'ACTUALIZAR' : 'CREAR', $tabla, $id, $antes, $despues);
            return $despues;
        }
        $datos['created_at'] = now();
        $id = DB::table($tabla)->insertGetId($datos);
        $despues = DB::table($tabla)->where('id', $id)->first();
        $this->auditar('acceso', 'CREAR', $tabla, $id, null, $despues);
        return $despues;
    }

    private function buscar($query, ?string $busqueda, array $columnas): void
    {
        if (empty($busqueda)) return;
        $texto = mb_strtolower($busqueda);
        $query->where(function ($q) use ($columnas, $texto): void {
            foreach ($columnas as $columna) $q->orWhereRaw("LOWER({$columna}) LIKE ?", ["%{$texto}%"]);
        });
    }

    private function filtrarTexto($query, string $columna, mixed $valor): void
    {
        if (empty($valor)) return;
        is_array($valor) ? $query->whereIn($columna, array_filter($valor)) : $query->whereRaw("LOWER({$columna}) LIKE ?", ['%' . mb_strtolower($valor) . '%']);
    }

    private function filtrarBooleano($query, string $columna, mixed $valor): void
    {
        if ($valor === null || $valor === '') return;
        $booleanos = collect(is_array($valor) ? $valor : [$valor])->map(fn ($item) => filter_var($item, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE))->filter(fn ($item) => $item !== null)->values();
        if ($booleanos->isNotEmpty()) $query->whereIn($columna, $booleanos->all());
    }
}
