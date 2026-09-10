<?php

namespace App\Services\Integraciones;

use App\Services\Concerns\RegistraAuditoria;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CredencialIntegracionService
{
    use RegistraAuditoria;

    public function obtener(int $id): array
    {
        $credencial = DB::table('integraciones.credenciales')->find($id);
        if (! $credencial || ! $credencial->activo) {
            throw new RuntimeException('El perfil de autenticación no está disponible.');
        }

        return array_filter($this->datosConRespaldo($credencial), fn ($valor) => $valor !== null && $valor !== '');
    }

    public function detalleAdministrativo(int $id): array
    {
        $credencial = DB::table('integraciones.credenciales')->find($id);
        if (! $credencial) {
            throw new RuntimeException('El perfil de autenticación no existe.');
        }

        return [
            'id' => $credencial->id,
            'proveedor_id' => $credencial->proveedor_id,
            'codigo' => $credencial->codigo,
            'nombre' => $credencial->nombre,
            'tipo_autenticacion' => $credencial->tipo_autenticacion,
            'token_url' => $credencial->token_url,
            'configuracion' => is_string($credencial->configuracion) ? json_decode($credencial->configuracion, true) ?? [] : [],
            'datos' => $this->datosConRespaldo($credencial),
            'activo' => (bool) $credencial->activo,
        ];
    }

    public function guardar(array $datos, ?int $id, ?int $usuarioId): object
    {
        $esNueva = ! $id;
        $actual = $id ? DB::table('integraciones.credenciales')->find($id) : null;
        $secretos = $actual ? $this->descifrar($actual->datos_cifrados) : [];
        foreach ($datos['datos'] ?? [] as $clave => $valor) {
            if ($valor !== null && $valor !== '') {
                $secretos[$clave] = $valor;
            }
        }
        $payload = ['proveedor_id' => $datos['proveedor_id'], 'codigo' => $datos['codigo'], 'nombre' => $datos['nombre'],
            'tipo_autenticacion' => $datos['tipo_autenticacion'], 'token_url' => $datos['token_url'] ?? null,
            'configuracion' => json_encode($datos['configuracion'] ?? []),
            'datos_cifrados' => Crypt::encryptString(json_encode($secretos, JSON_THROW_ON_ERROR)),
            'activo' => $datos['activo'], 'updated_by' => $usuarioId, 'updated_at' => now()];
        if ($id) {
            DB::table('integraciones.credenciales')->where('id', $id)->update($payload);
        } else {
            $id = DB::table('integraciones.credenciales')->insertGetId($payload + ['created_by' => $usuarioId, 'created_at' => now()]);
        }

        $despues = DB::table('integraciones.credenciales')->find($id);
        // Nunca auditar datos_cifrados ni los valores secretos: solo metadatos del perfil.
        $metadatoSeguro = fn (?object $registro) => $registro ? (object) [
            'id' => $registro->id, 'proveedor_id' => $registro->proveedor_id, 'codigo' => $registro->codigo,
            'nombre' => $registro->nombre, 'tipo_autenticacion' => $registro->tipo_autenticacion, 'activo' => $registro->activo,
        ] : null;
        $this->auditar('integraciones', $esNueva ? 'CREAR' : 'ACTUALIZAR', 'integraciones.credenciales', $id, $metadatoSeguro($actual), $metadatoSeguro($despues), 'Perfil de autenticación (secretos no auditados).');
        return $despues;
    }

    public function resumen(object $credencial): object
    {
        $datos = $this->datosConRespaldo($credencial);
        $requeridos = match ($credencial->tipo_autenticacion) {
            'OAUTH2_CLIENT_CREDENTIALS' => ['client_id', 'client_secret'],
            'OAUTH2_AUTHORIZATION_CODE_PKCE' => ['tenant_id', 'client_id'],
            'API_KEY' => ['api_key'],
            'BEARER_TOKEN' => ['token'],
            'BASIC' => ['username', 'password'],
            default => [],
        };
        $credencial->configuracion = is_string($credencial->configuracion) ? json_decode($credencial->configuracion, true) ?? [] : ($credencial->configuracion ?? []);
        $credencial->configurada = collect($requeridos)->every(fn ($campo) => filled($datos[$campo] ?? null));
        $credencial->campos_configurados = array_values(array_keys(array_filter($datos, fn ($valor) => filled($valor))));
        $credencial->datos_publicos = array_intersect_key($datos, array_flip([
            'tenant_id', 'client_id', 'scope', 'sender', 'header_name', 'username',
        ]));
        unset($credencial->datos_cifrados, $credencial->tenant_env, $credencial->client_id_env, $credencial->client_secret_env, $credencial->scope_env);

        return $credencial;
    }

    private function datosConRespaldo(object $credencial): array
    {
        $datos = $this->descifrar($credencial->datos_cifrados);
        if ($credencial->codigo === 'MICROSOFT_GRAPH_CORREO') {
            $datos['tenant_id'] ??= config('services.azure.tenant_id');
            $datos['client_id'] ??= config('services.azure.client_id_outlook_graph');
            $datos['client_secret'] ??= config('services.azure.client_secret_outlook_graph');
            $datos['scope'] ??= config('services.azure.scope', 'https://graph.microsoft.com/.default');
            $datos['sender'] ??= config('services.azure.outlook_sender');
        }

        return $datos;
    }

    private function descifrar(?string $valor): array
    {
        if (! $valor) {
            return [];
        }
        try {
            return json_decode(Crypt::decryptString($valor), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new RuntimeException('No fue posible leer las credenciales cifradas.');
        }
    }
}
