<?php

namespace App\Services\Comunicaciones;

use App\Services\Concerns\RegistraAuditoria;
use Illuminate\Support\Facades\DB;

class ComunicacionServicio
{
    use RegistraAuditoria;

    public function listarTipos(array $filtros)
    {
        $query = DB::table('comunicaciones.tipos_comunicacion');
        $this->buscar($query, $filtros['busqueda'] ?? null, ['codigo', 'nombre', 'canal_preferido']);
        $this->filtrarTexto($query, 'canal_preferido', $filtros['canal'] ?? null);
        $this->filtrarBooleano($query, 'activo', $filtros['estado'] ?? null);

        return $query->orderBy('nombre')->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function listarSegmentos(array $filtros)
    {
        $query = DB::table('comunicaciones.segmentos');
        $this->buscar($query, $filtros['busqueda'] ?? null, ['codigo', 'nombre', 'descripcion']);
        $this->filtrarBooleano($query, 'activo', $filtros['estado'] ?? null);

        return $query->orderBy('nombre')->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function listarMensajes(array $filtros)
    {
        $query = DB::table('comunicaciones.mensajes')
            ->leftJoin('comunicaciones.tipos_comunicacion as t', 't.id', '=', 'comunicaciones.mensajes.tipo_id')
            ->leftJoin('comunicaciones.segmentos as s', 's.id', '=', 'comunicaciones.mensajes.segmento_id')
            ->leftJoin('seguridad.users as u', 'u.id', '=', 'comunicaciones.mensajes.created_by')
            ->select('comunicaciones.mensajes.*', 't.nombre as tipo_nombre', 's.nombre as segmento_nombre', 'u.name as creado_por');

        $this->buscar($query, $filtros['busqueda'] ?? null, ['comunicaciones.mensajes.titulo', 'comunicaciones.mensajes.canal', 'comunicaciones.mensajes.estado', 't.nombre', 's.nombre']);
        $this->filtrarTexto($query, 'comunicaciones.mensajes.canal', $filtros['canal'] ?? null);
        $this->filtrarTexto($query, 'comunicaciones.mensajes.estado', $filtros['estado'] ?? null);

        return $query->orderByDesc('comunicaciones.mensajes.created_at')->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function listarProgramaciones(array $filtros)
    {
        $query = DB::table('comunicaciones.programaciones')
            ->join('comunicaciones.mensajes', 'comunicaciones.programaciones.mensaje_id', '=', 'comunicaciones.mensajes.id')
            ->select('comunicaciones.programaciones.*', 'comunicaciones.mensajes.titulo as mensaje_titulo');

        $this->buscar($query, $filtros['busqueda'] ?? null, ['comunicaciones.programaciones.nombre', 'comunicaciones.programaciones.frecuencia', 'comunicaciones.programaciones.estado', 'comunicaciones.mensajes.titulo']);
        $this->filtrarTexto($query, 'comunicaciones.programaciones.frecuencia', $filtros['frecuencia'] ?? null);
        $this->filtrarTexto($query, 'comunicaciones.programaciones.estado', $filtros['estado'] ?? null);

        return $query->orderByDesc('comunicaciones.programaciones.created_at')->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function guardarTipo(array $datos, ?int $id = null): object { return $this->guardar('comunicaciones.tipos_comunicacion', $datos, $id); }
    public function guardarSegmento(array $datos, ?int $id = null): object
    {
        $datos['criterios'] = json_encode($datos['criterios'] ?? ['origen' => 'REVIVE']);
        return $this->guardar('comunicaciones.segmentos', $datos, $id);
    }
    public function guardarMensaje(array $datos, ?int $id = null, ?int $usuarioId = null): object
    {
        if (! $id) {
            $datos['created_by'] = $usuarioId;
        }
        return $this->guardar('comunicaciones.mensajes', $datos, $id);
    }
    public function guardarProgramacion(array $datos, ?int $id = null): object { return $this->guardar('comunicaciones.programaciones', $datos, $id); }

    public function catalogos(): array
    {
        return [
            'tipos' => DB::table('comunicaciones.tipos_comunicacion')->where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'canal_preferido']),
            'segmentos' => DB::table('comunicaciones.segmentos')->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'mensajes' => DB::table('comunicaciones.mensajes')->orderByDesc('created_at')->limit(100)->get(['id', 'titulo', 'estado']),
        ];
    }

    public function opcionesFiltro(): array
    {
        return [
            'canal' => ['SISTEMA', 'CORREO', 'PUSH', 'APP'],
            'estado_mensaje' => ['BORRADOR', 'PROGRAMADO', 'ENVIADO', 'CANCELADO'],
            'estado_programacion' => ['ACTIVA', 'PAUSADA', 'FINALIZADA'],
            'frecuencia' => ['UNICA', 'DIARIA', 'SEMANAL', 'MENSUAL', 'EVENTO'],
        ];
    }

    private function guardar(string $tabla, array $datos, ?int $id): object
    {
        $antes = $id ? DB::table($tabla)->where('id', $id)->first() : null;
        $datos['updated_at'] = now();
        if ($id) {
            DB::table($tabla)->where('id', $id)->update($datos);
            $despues = DB::table($tabla)->where('id', $id)->first();
            $this->auditar('comunicaciones', 'ACTUALIZAR', $tabla, $id, $antes, $despues);
            return $despues;
        }
        $datos['created_at'] = now();
        $nuevoId = DB::table($tabla)->insertGetId($datos);
        $despues = DB::table($tabla)->where('id', $nuevoId)->first();
        $this->auditar('comunicaciones', 'CREAR', $tabla, $nuevoId, null, $despues);
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
