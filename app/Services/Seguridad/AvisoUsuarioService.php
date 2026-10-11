<?php

namespace App\Services\Seguridad;

use Illuminate\Support\Facades\DB;

class AvisoUsuarioService
{
    public function __construct(
        private readonly TiempoRealUsuarioService $tiempoReal,
    ) {}

    public function registrar(int $usuarioId, string $tipo, string $titulo, ?string $mensaje = null, ?string $vista = null, ?string $referenciaTipo = null, ?int $referenciaId = null): int
    {
        $ahora = now();
        $id = (int) DB::table('seguridad.avisos_usuario')->insertGetId([
            'usuario_id' => $usuarioId,
            'tipo' => $tipo,
            'titulo' => $titulo,
            'mensaje' => $mensaje,
            'vista' => $vista,
            'referencia_tipo' => $referenciaTipo,
            'referencia_id' => $referenciaId,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);

        $this->tiempoReal->emitir($usuarioId, 'AVISO_CREADO', [
            'id' => $id,
            'tipo' => $tipo,
            'titulo' => $titulo,
            'mensaje' => $mensaje,
            'vista' => $vista,
            'referencia_tipo' => $referenciaTipo,
            'referencia_id' => $referenciaId,
            'leido' => false,
            'created_at' => $ahora->toISOString(),
        ]);

        return $id;
    }

    public function listar(int $usuarioId, int $limite = 20): array
    {
        return DB::table('seguridad.avisos_usuario')
            ->where('usuario_id', $usuarioId)
            ->orderByRaw('leido_at IS NULL DESC')
            ->orderByDesc('id')
            ->limit($limite)
            ->get()
            ->map(fn ($aviso) => [
                'id' => (int) $aviso->id,
                'tipo' => $aviso->tipo,
                'titulo' => $aviso->titulo,
                'mensaje' => $aviso->mensaje,
                'vista' => $aviso->vista,
                'referencia_tipo' => $aviso->referencia_tipo,
                'referencia_id' => $aviso->referencia_id ? (int) $aviso->referencia_id : null,
                'leido' => $aviso->leido_at !== null,
                'created_at' => $aviso->created_at,
            ])->all();
    }

    public function marcarLeido(int $usuarioId, int $avisoId): void
    {
        DB::table('seguridad.avisos_usuario')
            ->where('id', $avisoId)
            ->where('usuario_id', $usuarioId)
            ->whereNull('leido_at')
            ->update(['leido_at' => now(), 'updated_at' => now()]);
    }
}
