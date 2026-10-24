<?php

namespace App\Http\Controllers\Api\Gimnasio;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class FotografiaValoracionControlador extends Controller
{
    private function autorizar(Request $request, int $deportistaId): void
    {
        abort_unless(DB::table('clientes.deportistas')->where('id', $deportistaId)->exists(), 404);
        $usuario = $request->user();
        $rol = DB::table('seguridad.cpu_userrole')->where('id_userrole', $usuario->usr_tipo)->value('role');
        abort_unless(in_array($rol, ['SUPERADMINISTRADOR', 'ADMINISTRADOR', 'ENTRENADOR'], true), 403);
        if ($rol === 'ENTRENADOR') {
            abort_unless(DB::table('entrenamiento.asignaciones_entrenador_cliente as a')
                ->join('entrenamiento.entrenadores as e', 'e.id', '=', 'a.entrenador_id')
                ->where('a.deportista_id', $deportistaId)->where('e.usuario_id', $usuario->id)
                ->where('a.estado', 'ACTIVO')->exists(), 403);
        }
    }

    public function index(Request $request, int $id)
    {
        $this->autorizar($request, $id);
        $fotos = DB::table('clientes.valoracion_fotografias')
            ->where('deportista_id', $id)
            ->orderByDesc('fecha_fotografia')->orderByDesc('id')
            ->get(['id', 'valoracion_registro_id', 'vista', 'fecha_fotografia', 'created_at']);
        return ApiResponse::exito('Fotografías consultadas.', $fotos);
    }

    public function guardar(Request $request, int $id)
    {
        $this->autorizar($request, $id);
        $datos = $request->validate([
            'valoracion_registro_id' => ['required', 'integer'],
            'vista' => ['required', Rule::in(['FRONTAL', 'LATERAL', 'POSTERIOR', 'OTRA'])],
            'fecha_fotografia' => ['required', 'date'],
            'foto' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'consentimiento_confirmado' => ['required', 'accepted'],
        ]);
        abort_unless(DB::table('clientes.valoracion_registros')
            ->where('id', $datos['valoracion_registro_id'])
            ->where('deportista_id', $id)->where('seccion', 'evaluacion')->exists(), 422,
            'Selecciona una evaluación física válida de este deportista.');

        // Disco local privado; Railway requiere volumen persistente antes de producción.
        $archivo = $request->file('foto');
        $ruta = $archivo->store("valoraciones/{$id}", 'local');
        abort_unless($ruta, 500, 'No fue posible almacenar la fotografía.');
        try {
            $fotoId = DB::table('clientes.valoracion_fotografias')->insertGetId([
                'valoracion_registro_id' => $datos['valoracion_registro_id'],
                'deportista_id' => $id, 'subido_por' => $request->user()->id,
                'ubicacion_privada' => $ruta, 'mime_type' => $archivo->getMimeType(),
                'vista' => $datos['vista'], 'fecha_fotografia' => $datos['fecha_fotografia'],
                'consentimiento_confirmado' => true, 'consentimiento_confirmado_at' => now(),
                'consentimiento_registrado_por' => $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($ruta);
            throw $error;
        }
        return ApiResponse::exito('Fotografía almacenada de forma privada.', ['id' => $fotoId], codigo: 201);
    }

    public function mostrar(Request $request, int $id, int $foto)
    {
        $this->autorizar($request, $id);
        $registro = DB::table('clientes.valoracion_fotografias')
            ->where('id', $foto)->where('deportista_id', $id)->first();
        abort_unless($registro, 404);
        abort_unless(Storage::disk('local')->exists($registro->ubicacion_privada), 404);
        return Storage::disk('local')->response($registro->ubicacion_privada, null, [
            'Content-Type' => $registro->mime_type,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
