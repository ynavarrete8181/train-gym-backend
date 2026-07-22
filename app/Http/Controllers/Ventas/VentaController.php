<?php

namespace App\Http\Controllers\Ventas;

use App\Http\Controllers\Controller;
use App\Queries\Ventas\VentaQuery;
use App\Services\Ventas\VentaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VentaController extends Controller
{
    public function __construct(
        private VentaQuery $ventaQuery,
        private VentaService $ventaService
    ) {}

    private function businessDate(): string
    {
        return now(config('app.timezone', 'America/Guayaquil'))->toDateString();
    }

    private function roleCodes(Request $request): array
    {
        $user = $request->user();

        if (!$user) {
            return [];
        }

        return DB::table('seguridad.usuario_roles as ur')
            ->join('seguridad.roles as r', 'r.id', '=', 'ur.rol_id')
            ->where('ur.usuario_id', $user->id)
            ->pluck('r.codigo')
            ->map(fn ($codigo) => strtoupper((string) $codigo))
            ->all();
    }

    private function assertCanUseSede(Request $request, ?int $sedeId, bool $isAdmin): void
    {
        $user = $request->user();

        if (!$user || !$sedeId || $isAdmin) {
            return;
        }

        $allowed = DB::table('seguridad.usuario_sedes')
            ->where('usuario_id', $user->id)
            ->where('sede_id', $sedeId)
            ->where('activo', true)
            ->exists();

        if (!$allowed) {
            abort(403, 'No tienes permiso para operar en la sede seleccionada');
        }
    }

    public function index(Request $request)
    {
        $sedeId = $request->header('X-Sede-Id') ?? 1; // Default
        $filters = $request->validate([
            'buscar' => ['nullable', 'string', 'max:150'],
            'cedula' => ['nullable', 'string', 'max:30'],
        ]);

        return response()->json($this->ventaQuery->getList((int) $sedeId, $filters['cedula'] ?? $filters['buscar'] ?? null));
    }

    public function cierreCaja(Request $request)
    {
        $data = $request->validate([
            'sede_id' => ['nullable', 'integer'],
            'fecha' => ['nullable', 'date'],
            'buscar' => ['nullable', 'string', 'max:120'],
            'buscar_tipo' => ['nullable', 'string', 'in:todos,factura,cliente,detalle'],
            'vendedor_usuario_id' => ['nullable', 'integer'],
        ]);

        $sedeId = isset($data['sede_id'])
            ? (int) $data['sede_id']
            : ($request->header('X-Sede-Id') ? (int) $request->header('X-Sede-Id') : null);
        $fecha = $data['fecha'] ?? $this->businessDate();
        $user = $request->user();
        $roles = $this->roleCodes($request);
        $isAdmin = in_array('ADMINISTRADOR', $roles, true);
        $isCashier = in_array('CAJERO', $roles, true);
        $this->assertCanUseSede($request, $sedeId, $isAdmin);

        $vendedorUsuarioId = null;

        if ($isCashier && !$isAdmin) {
            $vendedorUsuarioId = (int) $user->id;
        } elseif (isset($data['vendedor_usuario_id'])) {
            $vendedorUsuarioId = (int) $data['vendedor_usuario_id'];
        }

        return response()->json($this->ventaQuery->cierreCaja(
            $sedeId,
            $fecha,
            $vendedorUsuarioId,
            $data['buscar'] ?? null,
            $data['buscar_tipo'] ?? 'todos'
        ));
    }

    public function store(Request $request)
    {
        $request->validate([
            'sede_id' => 'required|integer',
            'cliente_id' => 'nullable|integer',
            'total' => 'required|numeric',
            'detalles' => 'required|array',
            'detalles.*.producto_id' => 'required|integer',
            'detalles.*.cantidad' => 'required|integer|min:1',
            'detalles.*.precio_unitario' => 'required|numeric|min:0',
        ]);

        $userId = $request->user()?->id ?? 1;

        $ventaId = $this->ventaService->store($request->all(), $userId);

        return response()->json([
            'message' => 'Venta registrada con éxito',
            'id' => $ventaId
        ], 201);
    }
}
