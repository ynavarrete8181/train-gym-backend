<?php

namespace App\Services\Gimnasio;

use App\Services\Concerns\RegistraAuditoria;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El tutor es una persona con este rol; solo tiene ficha de cliente deportivo
 * si también entrena. Nunca se registra en clientes.deportistas por ser tutor.
 */
class TutorServicio
{
    use RegistraAuditoria;

    public function __construct(private readonly DeportistaServicio $deportistas)
    {
    }

    /** Candidatos a tutor: tutores activos y clientes adultos o sin fecha registrada. */
    public function buscar(string $texto, ?int $excluirPersonaId = null)
    {
        $patron = '%' . mb_strtolower(trim($texto)) . '%';
        $mayorDesde = now('America/Guayaquil')->subYears(18)->toDateString();

        return DB::table('personas.personas as p')
            ->leftJoin('clientes.tutores as t', function ($join): void {
                $join->on('t.persona_id', '=', 'p.id')->where('t.activo', true);
            })
            ->leftJoin('clientes.deportistas as d', 'd.persona_id', '=', 'p.id')
            ->where(function ($q) use ($mayorDesde): void {
                $q->whereNotNull('t.id')->orWhere(function ($cliente) use ($mayorDesde): void {
                    $cliente->whereNotNull('d.id')->where(function ($edad) use ($mayorDesde): void {
                        $edad->whereNull('p.fecha_nacimiento')->orWhereDate('p.fecha_nacimiento', '<=', $mayorDesde);
                    });
                });
            })
            ->where(function ($q) use ($patron): void {
                $q->whereRaw("LOWER(COALESCE(p.identificacion, '')) LIKE ?", [$patron])
                    ->orWhereRaw("LOWER(COALESCE(p.nombre_completo, '')) LIKE ?", [$patron]);
            })
            // Quien tiene tutor no puede ser tutor de otro cliente.
            ->whereNotExists(function ($q): void {
                $q->selectRaw('1')->from('clientes.deportista_representantes as dr')
                    ->whereColumn('dr.deportista_id', 'd.id')
                    ->where('dr.activo', true);
            })
            ->when($excluirPersonaId, fn ($q) => $q->where('p.id', '!=', $excluirPersonaId))
            ->orderBy('p.nombre_completo')
            ->limit(15)
            ->get([
                'p.id as persona_id',
                'p.tipo_identificacion',
                'p.identificacion',
                'p.nombres',
                'p.apellidos',
                'p.nombre_completo',
                'p.telefono',
                'p.email',
                'p.direccion',
                DB::raw('t.id IS NOT NULL as es_tutor'),
                DB::raw('d.id IS NOT NULL as es_deportista'),
            ]);
    }

