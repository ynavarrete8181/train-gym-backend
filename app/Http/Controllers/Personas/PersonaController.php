<?php

namespace App\Http\Controllers\Personas;

use App\Http\Controllers\Controller;
use App\Queries\Personas\PersonaQuery;
use App\Services\Personas\BackgroundRemovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

class PersonaController extends Controller
{
    public function __construct(private PersonaQuery $personaQuery)
    {
    }

    public function index(Request $request)
    {
        $filtros = $request->only(['buscar', 'tipo_persona', 'estado_membresia', 'sede_id']);
        $personas = $this->personaQuery->listar($filtros);
        return response()->json($personas);
    }

    public function show($id)
    {
        $persona = $this->personaQuery->obtenerPorId((int) $id);

        if (!$persona) {
            return response()->json([
                'message' => 'No se encontró la persona solicitada.',
            ], 404);
        }

        return response()->json($persona);
    }

    public function buscar(Request $request)
    {
        $data = $request->validate([
            'cedula' => ['required', 'string', 'max:30'],
        ]);

        $persona = $this->personaQuery->buscarPorDocumento($data['cedula']);

        if (!$persona) {
            return response()->json([
                'message' => 'No se encontro una persona con esa identificacion.',
            ], 404);
        }

        return response()->json($persona);
    }

