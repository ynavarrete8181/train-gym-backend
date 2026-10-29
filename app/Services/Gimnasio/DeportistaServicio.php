<?php

namespace App\Services\Gimnasio;

use App\Services\Concerns\RegistraAuditoria;
use Illuminate\Support\Facades\DB;

class DeportistaServicio
{
    use RegistraAuditoria;

    public function crear(array $datos)
    {
        $representante = $datos['representante_legal'] ?? null;
        $persona = $datos['persona'] ?? null;
        $ubicacion = $datos['ubicacion'] ?? null;
        unset($datos['representante_legal'], $datos['persona'], $datos['ubicacion']);

        return DB::transaction(function () use ($datos, $representante, $persona, $ubicacion) {
            $personaId = $this->resolverPersonaCliente($persona ?? []);
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
        unset($datos['representante_legal'], $datos['persona'], $datos['ubicacion']);

        return DB::transaction(function () use ($id, $datos, $representante, $persona, $ubicacion) {
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

        return $deportista;
    }

    private function sincronizarRepresentanteLegal(int $deportistaId, bool $requiere, ?array $datos): void
    {
        if (! $requiere) {
            DB::table('clientes.deportista_representantes')
                ->where('deportista_id', $deportistaId)
                ->where('activo', true)
                ->update([
                    'activo' => false,
                    'updated_at' => now(),
                ]);
            return;
        }

        if (! $datos) {
            return;
        }

        $personaId = $this->resolverPersonaRepresentante($datos);

        DB::table('clientes.deportista_representantes')
            ->where('deportista_id', $deportistaId)
            ->where('activo', true)
            ->where('representante_persona_id', '!=', $personaId)
            ->update([
                'activo' => false,
                'updated_at' => now(),
            ]);

        DB::table('clientes.deportista_representantes')->updateOrInsert(
            [
                'deportista_id' => $deportistaId,
                'representante_persona_id' => $personaId,
            ],
            [
                'tipo_relacion' => $datos['tipo_relacion'] ?? 'REPRESENTANTE_LEGAL',
                'es_principal' => true,
                'responsable_pago' => (bool) ($datos['responsable_pago'] ?? false),
                'activo' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    /**
     * Un documento identifica una única persona, incluso si se registró con espacios
     * o diferencias de mayúsculas. Rechazamos ambigüedades históricas.
     */
    private function buscarPersonaPorDocumento(string $identificacion): ?object
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

    private function guardarUbicacionPersona(int $personaId, ?array $ubicacion): void
    {
        if (! $ubicacion || ! isset($ubicacion['latitud'], $ubicacion['longitud'])) {
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

    private function actualizarPersonaCliente(int $personaId, array $datos): void
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
