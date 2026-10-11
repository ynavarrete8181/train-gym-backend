<?php

namespace App\Http\Controllers\Api\Integraciones;

use App\Http\Controllers\Controller;
use App\Models\Integraciones\Credencial;
use App\Models\Integraciones\Proveedor;
use App\Models\Integraciones\ServicioExterno;
use App\Services\Integraciones\ConfiguracionIntegracionService;
use App\Services\Integraciones\CredencialIntegracionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ConfiguracionIntegracionController extends Controller
{
    public function __construct(private readonly ConfiguracionIntegracionService $service) {}

    public function index(): JsonResponse
    {
        return ApiResponse::exito('Integraciones consultadas.', $this->service->resumen());
    }

    public function proveedor(Request $request, ?int $id = null): JsonResponse
    {
        $datos = $request->validate([
            'codigo' => ['required', 'string', 'max:80', Rule::unique(Proveedor::class, 'codigo')->ignore($id)],
            'nombre' => ['required', 'string', 'max:150'], 'descripcion' => ['nullable', 'string'],
            'url_base' => ['required', 'url', 'starts_with:https://', 'max:500'],
            'tipo' => ['required', 'string', 'max:30'], 'verificar_ssl' => ['required', 'boolean'], 'activo' => ['required', 'boolean'],
        ]);

        return ApiResponse::exito('Proveedor guardado.', $this->service->guardarProveedor($datos, $id));
    }

    public function servicio(Request $request, ?int $id = null): JsonResponse
    {
        $datos = $request->validate([
            'proveedor_id' => ['required', 'integer', Rule::exists(Proveedor::class, 'id')],
            'credencial_id' => ['nullable', 'integer', Rule::exists(Credencial::class, 'id')],
            'codigo' => ['required', 'string', 'max:100', Rule::unique(ServicioExterno::class, 'codigo')->ignore($id)],
            'nombre' => ['required', 'string', 'max:150'], 'endpoint' => ['required', 'string', 'starts_with:/', 'max:500'],
            'metodo' => ['required', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])],
            'tipo_autenticacion' => ['required', 'string', 'max:40'], 'headers' => ['nullable', 'array'],
            'configuracion' => ['nullable', 'array'],
            'configuracion.correos_por_minuto' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'timeout_segundos' => ['required', 'integer', 'min:1', 'max:120'],
            'reintentos' => ['required', 'integer', 'min:0', 'max:10'], 'activo' => ['required', 'boolean'],
        ]);

        return ApiResponse::exito('Servicio guardado.', $this->service->guardarServicio($datos, $id));
    }

    public function credencial(Request $request, ?int $id = null): JsonResponse
    {
        $datos = $request->validate([
            'proveedor_id' => ['required', 'integer', Rule::exists(Proveedor::class, 'id')],
            'codigo' => ['required', 'string', 'max:100', Rule::unique(Credencial::class, 'codigo')->ignore($id)],
            'nombre' => ['required', 'string', 'max:150'],
            'tipo_autenticacion' => ['required', Rule::in([
                'OAUTH2_CLIENT_CREDENTIALS',
                'OAUTH2_AUTHORIZATION_CODE_PKCE',
                'API_KEY',
                'BEARER_TOKEN',
                'BASIC',
                'NINGUNA',
            ])],
            'token_url' => [
                'nullable', 'string', 'max:500',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') return;
                    $urlValidacion = str_replace('{tenant_id}', 'tenant', (string) $value);
                    if (! str_starts_with($urlValidacion, 'https://') || filter_var($urlValidacion, FILTER_VALIDATE_URL) === false) {
                        $fail('La URL para obtener el token debe ser una dirección HTTPS válida.');
                    }
                },
            ],
            'datos' => ['nullable', 'array'],
            'datos.tenant_id' => ['required_if:tipo_autenticacion,OAUTH2_AUTHORIZATION_CODE_PKCE', 'nullable', 'string', 'max:100'],
            'datos.client_id' => ['required_if:tipo_autenticacion,OAUTH2_AUTHORIZATION_CODE_PKCE', 'nullable', 'string', 'max:150'],
            'datos.scope' => ['nullable', 'string', 'max:500'],
            'configuracion' => ['nullable', 'array'],
            'configuracion.dominios_permitidos' => ['nullable', 'array'],
            'configuracion.dominios_permitidos.*' => ['string', 'max:150'],
            'configuracion.graph_me_endpoint' => [
                'nullable', 'url:https', 'max:500',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') return;
                    if (mb_strtolower((string) parse_url((string) $value, PHP_URL_HOST)) !== 'graph.microsoft.com') {
                        $fail('El endpoint de perfil debe pertenecer a graph.microsoft.com.');
                    }
                },
            ],
            'activo' => ['required', 'boolean'],
        ]);

        return ApiResponse::exito('Perfil de autenticación guardado.', $this->service->guardarCredencial($datos, $id, $request->user()?->id));
    }

    public function detalleCredencial(Request $request, int $id): JsonResponse
    {
        $esAdministrador = DB::table('seguridad.cpu_userrole')
            ->where('id_userrole', $request->user()?->usr_tipo)
            ->where('role', 'ADMINISTRADOR')
            ->where('activo', true)
            ->exists();

        if (! $esAdministrador) {
            return ApiResponse::error('No tienes permiso para consultar datos sensibles.', codigo: 403);
        }

        return ApiResponse::exito(
            'Perfil consultado.',
            app(CredencialIntegracionService::class)->detalleAdministrativo($id),
        )->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    }

    public function probarCredencial(int $id): JsonResponse
    {
        return ApiResponse::exito('Conexión correcta.', $this->service->probarCredencial($id));
    }
}