    public function crear(array $datos): object
    {
        return DB::transaction(function () use ($datos): object {
            $persona = $datos['persona'];
            $this->validarMayorDeEdad($persona['fecha_nacimiento'] ?? null);
            $existente = $this->deportistas->buscarPersonaPorDocumento(trim((string) $persona['identificacion']));

            if ($existente) {
                $tutorActivo = DB::table('clientes.tutores')
                    ->where('persona_id', $existente->id)->where('activo', true)->exists();
                if ($tutorActivo) {
                    throw ValidationException::withMessages([
                        'persona.identificacion' => 'Esta persona ya está registrada como tutor. Búscala y selecciónala.',
                    ]);
                }
                $personaId = (int) $existente->id;
            } else {
                $nombres = trim((string) ($persona['nombres'] ?? ''));
                $apellidos = trim((string) ($persona['apellidos'] ?? ''));
                $personaId = (int) DB::table('personas.personas')->insertGetId([
                    'tipo_identificacion' => $persona['tipo_identificacion'] ?? 'CEDULA',
                    'identificacion' => trim((string) $persona['identificacion']),
                    'nombres' => $nombres !== '' ? $nombres : null,
                    'apellidos' => $apellidos !== '' ? $apellidos : null,
                    'nombre_completo' => trim($nombres . ' ' . $apellidos),
                    'fecha_nacimiento' => $persona['fecha_nacimiento'] ?? null,
                    'genero' => $persona['genero'] ?? null,
                    'telefono' => trim((string) ($persona['telefono'] ?? '')) ?: null,
                    'email' => trim((string) ($persona['email'] ?? '')) ?: null,
                    'direccion' => trim((string) ($persona['direccion'] ?? '')) ?: null,
                    'provincia_codigo' => $persona['provincia_codigo'] ?? null,
                    'canton_codigo' => $persona['canton_codigo'] ?? null,
                    'parroquia_codigo' => $persona['parroquia_codigo'] ?? null,
                    'activo' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->deportistas->guardarUbicacionPersona($personaId, $datos['ubicacion'] ?? null);
            }

            $id = $this->deportistas->marcarTutor($personaId, $datos['estado'] ?? 'ACTIVO');
            $tutor = $this->obtener($id);
            $this->auditar('clientes', 'CREAR', 'clientes.tutores', $id, null, $tutor);

            return $tutor;
        });
    }

    public function obtener(int $id): ?object
    {
        $tutor = DB::table('clientes.tutores as t')
            ->join('personas.personas as p', 'p.id', '=', 't.persona_id')
            ->leftJoin('clientes.deportistas as d', 'd.persona_id', '=', 't.persona_id')
            ->where('t.id', $id)
            ->first([
                't.id as tutor_id',
                't.persona_id',
                't.estado',
                't.activo',
                'd.id',
                'p.tipo_identificacion',
                'p.identificacion',
                DB::raw("COALESCE(NULLIF(TRIM(p.nombres), ''), p.nombre_completo) as nombres"),
                'p.apellidos',
                'p.nombre_completo',
                'p.fecha_nacimiento as persona_fecha_nacimiento',
                'p.genero as persona_genero',
                'p.telefono as persona_telefono',
                'p.email as persona_email',
                'p.direccion as persona_direccion',
                'p.provincia_codigo',
                'p.canton_codigo',
                'p.parroquia_codigo',
            ]);

        if (! $tutor) {
            return null;
        }

        $tutor->tipo_cliente = $tutor->id ? 'DEPORTISTA_TUTOR' : 'TUTOR';
        $tutor->ubicacion = DB::table('personas.persona_ubicaciones')
            ->where('persona_id', $tutor->persona_id)
            ->first(['latitud', 'longitud', 'referencia']);
        $tutor->representados = $this->deportistas->representadosDe((int) $tutor->persona_id);
        $tutor->representados_anteriores = $this->deportistas->representadosAnterioresDe((int) $tutor->persona_id);

        return $tutor;
    }

    /** Actualiza al tutor y, si empieza a entrenar, crea su ficha de deportista. */
    public function actualizar(int $id, array $datos): object
    {
        return DB::transaction(function () use ($id, $datos): object {
            $antes = $this->obtener($id);
            if (! $antes || $antes->id) {
                throw ValidationException::withMessages([
                    'tutor_id' => 'Este tutor ya tiene ficha de deportista. Edítalo desde esa ficha.',
                ]);
            }

            $persona = $datos['persona'];
            $tipo = $datos['tipo_cliente'] ?? 'TUTOR';
            $this->validarMayorDeEdad($persona['fecha_nacimiento'] ?? null);

            $personaId = (int) $antes->persona_id;
            $this->deportistas->actualizarPersonaCliente($personaId, $persona);
            $this->deportistas->guardarUbicacionPersona($personaId, $datos['ubicacion'] ?? null);
            DB::table('clientes.tutores')->where('id', $id)->update([
                'estado' => $datos['estado'] ?? $antes->estado,
                'updated_at' => now(),
            ]);

            if ($tipo !== 'TUTOR') {
                $this->deportistas->crear([
                    'codigo_deportista' => app(CodigoClienteServicio::class)->generar(),
                    'fecha_nacimiento' => $persona['fecha_nacimiento'] ?? null,
                    'genero' => $persona['genero'] ?? null,
                    'telefono' => $persona['telefono'] ?? null,
                    'estado' => $datos['estado'] ?? $antes->estado,
                    'requiere_representante_legal' => false,
                    'tipo_cliente' => $tipo,
                ], $personaId);
            }

            $tutor = $this->obtener($id);
            $this->auditar('clientes', 'ACTUALIZAR', 'clientes.tutores', $id, $antes, $tutor);

            return $tutor;
        });
    }

    /** Quita el tutor vigente de un cliente adulto; el vínculo queda en su historial. */
    public function quitarDeCliente(int $deportistaId, ?string $motivo): object
    {
        return DB::transaction(function () use ($deportistaId, $motivo): object {
            $cliente = DB::table('clientes.deportistas')->where('id', $deportistaId)->lockForUpdate()->first(['id', 'persona_id']);

            if (! $cliente) {
                throw ValidationException::withMessages(['deportista_id' => 'El cliente no existe.']);
            }

            $fechaNacimiento = DB::table('personas.personas')->where('id', $cliente->persona_id)->value('fecha_nacimiento');
            $mayorDesde = now('America/Guayaquil')->subYears(18)->toDateString();
            if ($fechaNacimiento && substr((string) $fechaNacimiento, 0, 10) > $mayorDesde) {
                throw ValidationException::withMessages([
                    'tutor' => 'El cliente es menor de edad: no puede quedar sin tutor. Usa Cambiar tutor para seleccionar otro.',
                ]);
            }

            $antes = $this->deportistas->historialTutores($deportistaId);
            $motivoLimpio = trim((string) $motivo) ?: null;
            if ($this->deportistas->finalizarTutores($deportistaId, null, $motivoLimpio) === 0) {
                throw ValidationException::withMessages(['tutor' => 'El cliente no tiene un tutor vigente.']);
            }

            DB::table('clientes.deportistas')->where('id', $deportistaId)->update([
                'requiere_representante_legal' => false,
                'updated_at' => now(),
            ]);

            $cliente = $this->deportistas->obtenerDeportistaConRelaciones($deportistaId);
            $this->auditar('clientes', 'QUITAR_TUTOR', 'clientes.deportista_representantes', $deportistaId, $antes, $cliente->historial_tutores, $motivoLimpio);

            return $cliente;
        });
    }

    /** Directorio de Clientes: deportistas y tutores, cada persona en una sola fila. */
    public function directorio(array $filtros): array
    {
        $conDeportista = DB::table('clientes.deportistas as d')
            ->leftJoin('personas.personas as p', 'p.id', '=', 'd.persona_id')
            ->leftJoin('seguridad.users as u', 'u.id', '=', 'd.usuario_id')
            ->leftJoin('clientes.tutores as t', function ($join): void {
                $join->on('t.persona_id', '=', 'd.persona_id')->where('t.activo', true);
            })
            ->selectRaw("d.id, t.id as tutor_id, d.persona_id, d.codigo_deportista::varchar as codigo_deportista,
                COALESCE(p.identificacion, u.cedula)::varchar as cedula, p.nombres::varchar as nombres, p.apellidos::varchar as apellidos,
                COALESCE(p.nombre_completo, u.name)::varchar as name, COALESCE(p.email, u.email)::varchar as usuario_email,
                COALESCE(d.telefono, p.telefono)::varchar as telefono, d.estado::varchar as estado,
                CASE WHEN t.id IS NULL THEN 'DEPORTISTA' ELSE 'DEPORTISTA_TUTOR' END as tipo_cliente, d.created_at");

        $soloTutores = DB::table('clientes.tutores as t')
            ->join('personas.personas as p', 'p.id', '=', 't.persona_id')
            ->where('t.activo', true)
            ->whereNotExists(function ($q): void {
                $q->selectRaw('1')->from('clientes.deportistas as d')->whereColumn('d.persona_id', 't.persona_id');
            })
            ->selectRaw("NULL::bigint as id, t.id as tutor_id, t.persona_id, NULL::varchar as codigo_deportista,
                p.identificacion::varchar as cedula, p.nombres::varchar as nombres, p.apellidos::varchar as apellidos,
                p.nombre_completo::varchar as name, p.email::varchar as usuario_email,
                p.telefono::varchar as telefono, t.estado::varchar as estado,
                'TUTOR' as tipo_cliente, t.created_at");

        $base = DB::query()->fromSub($conDeportista->unionAll($soloTutores), 'c');
        $consulta = clone $base;

        if (! empty($filtros['busqueda'])) {
            $patron = '%' . mb_strtolower((string) $filtros['busqueda']) . '%';
            $consulta->where(function ($q) use ($patron): void {
                foreach (['codigo_deportista', 'cedula', 'name', 'usuario_email', 'telefono'] as $columna) {
                    $q->orWhereRaw("LOWER(COALESCE({$columna}, '')) LIKE ?", [$patron]);
                }
            });
        }

        foreach (['cedula' => 'cedula', 'nombres' => 'name', 'telefono' => 'telefono', 'estado' => 'estado', 'tipo_cliente' => 'tipo_cliente'] as $filtro => $columna) {
            $valor = $filtros[$filtro] ?? null;
            if (is_array($valor) && array_filter($valor)) {
                $consulta->whereIn($columna, array_filter($valor));
            } elseif (is_string($valor) && $valor !== '') {
                $consulta->whereRaw("LOWER(COALESCE({$columna}, '')) LIKE ?", ['%' . mb_strtolower($valor) . '%']);
            }
        }

        /** @var LengthAwarePaginator $paginador */
        $paginador = $consulta
            ->orderByDesc('created_at')
            ->orderByDesc('tutor_id')
            ->paginate($filtros['per_page'] ?? 15, ['*'], 'page', $filtros['page'] ?? 1);

        $opciones = fn (string $columna) => (clone $base)->whereNotNull($columna)->distinct()->orderBy($columna)->pluck($columna)->values();

        return [
            'paginador' => $paginador,
            'opciones_filtro' => [
                'cedula' => $opciones('cedula'),
                'nombres' => $opciones('name'),
                'telefono' => $opciones('telefono'),
            ],
        ];
    }

    private function validarMayorDeEdad(?string $fechaNacimiento): void
    {
        if ($fechaNacimiento && $fechaNacimiento > now('America/Guayaquil')->subYears(18)->toDateString()) {
            throw ValidationException::withMessages([
                'persona.fecha_nacimiento' => 'Un tutor debe ser mayor de edad.',
            ]);
        }
    }
}
