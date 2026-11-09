<?php

namespace App\Services\Gimnasio;

use App\Services\Concerns\RegistraAuditoria;
use Illuminate\Support\Facades\DB;

class DeportistaServicio
{
    use RegistraAuditoria;

    public function crear(array $datos, ?int $personaExistenteId = null)
    {
        $representante = $datos['representante_legal'] ?? null;
        $persona = $datos['persona'] ?? null;
        $ubicacion = $datos['ubicacion'] ?? null;
        $tipoCliente = $datos['tipo_cliente'] ?? null;
        unset($datos['representante_legal'], $datos['persona'], $datos['ubicacion'], $datos['tipo_cliente']);

        return DB::transaction(function () use ($datos, $representante, $persona, $ubicacion, $tipoCliente, $personaExistenteId) {
            $personaId = $personaExistenteId ?? $this->resolverPersonaCliente($persona ?? []);
            $this->guardarUbicacionPersona($personaId, $ubicacion);
            $datos['persona_id'] = $personaId;
            $datos['usuario_id'] = $datos['usuario_id'] ?? null;
            // Si la persona ya tiene cuenta DEPORTISTA, enlazarla sin crear otra.
            if ($datos['usuario_id'] === null) {
                $usuarios = DB::table('seguridad.users as u')
                    ->join('seguridad.cpu_userrole as r', 'r.id_userrole', '=', 'u.usr_tipo')
                    ->where('u.persona_id', $personaId)->where('r.role', 'DEPORTISTA')
                    ->limit(2)->pluck('u.id');
                if ($usuarios->count() > 1) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'persona.identificacion' => 'Hay varias cuentas de deportista asociadas a esta persona. Revisa Seguridad antes de continuar.',
                    ]);
                }
                $datos['usuario_id'] = $usuarios->first();
            }
            $datos['created_at'] = now();
            $datos['updated_at'] = now();

            $id = DB::table('clientes.deportistas')->insertGetId($datos);
            $this->sincronizarRepresentanteLegal($id, (bool) ($datos['requiere_representante_legal'] ?? false), $representante);
            $this->aplicarTipoCliente($personaId, $tipoCliente);

            $cliente = $this->obtenerDeportistaConRelaciones($id);
            $this->auditar('clientes', 'CREAR', 'clientes.deportistas', $id, null, $cliente);

