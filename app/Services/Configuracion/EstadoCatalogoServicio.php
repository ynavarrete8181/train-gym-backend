<?php

namespace App\Services\Configuracion;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EstadoCatalogoServicio
{
    public function idPorValor(string $entidad, string $valorInterno): int
    {
        $estado = $this->porValor($entidad, $valorInterno);
        if (! $estado) {
            throw ValidationException::withMessages([
                'estado' => "El estado {$valorInterno} no está configurado para {$entidad}.",
            ]);
        }
        return (int) $estado->id;
    }

    public function porValor(string $entidad, string $valorInterno): ?object
    {
        return DB::table('configuracion.estados_catalogo')
            ->where('entidad', strtoupper($entidad))
            ->where('valor_interno', strtoupper($valorInterno))
            ->where('activo', true)
            ->first();
    }

    public function aplicar(array $datos, string $entidad, string $campoTexto = 'estado', string $campoId = 'estado_id'): array
    {
        if (! array_key_exists($campoTexto, $datos) || $datos[$campoTexto] === null || $datos[$campoTexto] === '') {
            return $datos;
        }
        $datos[$campoTexto] = strtoupper((string) $datos[$campoTexto]);
        $datos[$campoId] = $this->idPorValor($entidad, $datos[$campoTexto]);
        return $datos;
    }
}
