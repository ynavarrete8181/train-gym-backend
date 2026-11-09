<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auditoria\AuditoriaServicio;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Cambio de la clave temporal desde la app móvil. Aplica las mismas reglas
 * que /base/auth/cambiar-clave, pero conserva la sesión actual (la app
 * continúa al inicio sin volver a pedir login) y cierra las demás.
 */
class CambiarClaveTemporalController extends Controller
{
    public function __construct(private readonly AuditoriaServicio $auditoria) {}

    public function __invoke(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'password_actual' => ['required', 'string'],
            'password' => [
                'required',
                'string',
                'confirmed',
                'min:8',
                'regex:/[A-ZÁÉÍÓÚÑ]/u',
                'regex:/[a-záéíóúñ]/u',
                'regex:/\d/',
                'regex:/[^A-Za-zÁÉÍÓÚÑáéíóúñ0-9\s]/u',
            ],
        ], [
            'password.regex' => 'La nueva contraseña debe tener mayúscula, minúscula, número y un símbolo.',
        ]);

        /** @var User|null $usuario */
        $usuario = $request->user();

        if (! $usuario || ! Hash::check($datos['password_actual'], $usuario->password)) {
            throw ValidationException::withMessages([
                'password_actual' => 'La clave temporal no es correcta.',
            ]);
        }

        if (Hash::check($datos['password'], $usuario->password)) {
            throw ValidationException::withMessages([
                'password' => 'La nueva contraseña debe ser diferente de la clave temporal.',
            ]);
        }

        DB::transaction(function () use ($usuario, $datos, $request): void {
            $usuario->update(['password' => Hash::make($datos['password'])]);
            DB::table('seguridad.tokens_acceso')
                ->where('id_usuario', $usuario->id)
                ->where('token_hash', '<>', hash('sha256', (string) $request->bearerToken()))
                ->delete();
        });

        $this->auditoria->registrar([
            'modulo' => 'seguridad',
            'tabla' => 'seguridad.users',
            'accion' => 'ACTUALIZAR',
            'registro_id' => $usuario->id,
            'datos_antes' => null,
            'datos_despues' => null,
            'descripcion' => 'Clave temporal cambiada desde la app móvil.',
        ]);

        return ApiResponse::exito('Contraseña actualizada correctamente.');
    }
}