            return $cliente;
        });
    }

    public function actualizar(int $id, array $datos)
    {
        $representante = $datos['representante_legal'] ?? null;
        $persona = $datos['persona'] ?? null;
        $ubicacion = $datos['ubicacion'] ?? null;
        $tipoCliente = $datos['tipo_cliente'] ?? null;
        unset($datos['representante_legal'], $datos['persona'], $datos['ubicacion'], $datos['tipo_cliente']);

        return DB::transaction(function () use ($id, $datos, $representante, $persona, $ubicacion, $tipoCliente) {
            $antes = $this->obtenerDeportistaConRelaciones($id);
            $actual = DB::table('clientes.deportistas')->where('id', $id)->first();

            if ($actual?->persona_id) {
                $this->guardarUbicacionPersona((int) $actual->persona_id, $ubicacion);
            }

            if ($persona && $actual?->persona_id) {
                $this->actualizarPersonaCliente((int) $actual->persona_id, $persona);

                // Mantener las columnas de compatibilidad del usuario alineadas
                // con la identidad canónica cuando esa cuenta pertenece al cliente.
                if ($actual->usuario_id) {
                    DB::table('seguridad.users')
                        ->where('id', $actual->usuario_id)
                        ->where('persona_id', $actual->persona_id)
                        ->update([
                            'nombres' => trim((string) ($persona['nombres'] ?? '')) ?: null,
                            'apellidos' => trim((string) ($persona['apellidos'] ?? '')) ?: null,
                            'cedula' => trim((string) ($persona['identificacion'] ?? '')) ?: null,
                            'updated_at' => now(),
                        ]);
                }
            }

            $datos['updated_at'] = now();

            DB::table('clientes.deportistas')->where('id', $id)->update($datos);
            // Primero el rol: quien deja de ser tutor ya puede tener uno.
            if ($actual?->persona_id) {
                $this->aplicarTipoCliente((int) $actual->persona_id, $tipoCliente);
            }
            $this->sincronizarRepresentanteLegal($id, (bool) ($datos['requiere_representante_legal'] ?? false), $representante);

            $cliente = $this->obtenerDeportistaConRelaciones($id);
            $this->auditar('clientes', 'ACTUALIZAR', 'clientes.deportistas', $id, $antes, $cliente);

            return $cliente;
        });
    }

    public function obtenerDeportistaConRelaciones(int $id)
    {
        $deportista = DB::table('clientes.deportistas as d')
            ->leftJoin('personas.personas as p', 'd.persona_id', '=', 'p.id')
            ->leftJoin('seguridad.users as u', 'd.usuario_id', '=', 'u.id')
            ->leftJoin('institucional.sedes as s', 'd.sede_principal_id', '=', 's.id_sede')
            ->select(
                'd.*',
                'p.tipo_identificacion',
                'p.identificacion',
                DB::raw('COALESCE(NULLIF(TRIM(p.nombres), \'\'), p.nombre_completo) as nombres'),
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
                'u.name as usuario_nombre',
                'u.email as usuario_email',
                's.nombre as sede_nombre'
            )
            ->where('d.id', $id)
            ->first();

        if (! $deportista) {
            return null;
        }

        $representante = DB::table('clientes.deportista_representantes as dr')
            ->join('personas.personas as p', 'p.id', '=', 'dr.representante_persona_id')
            ->where('dr.deportista_id', $id)
            ->where('dr.activo', true)
            ->orderByDesc('dr.es_principal')
            ->first([
                'dr.id',
                'dr.representante_persona_id as persona_id',
                'dr.tipo_relacion',
                'dr.es_principal',
                'dr.responsable_pago',
                'p.tipo_identificacion',
                'p.identificacion',
                'p.nombres',
                'p.apellidos',
                'p.nombre_completo',
                'p.telefono',
                'p.email',
                'p.direccion',
            ]);

        $deportista->ubicacion = DB::table('personas.persona_ubicaciones')
            ->where('persona_id', $deportista->persona_id)
            ->first(['latitud', 'longitud', 'referencia']);

        $deportista->requiere_representante_legal = (bool) $deportista->requiere_representante_legal;
        $deportista->representante_legal = $representante;
        $deportista->tutor_id = $deportista->persona_id
            ? DB::table('clientes.tutores')->where('persona_id', $deportista->persona_id)->where('activo', true)->value('id')
            : null;
        $deportista->tipo_cliente = $deportista->tutor_id ? 'DEPORTISTA_TUTOR' : 'DEPORTISTA';
        $deportista->representados = $deportista->tutor_id
            ? $this->representadosDe((int) $deportista->persona_id)
            : [];
        $deportista->representados_anteriores = $deportista->persona_id
            ? $this->representadosAnterioresDe((int) $deportista->persona_id)
            : [];
        $deportista->historial_tutores = $this->historialTutores($id);

        return $deportista;
    }

    public function representadosDe(int $personaId)
    {
        return DB::table('clientes.deportista_representantes as dr')
            ->join('clientes.deportistas as d', 'd.id', '=', 'dr.deportista_id')
            ->leftJoin('personas.personas as p', 'p.id', '=', 'd.persona_id')
            ->where('dr.representante_persona_id', $personaId)
            ->where('dr.activo', true)
            ->orderBy('p.nombre_completo')
            ->get([
                'd.id',
                'd.codigo_deportista',
                'd.estado',
                'p.identificacion',
                'p.nombre_completo',
                'dr.tipo_relacion',
                'dr.responsable_pago',
            ]);
    }

    public function marcarTutor(int $personaId, string $estado = 'ACTIVO'): int
    {
        $existente = DB::table('clientes.tutores')->where('persona_id', $personaId)->first(['id', 'activo']);
        if (! $existente) {
            return (int) DB::table('clientes.tutores')->insertGetId([
                'persona_id' => $personaId,
                'estado' => $estado,
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        if (! $existente->activo) {
            DB::table('clientes.tutores')->where('id', $existente->id)
                ->update(['activo' => true, 'estado' => $estado, 'updated_at' => now()]);
        }

        return (int) $existente->id;
    }

    private function aplicarTipoCliente(int $personaId, ?string $tipoCliente): void
    {
        if ($tipoCliente === 'DEPORTISTA_TUTOR') {
            $this->marcarTutor($personaId);
            return;
        }
        if ($tipoCliente !== 'DEPORTISTA') {
            return;
        }

        $tieneRepresentados = DB::table('clientes.deportista_representantes')
            ->where('representante_persona_id', $personaId)->where('activo', true)->exists();
        if ($tieneRepresentados) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'tipo_cliente' => 'No puede dejar de ser tutor mientras tenga deportistas a su cargo.',
            ]);
        }
        DB::table('clientes.tutores')->where('persona_id', $personaId)
            ->update(['activo' => false, 'updated_at' => now()]);
    }

    private function sincronizarRepresentanteLegal(int $deportistaId, bool $requiere, ?array $datos): void
    {
        if (! $requiere) {
            $this->finalizarTutores($deportistaId);
            return;
        }

        if (! $datos) {
            return;
        }

        $personaCliente = (int) DB::table('clientes.deportistas')->where('id', $deportistaId)->value('persona_id');
        $personaSeleccionada = ! empty($datos['persona_id']) ? (int) $datos['persona_id'] : null;

        // Si el representante ya está asociado a este cliente y conserva su
        // documento, actualizar su ficha canónica en lugar de solo reutilizarla.
        // Nunca modificar por accidente a una persona ajena al vínculo.
        $actual = DB::table('clientes.deportista_representantes as dr')
            ->join('personas.personas as p', 'p.id', '=', 'dr.representante_persona_id')
            ->where('dr.deportista_id', $deportistaId)
            ->where('dr.activo', true)
            ->orderByDesc('dr.es_principal')
            ->first(['p.id', 'p.identificacion']);

        $documentoNuevo = trim((string) ($datos['identificacion'] ?? ''));
        $documentoActual = trim((string) ($actual?->identificacion ?? ''));
        // Sin documento no hay identidad confirmada: nunca editar a una persona
        // que puede representar también a otros deportistas.
        $mismaPersona = $actual && $documentoNuevo !== ''
            && mb_strtolower($documentoNuevo) === mb_strtolower($documentoActual);

        if ($personaSeleccionada) {
            // Seleccionar un tutor existente no modifica sus datos personales.
            $personaId = $personaSeleccionada;
        } elseif ($mismaPersona) {
            $personaId = (int) $actual->id;
            $nombres = trim((string) ($datos['nombres'] ?? ''));
            $apellidos = trim((string) ($datos['apellidos'] ?? ''));
            DB::table('personas.personas')->where('id', $personaId)->update([
                'tipo_identificacion' => $datos['tipo_identificacion'] ?? null,
                'identificacion' => $documentoNuevo !== '' ? $documentoNuevo : null,
                'nombres' => $nombres !== '' ? $nombres : null,
                'apellidos' => $apellidos !== '' ? $apellidos : null,
                'nombre_completo' => trim($nombres . ' ' . $apellidos),
                'telefono' => trim((string) ($datos['telefono'] ?? '')) ?: null,
                'email' => trim((string) ($datos['email'] ?? '')) ?: null,
                'direccion' => trim((string) ($datos['direccion'] ?? '')) ?: null,
                'updated_at' => now(),
            ]);
        } else {
            // Cambio de documento o representante: resolver identidad sin
            // sobrescribir los datos de otra persona registrada previamente.
            $personaId = $this->resolverPersonaRepresentante($datos);
        }

        if ($personaId === $personaCliente) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'representante_legal.persona_id' => 'Un cliente no puede ser su propio tutor.',
            ]);
        }

        if (DB::table('clientes.tutores')->where('persona_id', $personaCliente)->where('activo', true)->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'representante_legal.persona_id' => 'Un tutor no puede tener tutor.',
            ]);
        }

        $tutorTieneTutor = DB::table('clientes.deportista_representantes as dr')
            ->join('clientes.deportistas as d', 'd.id', '=', 'dr.deportista_id')
            ->where('d.persona_id', $personaId)
            ->where('dr.activo', true)
            ->exists();
        if ($tutorTieneTutor) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'representante_legal.persona_id' => 'Esta persona tiene tutor, por lo que no puede ser tutor de otro cliente.',
            ]);
        }

        $this->marcarTutor($personaId);

        $this->finalizarTutores($deportistaId, $personaId);

        $vinculo = [
            'tipo_relacion' => $datos['tipo_relacion'] ?? 'TUTOR',
            'es_principal' => true,
            'responsable_pago' => (bool) ($datos['responsable_pago'] ?? false),
            'updated_at' => now(),
        ];
        $vigente = DB::table('clientes.deportista_representantes')
            ->where('deportista_id', $deportistaId)
            ->where('representante_persona_id', $personaId)
            ->where('activo', true);

        if ((clone $vigente)->exists()) {
            $vigente->update($vinculo);
            return;
        }

        // Cada periodo es una fila nueva para conservar el historial si el tutor regresa.
        DB::table('clientes.deportista_representantes')->insert($vinculo + [
            'deportista_id' => $deportistaId,
            'representante_persona_id' => $personaId,
            'activo' => true,
            'fecha_inicio' => now(),
            'created_at' => now(),
        ]);
    }

    /** Cierra los vínculos activos con su fecha; nunca los elimina. */
    public function finalizarTutores(int $deportistaId, ?int $exceptoPersonaId = null, ?string $motivo = null): int
    {
        return DB::table('clientes.deportista_representantes')
            ->where('deportista_id', $deportistaId)
            ->where('activo', true)
            ->when($exceptoPersonaId, fn ($q) => $q->where('representante_persona_id', '!=', $exceptoPersonaId))
            ->update([
                'activo' => false,
                'fecha_fin' => now(),
                'motivo_fin' => $motivo,
                'finalizado_por' => request()->user()?->id,
                'updated_at' => now(),
            ]);
    }

    public function historialTutores(int $deportistaId)
    {
        return DB::table('clientes.deportista_representantes as dr')
            ->join('personas.personas as p', 'p.id', '=', 'dr.representante_persona_id')
            ->leftJoin('seguridad.users as u', 'u.id', '=', 'dr.finalizado_por')
            ->where('dr.deportista_id', $deportistaId)
            ->orderByDesc('dr.activo')
            ->orderByDesc('dr.fecha_inicio')
            ->orderByDesc('dr.id')
            ->get([
                'dr.id',
                'dr.representante_persona_id as persona_id',
                'p.nombre_completo',
                'p.identificacion',
                'dr.tipo_relacion',
                'dr.responsable_pago',
                'dr.activo',
                'dr.fecha_inicio',
                'dr.fecha_fin',
                'dr.motivo_fin',
                'u.name as finalizado_por_nombre',
            ]);
    }

    public function representadosAnterioresDe(int $personaId)
    {
        return DB::table('clientes.deportista_representantes as dr')
            ->join('clientes.deportistas as d', 'd.id', '=', 'dr.deportista_id')
            ->leftJoin('personas.personas as p', 'p.id', '=', 'd.persona_id')
            ->where('dr.representante_persona_id', $personaId)
            ->where('dr.activo', false)
            ->orderByDesc('dr.fecha_fin')
            ->orderByDesc('dr.id')
            ->get([
                'dr.id as vinculo_id',
                'd.id',
                'd.codigo_deportista',
                'd.estado',
                'p.identificacion',
                'p.nombre_completo',
                'dr.tipo_relacion',
                'dr.fecha_inicio',
                'dr.fecha_fin',
                'dr.motivo_fin',
            ]);
    }

    /**
     * Un documento identifica una única persona, incluso si se registró con espacios
     * o diferencias de mayúsculas. Rechazamos ambigüedades históricas.
     */
    public function buscarPersonaPorDocumento(string $identificacion): ?object
    {
        if ($identificacion === '') {
            return null;
        }

        $coincidencias = DB::table('personas.personas')
            ->whereRaw('LOWER(TRIM(identificacion)) = LOWER(TRIM(?))', [$identificacion])
            ->limit(2)->get(['id', 'identificacion']);

        if ($coincidencias->count() > 1) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'persona.identificacion' => 'Existen personas duplicadas con esta identificación. Solicita una revisión antes de continuar.',
            ]);
        }

        return $coincidencias->first();
    }

    private function validarDocumentoUnico(string $identificacion, int $personaId): void
    {
        if ($identificacion !== '' && DB::table('personas.personas')
            ->where('id', '<>', $personaId)
            ->whereRaw('LOWER(TRIM(identificacion)) = LOWER(TRIM(?))', [$identificacion])
            ->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'persona.identificacion' => 'La identificación pertenece a otra persona.',
            ]);
        }
    }

    public function guardarUbicacionPersona(int $personaId, ?array $ubicacion): void
    {
        if ($ubicacion === null) {
            return;
        }
        if (! isset($ubicacion['latitud'], $ubicacion['longitud'])) {
            DB::table('personas.persona_ubicaciones')->where('persona_id', $personaId)->delete();
            return;
        }
        DB::table('personas.persona_ubicaciones')->updateOrInsert(
            ['persona_id' => $personaId],
            [
                'latitud' => $ubicacion['latitud'],
                'longitud' => $ubicacion['longitud'],
                'referencia' => $ubicacion['referencia'] ?? null,
                'ubicacion_voluntaria' => true,
                'updated_at' => now(),
            ]
        );
    }

    private function resolverPersonaCliente(array $datos): int
    {
        $identificacion = trim((string) ($datos['identificacion'] ?? ''));
        $email = trim((string) ($datos['email'] ?? ''));
        $nombres = trim((string) ($datos['nombres'] ?? ''));
        $apellidos = trim((string) ($datos['apellidos'] ?? ''));
        $nombreCompleto = trim($nombres . ' ' . $apellidos);

        $persona = null;

        $persona = $this->buscarPersonaPorDocumento($identificacion);

        // El correo puede ser compartido por familiares: nunca acredita identidad.
        // Si el documento existe, resolvemos únicamente por identificación.
        if ($persona) {
            if (DB::table('clientes.deportistas')->where('persona_id', $persona->id)->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'persona.identificacion' => 'Esta persona ya está registrada como cliente.',
                ]);
            }

            // La creación de un cliente no debe sobrescribir una persona ya existente.
            // Sus datos se editan explícitamente desde la ficha correspondiente.
            return (int) $persona->id;
        }

        return (int) DB::table('personas.personas')->insertGetId([
            'tipo_identificacion' => $datos['tipo_identificacion'] ?? ($identificacion !== '' ? 'CEDULA' : null),
            'identificacion' => $identificacion !== '' ? $identificacion : null,
            'nombres' => $nombres !== '' ? $nombres : null,
            'apellidos' => $apellidos !== '' ? $apellidos : null,
            'nombre_completo' => $nombreCompleto,
            'fecha_nacimiento' => $datos['fecha_nacimiento'] ?? null,
            'genero' => $datos['genero'] ?? null,
            'telefono' => trim((string) ($datos['telefono'] ?? '')) ?: null,
            'email' => $email !== '' ? mb_strtolower($email) : null,
            'direccion' => trim((string) ($datos['direccion'] ?? '')) ?: null,
            'provincia_codigo' => $datos['provincia_codigo'] ?? null,
            'canton_codigo' => $datos['canton_codigo'] ?? null,
            'parroquia_codigo' => $datos['parroquia_codigo'] ?? null,
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function actualizarPersonaCliente(int $personaId, array $datos): void
    {
        $nombres = trim((string) ($datos['nombres'] ?? ''));
        $apellidos = trim((string) ($datos['apellidos'] ?? ''));
        $nombreCompleto = trim($nombres . ' ' . $apellidos);
        $this->validarDocumentoUnico(trim((string) ($datos['identificacion'] ?? '')), $personaId);

        DB::table('personas.personas')->where('id', $personaId)->update([
            'tipo_identificacion' => $datos['tipo_identificacion'] ?? null,
            'identificacion' => trim((string) ($datos['identificacion'] ?? '')) ?: null,
            'nombres' => $nombres !== '' ? $nombres : null,
            'apellidos' => $apellidos !== '' ? $apellidos : null,
            'nombre_completo' => $nombreCompleto,
            'fecha_nacimiento' => $datos['fecha_nacimiento'] ?? null,
            'genero' => $datos['genero'] ?? null,
            'telefono' => trim((string) ($datos['telefono'] ?? '')) ?: null,
            'email' => trim((string) ($datos['email'] ?? '')) ?: null,
            'direccion' => trim((string) ($datos['direccion'] ?? '')) ?: null,
            'provincia_codigo' => $datos['provincia_codigo'] ?? null,
            'canton_codigo' => $datos['canton_codigo'] ?? null,
            'parroquia_codigo' => $datos['parroquia_codigo'] ?? null,
            'updated_at' => now(),
        ]);
    }

    private function resolverPersonaRepresentante(array $datos): int
    {
        $identificacion = trim((string) ($datos['identificacion'] ?? ''));
        $email = trim((string) ($datos['email'] ?? ''));
        $nombres = trim((string) ($datos['nombres'] ?? ''));
        $apellidos = trim((string) ($datos['apellidos'] ?? ''));
        $nombreCompleto = trim($nombres . ' ' . $apellidos);

        $persona = null;

        $persona = $this->buscarPersonaPorDocumento($identificacion);

        // Nunca vincular o sobrescribir un representante por correo o nombre;
        // ambos pueden coincidir entre personas distintas.

        $payload = [
            'tipo_identificacion' => $datos['tipo_identificacion'] ?? ($identificacion !== '' ? 'CEDULA' : null),
            'identificacion' => $identificacion !== '' ? $identificacion : null,
            'nombres' => $nombres !== '' ? $nombres : null,
            'apellidos' => $apellidos !== '' ? $apellidos : null,
            'nombre_completo' => $nombreCompleto,
            'telefono' => trim((string) ($datos['telefono'] ?? '')) ?: null,
            'email' => $email !== '' ? $email : null,
            'direccion' => trim((string) ($datos['direccion'] ?? '')) ?: null,
            'provincia_codigo' => $datos['provincia_codigo'] ?? null,
            'canton_codigo' => $datos['canton_codigo'] ?? null,
            'parroquia_codigo' => $datos['parroquia_codigo'] ?? null,
            'activo' => true,
            'updated_at' => now(),
        ];

        if ($persona) {
            // La relación reutiliza la identidad confirmada sin editar su ficha.
            return (int) $persona->id;
        }

        $payload['created_at'] = now();

        return (int) DB::table('personas.personas')->insertGetId($payload);
    }
}
