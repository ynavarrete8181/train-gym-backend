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
        unset($datos['representante_legal'], $datos['persona']);

        return DB::transaction(function () use ($datos, $representante, $persona) {
            $personaId = $this->resolverPersonaCliente($persona ?? []);
            $datos['persona_id'] = $personaId;
            $datos['usuario_id'] = $datos['usuario_id'] ?? null;
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
        unset($datos['representante_legal'], $datos['persona']);

        return DB::transaction(function () use ($id, $datos, $representante, $persona) {
            $antes = $this->obtenerDeportistaConRelaciones($id);
            $actual = DB::table('clientes.deportistas')->where('id', $id)->first();

            if ($persona && $actual?->persona_id) {
                $this->actualizarPersonaCliente((int) $actual->persona_id, $persona);
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

    private function resolverPersonaCliente(array $datos): int
    {
        $identificacion = trim((string) ($datos['identificacion'] ?? ''));
        $email = trim((string) ($datos['email'] ?? ''));
        $nombres = trim((string) ($datos['nombres'] ?? ''));
        $apellidos = trim((string) ($datos['apellidos'] ?? ''));
        $nombreCompleto = trim($nombres . ' ' . $apellidos);

        $persona = null;

        if ($identificacion !== '') {
            $persona = DB::table('personas.personas')
                ->whereRaw('LOWER(TRIM(identificacion)) = LOWER(TRIM(?))', [$identificacion])
                ->first();
        }

        if (! $persona && $email !== '') {
            $persona = DB::table('personas.personas')
                ->whereNotNull('email')
                ->whereRaw('LOWER(TRIM(email)) = LOWER(TRIM(?))', [$email])
                ->first();
        }

        if ($persona) {
            if (DB::table('clientes.deportistas')->where('persona_id', $persona->id)->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'persona.identificacion' => 'Esta persona ya está registrada como cliente.',
                ]);
            }

            $this->actualizarPersonaCliente((int) $persona->id, $datos);
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

        if ($identificacion !== '') {
            $persona = DB::table('personas.personas')
                ->whereRaw('LOWER(TRIM(identificacion)) = LOWER(TRIM(?))', [$identificacion])
                ->first();
        }

        if (! $persona && $email !== '') {
            $persona = DB::table('personas.personas')
                ->whereNotNull('email')
                ->whereRaw('LOWER(TRIM(email)) = LOWER(TRIM(?))', [$email])
                ->first();
        }

        if (! $persona && $nombreCompleto !== '') {
            $persona = DB::table('personas.personas')
                ->whereRaw('LOWER(TRIM(nombre_completo)) = LOWER(TRIM(?))', [$nombreCompleto])
                ->first();
        }

        $payload = [
            'tipo_identificacion' => $datos['tipo_identificacion'] ?? ($identificacion !== '' ? 'CEDULA' : null),
            'identificacion' => $identificacion !== '' ? $identificacion : null,
            'nombres' => $nombres !== '' ? $nombres : null,
            'apellidos' => $apellidos !== '' ? $apellidos : null,
            'nombre_completo' => $nombreCompleto,
            'telefono' => trim((string) ($datos['telefono'] ?? '')) ?: null,
            'email' => $email !== '' ? $email : null,
            'direccion' => trim((string) ($datos['direccion'] ?? '')) ?: null,
            'activo' => true,
            'updated_at' => now(),
        ];

        if ($persona) {
            DB::table('personas.personas')->where('id', $persona->id)->update($payload);
            return (int) $persona->id;
        }

        $payload['created_at'] = now();

        return (int) DB::table('personas.personas')->insertGetId($payload);
    }
}