    public function removePhotoBackground(Request $request, BackgroundRemovalService $backgroundRemoval)
    {
        $data = $request->validate([
            'foto' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        try {
            $png = $backgroundRemoval->remove($data['foto']);
        } catch (Throwable $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename="foto-sin-fondo.png"',
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'cedula' => ['required', 'string', 'max:30'],
            'nombres' => ['required', 'string', 'max:120'],
            'apellidos' => ['nullable', 'string', 'max:120'],
            'fecha_nacimiento' => ['nullable', 'date'],
            'sexo' => ['nullable', 'string', 'max:20'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'direccion' => ['nullable', 'string'],
            'ciudad' => ['nullable', 'string', 'max:120'],
            'provincia' => ['nullable', 'string', 'max:120'],
            'tipo_persona' => ['required', 'string', 'in:CLIENTE,SOCIO,FUNCIONARIO,ENTRENADOR'],
            'sede_id' => ['required', 'integer'],
            'foto' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'foto_url' => ['nullable', 'string', 'max:2048'],
            'remove_foto' => ['nullable', 'boolean'],
        ]);

        $existe = DB::table('core.personas')
            ->where('tipo_identificacion', 'CEDULA')
            ->where('numero_identificacion', $data['cedula'])
            ->exists();

        if ($existe) {
            return response()->json([
                'message' => 'Ya existe una persona registrada con ese número de identificación.',
            ], 422);
        }

        $personaId = DB::transaction(function () use ($data, $request) {
            $estadoActivoId = DB::table('core.estados')->where('codigo', 'ACTIVO')->value('id');
            $fotoUrl = $this->resolvePersonaPhotoUrl($request, $data);

            $personaId = DB::table('core.personas')->insertGetId([
                'tipo_identificacion' => 'CEDULA',
                'numero_identificacion' => $data['cedula'],
                'nombres' => $data['nombres'],
                'apellidos' => $data['apellidos'] ?? null,
                'fecha_nacimiento' => $data['fecha_nacimiento'] ?? null,
                'sexo' => $data['sexo'] ?? null,
                'telefono' => $data['telefono'] ?? null,
                'email' => $data['email'] ?? null,
                'direccion' => $data['direccion'] ?? null,
                'foto_url' => $fotoUrl,
                'ciudad' => $data['ciudad'] ?? null,
                'provincia' => $data['provincia'] ?? null,
                'estado_id' => $estadoActivoId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $tipoId = DB::table('core.persona_tipos')->where('codigo', $data['tipo_persona'])->value('id');
            if ($tipoId) {
                DB::table('core.persona_tipo_detalle')->insert([
                    'persona_id' => $personaId,
                    'tipo_id' => $tipoId,
                    'activo' => true,
                    'fecha_inicio' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if ($data['tipo_persona'] === 'SOCIO') {
                $ultimoSocioId = DB::table('socios.socios')->max('id') ?? 0;
                $codigoSocio = 'SOC-' . str_pad($ultimoSocioId + 1, 4, '0', STR_PAD_LEFT);

                DB::table('socios.socios')->insert([
                    'persona_id' => $personaId,
                    'sede_id' => $data['sede_id'],
                    'codigo_socio' => $codigoSocio,
                    'fecha_alta' => now(),
                    'estado_id' => $estadoActivoId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $personaId;
        });

        return response()->json([
            'message' => 'Persona registrada exitosamente.',
            'id' => $personaId,
        ], 210);
    }

    public function update(Request $request, $id)
    {
        $data = $request->validate([
            'cedula' => ['required', 'string', 'max:30'],
            'nombres' => ['required', 'string', 'max:120'],
            'apellidos' => ['nullable', 'string', 'max:120'],
            'fecha_nacimiento' => ['nullable', 'date'],
            'sexo' => ['nullable', 'string', 'max:20'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'direccion' => ['nullable', 'string'],
            'ciudad' => ['nullable', 'string', 'max:120'],
            'provincia' => ['nullable', 'string', 'max:120'],
            'tipo_persona' => ['required', 'string', 'in:CLIENTE,SOCIO,FUNCIONARIO,ENTRENADOR'],
            'sede_id' => ['required', 'integer'],
            'foto' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'foto_url' => ['nullable', 'string', 'max:2048'],
            'remove_foto' => ['nullable', 'boolean'],
        ]);

        $persona = DB::table('core.personas')->where('id', $id)->first();

        if (!$persona) {
            return response()->json([
                'message' => 'No se encontró la persona a actualizar.',
            ], 404);
        }

        $existeOtro = DB::table('core.personas')
            ->where('tipo_identificacion', 'CEDULA')
            ->where('numero_identificacion', $data['cedula'])
            ->where('id', '!=', $id)
            ->exists();

        if ($existeOtro) {
            return response()->json([
                'message' => 'Ya existe otra persona registrada con ese número de identificación.',
            ], 422);
        }

        DB::transaction(function () use ($data, $id, $request, $persona) {
            $fotoUrl = $this->resolvePersonaPhotoUrl($request, $data, $persona->foto_url ?? null);

            DB::table('core.personas')
                ->where('id', $id)
                ->update([
                    'numero_identificacion' => $data['cedula'],
                    'nombres' => $data['nombres'],
                    'apellidos' => $data['apellidos'] ?? null,
                    'fecha_nacimiento' => $data['fecha_nacimiento'] ?? null,
                    'sexo' => $data['sexo'] ?? null,
                    'telefono' => $data['telefono'] ?? null,
                    'email' => $data['email'] ?? null,
                    'direccion' => $data['direccion'] ?? null,
                    'foto_url' => $fotoUrl,
                    'ciudad' => $data['ciudad'] ?? null,
                    'provincia' => $data['provincia'] ?? null,
                    'updated_at' => now(),
                ]);

            $tipoActualId = DB::table('core.persona_tipo_detalle')
                ->where('persona_id', $id)
                ->where('activo', true)
                ->value('tipo_id');
            $tipoActualCodigo = $tipoActualId ? DB::table('core.persona_tipos')->where('id', $tipoActualId)->value('codigo') : null;

            if ($tipoActualCodigo !== $data['tipo_persona']) {
                // Desactivar el tipo actual
                DB::table('core.persona_tipo_detalle')
                    ->where('persona_id', $id)
                    ->update(['activo' => false, 'fecha_fin' => now(), 'updated_at' => now()]);

                // Asignar el nuevo tipo
                $nuevoTipoId = DB::table('core.persona_tipos')->where('codigo', $data['tipo_persona'])->value('id');
                if ($nuevoTipoId) {
                    DB::table('core.persona_tipo_detalle')->insert([
                        'persona_id' => $id,
                        'tipo_id' => $nuevoTipoId,
                        'activo' => true,
                        'fecha_inicio' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // Actualizar o crear socio si el tipo es SOCIO
            if ($data['tipo_persona'] === 'SOCIO') {
                $socio = DB::table('socios.socios')->where('persona_id', $id)->first();
                if ($socio) {
                    // Actualizar sede del socio
                    DB::table('socios.socios')->where('id', $socio->id)->update([
                        'sede_id' => $data['sede_id'],
                        'updated_at' => now(),
                    ]);
                } else {
                    // Crear nuevo registro de socio
                    $estadoActivoId = DB::table('core.estados')->where('codigo', 'ACTIVO')->value('id');
                    $ultimoSocioId = DB::table('socios.socios')->max('id') ?? 0;
                    $codigoSocio = 'SOC-' . str_pad($ultimoSocioId + 1, 4, '0', STR_PAD_LEFT);
                    
                    DB::table('socios.socios')->insert([
                        'persona_id' => $id,
                        'sede_id' => $data['sede_id'],
                        'codigo_socio' => $codigoSocio,
                        'fecha_alta' => now(),
                        'estado_id' => $estadoActivoId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });

        return response()->json([
            'message' => 'Datos actualizados exitosamente.',
        ]);
    }

    private function resolvePersonaPhotoUrl(Request $request, array $data, ?string $currentUrl = null): ?string
    {
        if ($request->hasFile('foto')) {
            $uploadedFile = $request->file('foto');
            $directory = public_path('uploads/personas');
            File::ensureDirectoryExists($directory);

            $filename = now()->format('YmdHis') . '_' . Str::random(12) . '.' . $uploadedFile->getClientOriginalExtension();
            $uploadedFile->move($directory, $filename);

            if ($currentUrl) {
                $this->deleteLocalPersonaPhoto($currentUrl);
            }

            return $this->publicPersonaUploadUrl($filename, $request);
        }

        if (filter_var($data['remove_foto'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            if ($currentUrl) {
                $this->deleteLocalPersonaPhoto($currentUrl);
            }

            return null;
        }

        return !empty($data['foto_url']) ? $data['foto_url'] : $currentUrl;
    }

    private function publicPersonaUploadUrl(string $filename, Request $request): string
    {
        $baseUrl = rtrim($request->getSchemeAndHttpHost(), '/');

        return "{$baseUrl}/uploads/personas/{$filename}";
    }

    private function deleteLocalPersonaPhoto(string $url): void
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (!$path || !str_contains($path, '/uploads/personas/')) {
            return;
        }

        $fullPath = public_path(ltrim($path, '/'));

        if (File::exists($fullPath)) {
            File::delete($fullPath);
        }
    }

    public function changeStatus(Request $request, $id)
    {
        $data = $request->validate([
            'estado' => ['required', 'string', 'in:ACTIVO,SUSPENDIDO,INACTIVO'],
        ]);

        $persona = DB::table('core.personas')->where('id', $id)->first();

        if (!$persona) {
            return response()->json([
                'message' => 'No se encontró la persona.',
            ], 404);
        }

        $estadoId = DB::table('core.estados')->where('codigo', $data['estado'])->value('id');

        if (!$estadoId) {
            return response()->json([
                'message' => 'Estado no válido.',
            ], 422);
        }

        DB::table('core.personas')
            ->where('id', $id)
            ->update([
                'estado_id' => $estadoId,
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Estado de la persona actualizado correctamente.',
            'estado' => $data['estado'],
        ]);
    }
}
