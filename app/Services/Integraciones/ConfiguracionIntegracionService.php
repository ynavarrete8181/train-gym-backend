<?php

namespace App\Services\Integraciones;

use App\Services\Concerns\RegistraAuditoria;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ConfiguracionIntegracionService
{
    use RegistraAuditoria;

    public function __construct(private readonly CredencialIntegracionService $credenciales) {}

    public function resumen(): array
    {
        $proveedores = DB::table('integraciones.proveedores')->orderBy('nombre')->get();
        $servicios = DB::table('integraciones.servicios')->orderBy('nombre')->get()
            ->map(fn ($servicio) => $this->normalizarJson($servicio, ['headers', 'configuracion']));
        $credenciales = DB::table('integraciones.credenciales')->orderBy('nombre')->get()
            ->map(fn ($credencial) => $this->credenciales->resumen($credencial));
        $cola = $this->estadoCola();

        return compact('proveedores', 'servicios', 'credenciales', 'cola');
    }

    public function guardarProveedor(array $datos, ?int $id = null): object
    {
        $antes = $id ? DB::table('integraciones.proveedores')->find($id) : null;
        $payload = $datos + ['updated_at' => now()];
        if ($id) {
            DB::table('integraciones.proveedores')->where('id', $id)->update($payload);
        } else {
            $id = DB::table('integraciones.proveedores')->insertGetId($payload + ['created_at' => now()]);
        }

        $despues = DB::table('integraciones.proveedores')->find($id);
        $this->auditar('integraciones', $antes ? 'ACTUALIZAR' : 'CREAR', 'integraciones.proveedores', $id, $antes, $despues);
        return $despues;
    }

    public function guardarServicio(array $datos, ?int $id = null): object
    {
        $antes = $id ? DB::table('integraciones.servicios')->find($id) : null;
        $payload = $datos + ['updated_at' => now()];
        $payload['headers'] = json_encode($datos['headers'] ?? []);
        $payload['configuracion'] = json_encode($datos['configuracion'] ?? []);
        if ($id) {
            DB::table('integraciones.proveedores')->where('id', $datos['proveedor_id'])->exists();
            DB::table('integraciones.servicios')->where('id', $id)->update($payload);
        } else {
            $id = DB::table('integraciones.servicios')->insertGetId($payload + ['created_at' => now()]);
        }

        $despues = $this->normalizarJson(DB::table('integraciones.servicios')->find($id), ['headers', 'configuracion']);
        $this->auditar('integraciones', $antes ? 'ACTUALIZAR' : 'CREAR', 'integraciones.servicios', $id, $antes, $despues);
        return $despues;
    }

    public function guardarCredencial(array $datos, ?int $id, ?int $usuarioId): object
    {
        return $this->credenciales->resumen($this->credenciales->guardar($datos, $id, $usuarioId));
    }

    public function probarCredencial(int $id): array
    {
        $registro = DB::table('integraciones.credenciales as c')
            ->join('integraciones.proveedores as p', 'p.id', '=', 'c.proveedor_id')
            ->where('c.id', $id)->select('c.*', 'p.verificar_ssl')->first();
        if (! $registro) throw ValidationException::withMessages(['credencial' => 'El perfil de autenticación no existe.']);
        if ($registro->tipo_autenticacion !== 'OAUTH2_CLIENT_CREDENTIALS') throw ValidationException::withMessages(['credencial' => 'Este perfil se validará al utilizar uno de sus servicios.']);

        $datos = $this->credenciales->obtener($id);
        $tenant = trim((string) ($datos['tenant_id'] ?? ''));
        $client = trim((string) ($datos['client_id'] ?? ''));
        $secret = trim((string) ($datos['client_secret'] ?? ''));
        $tokenUrl = str_replace('{tenant_id}', rawurlencode($tenant), (string) $registro->token_url);
        if ($client === '' || $secret === '' || $tokenUrl === '') throw ValidationException::withMessages(['credencial' => 'Completa los datos obligatorios del perfil.']);

        $inicio = microtime(true);
        $response = Http::withOptions(['verify' => (bool) $registro->verificar_ssl])
            ->asForm()->timeout(20)->connectTimeout(10)->post($tokenUrl, [
                'grant_type' => 'client_credentials', 'client_id' => $client, 'client_secret' => $secret, 'scope' => $datos['scope'] ?? '',
            ]);
        $correcta = $response->successful() && $response->json('access_token');
        $codigoProveedor = (string) $response->json('error');
        $descripcionProveedor = (string) $response->json('error_description');
        $mensaje = $correcta ? 'Autenticación verificada.' : $this->mensajeAutenticacion($codigoProveedor, $descripcionProveedor);
        if (! $correcta) Log::warning('El proveedor rechazó una prueba de autenticación.', ['credencial_id' => $id, 'http_status' => $response->status(), 'codigo_proveedor' => $codigoProveedor]);

        DB::table('integraciones.credenciales')->where('id', $id)->update([
            'ultima_prueba_estado' => $correcta ? 'EXITOSA' : 'ERROR', 'ultima_prueba_mensaje' => mb_substr($mensaje, 0, 500),
            'ultima_prueba_at' => now(), 'updated_at' => now(),
        ]);
        if (! $correcta) throw ValidationException::withMessages(['conexion' => $mensaje]);
        Cache::forget('integraciones.credencial.'.$id.'.token');

        return ['conectado' => true, 'http_status' => $response->status(), 'duracion_ms' => (int) ((microtime(true) - $inicio) * 1000)];
    }

    private function mensajeAutenticacion(string $codigo, string $descripcion): string
    {
        if ($codigo === 'invalid_client' || str_contains($descripcion, 'AADSTS7000215')) return 'El secreto de la aplicación no es válido o expiró. Copia el valor del secreto desde Azure, no su identificador.';
        if (str_contains($descripcion, 'AADSTS700016')) return 'No encontramos la aplicación en el tenant indicado. Revisa el Tenant ID y el Client ID.';
        if ($codigo === 'invalid_scope' || str_contains($descripcion, 'AADSTS70011')) return 'El alcance configurado no es válido para esta aplicación.';

        return 'El proveedor rechazó la autenticación. Revisa los datos configurados e inténtalo nuevamente.';
    }

    private function normalizarJson(object $obj, array $campos): object
    {
        foreach ($campos as $campo) $obj->{$campo} = is_string($obj->{$campo}) ? json_decode($obj->{$campo}, true) : ($obj->{$campo} ?? []);
        return $obj;
    }

    private function estadoCola(): array
    {
        $conexion = (string) config('queue.default');
        $pendientes = null;
        if ($conexion === 'database') {
            try { $pendientes = DB::table(config('queue.connections.database.table', 'jobs'))->count(); } catch (\Throwable) { $pendientes = null; }
        }

        return [
            'conexion' => $conexion,
            'procesamiento_inmediato' => $conexion === 'sync',
            'requiere_trabajador' => ! in_array($conexion, ['sync', 'deferred', 'background'], true),
            'trabajos_pendientes' => $pendientes,
        ];
    }
}
