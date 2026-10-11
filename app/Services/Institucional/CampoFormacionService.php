<?php

namespace App\Services\Institucional;

use App\Services\Concerns\RegistraAuditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CampoFormacionService
{
    use RegistraAuditoria;

    public function listar(): array
    {
        return [
            'campos_amplios' => DB::table('institucional.campos_amplios')->orderBy('nombre')->get(),
        ];
    }

    public function guardarAmplio(array $datos, ?int $id = null): object
    {
        $codigo = $this->codigo($datos['nombre']);
        $q = DB::table('institucional.campos_amplios')->where(fn ($q) => $q->where('codigo', $codigo)->orWhereRaw('LOWER(nombre) = ?', [mb_strtolower(trim($datos['nombre']))]));
        if ($id) $q->where('id_campo_amplio', '<>', $id);
        if ($q->exists()) throw ValidationException::withMessages(['nombre' => 'Ya existe un campo amplio con ese nombre.']);
        $antes = $id ? DB::table('institucional.campos_amplios')->where('id_campo_amplio', $id)->first() : null;
        $payload = ['codigo' => $codigo, 'nombre' => trim($datos['nombre']), 'activo' => (bool) $datos['activo'], 'updated_at' => now()];
        if ($id) DB::table('institucional.campos_amplios')->where('id_campo_amplio', $id)->update($payload);
        else { $payload['created_at'] = now(); $id = DB::table('institucional.campos_amplios')->insertGetId($payload, 'id_campo_amplio'); }
        $despues = DB::table('institucional.campos_amplios')->where('id_campo_amplio', $id)->first();
        $this->auditar('institucional', $antes ? 'ACTUALIZAR' : 'CREAR', 'institucional.campos_amplios', $id, $antes, $despues);
        return $despues;
    }

    public function cambiarEstado(int $id, bool $activo): void
    {
        DB::table('institucional.campos_amplios')
            ->where('id_campo_amplio', $id)
            ->update(['activo' => $activo, 'updated_at' => now()]);
        $this->auditar('institucional', 'ACTUALIZAR', 'institucional.campos_amplios', $id, null, null, 'Cambio de estado.');
    }

    private function codigo(string $nombre): string
    {
        return Str::of(Str::ascii($nombre))->upper()->replaceMatches('/[^A-Z0-9]+/', '_')->trim('_')->limit(120, '')->toString();
    }
}
