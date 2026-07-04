<?php

namespace App\Http\Controllers\Ventas;

use App\Http\Controllers\Controller;
use App\Queries\Ventas\VentaQuery;
use App\Services\Ventas\PuntoVentaBorradorService;
use App\Services\Ventas\PuntoVentaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PuntoVentaController extends Controller
{
    public function __construct(
        private PuntoVentaService $puntoVentaService,
        private PuntoVentaBorradorService $puntoVentaBorradorService,
        private VentaQuery $ventaQuery
    )
    {
    }

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

    private function isAdmin(Request $request): bool
    {
        return in_array('ADMINISTRADOR', $this->roleCodes($request), true);
    }

    private function assertCanUseSede(Request $request, ?int $sedeId): void
    {
        $user = $request->user();

        if (!$user || !$sedeId || $this->isAdmin($request)) {
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

    public function catalogo(Request $request)
    {
        $data = $request->validate([
            'sede_id' => ['required', 'integer'],
        ]);

        $this->assertCanUseSede($request, (int) $data['sede_id']);

        return response()->json(
            $this->puntoVentaService->catalogo((int) $data['sede_id'])
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'venta_id' => ['nullable', 'integer'],
            'sede_id' => ['required', 'integer'],
            'persona_id' => ['nullable', 'integer'],
            'forma_pago' => ['nullable', 'string', 'in:EFECTIVO,TARJETA,TRANSFERENCIA,QR,BECA,OTRO,PENDIENTE'],
            'estado_pago' => ['nullable', 'string', 'in:PAGADO,PENDIENTE,ABONADO'],
            'membresia_id' => ['nullable', 'integer'],
            'referencia' => ['nullable', 'string', 'max:100'],
            'observacion' => ['nullable', 'string'],
            'items' => ['nullable', 'array'],
            'items.*.producto_id' => ['required_with:items', 'integer'],
            'items.*.cantidad' => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.precio_unitario' => ['nullable', 'numeric', 'gte:0'],
            'items.*.costo_unitario' => ['nullable', 'numeric', 'gte:0'],
            'items.*.tipo_precio' => ['nullable', 'string', 'max:30'],
        ]);

        try {
            $this->assertCanUseSede($request, (int) $data['sede_id']);

            $venta = $this->puntoVentaService->procesar($data, $request);

            return response()->json([
                'message' => 'Venta registrada correctamente',
                'data' => $venta,
            ], 201);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function borradores(Request $request)
    {
        $userId = $request->user()?->id ?? 1;

        return response()->json([
            'data' => $this->puntoVentaBorradorService->listByUser($userId),
        ]);
    }

    public function abiertas(Request $request)
    {
        $data = $request->validate([
            'persona_id' => ['nullable', 'integer'],
            'sede_id' => ['nullable', 'integer'],
            'fecha_consumo' => ['nullable', 'date'],
        ]);

        $personaId = isset($data['persona_id']) ? (int) $data['persona_id'] : null;
        $sedeId = isset($data['sede_id']) ? (int) $data['sede_id'] : null;
        $this->assertCanUseSede($request, $sedeId);

        $fechaConsumo = $data['fecha_consumo'] ?? $this->businessDate();
        $roles = $this->roleCodes($request);
        $isAdmin = in_array('ADMINISTRADOR', $roles, true);
        $isCashier = in_array('CAJERO', $roles, true);
        $vendedorUsuarioId = ($isCashier && !$isAdmin && $request->user())
            ? (int) $request->user()->id
            : null;

        return response()->json([
            'fecha' => $fechaConsumo,
            'data' => $this->ventaQuery->getAbiertas($personaId, $sedeId, $fechaConsumo, $vendedorUsuarioId),
        ]);
    }

    public function guardarBorrador(Request $request)
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer'],
            'sede_id' => ['required', 'integer'],
            'persona_id' => ['nullable', 'integer'],
            'membresia_id' => ['nullable', 'integer'],
            'forma_pago' => ['nullable', 'string'],
            'estado_pago' => ['nullable', 'string'],
            'referencia' => ['nullable', 'string', 'max:100'],
            'observacion' => ['nullable', 'string'],
            'membresia_precio' => ['nullable', 'numeric', 'gte:0'],
            'items' => ['nullable', 'array'],
            'items.*.producto_id' => ['required_with:items', 'integer'],
            'items.*.cantidad' => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.precio_unitario' => ['nullable', 'numeric', 'gte:0'],
            'items.*.costo_unitario' => ['nullable', 'numeric', 'gte:0'],
            'items.*.tipo_precio' => ['nullable', 'string', 'max:30'],
        ]);

        try {
            $draft = $this->puntoVentaBorradorService->save($data, $request);

            return response()->json([
                'message' => 'Borrador guardado correctamente',
                'data' => $draft,
            ], 201);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function confirmarBorrador(Request $request, int $id)
    {
        try {
            $venta = $this->puntoVentaBorradorService->confirm($id, $request);

            return response()->json([
                'message' => 'Venta confirmada correctamente',
                'data' => $venta,
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function eliminarBorrador(Request $request, int $id)
    {
        $this->puntoVentaBorradorService->delete($id, $request);

        return response()->json([
            'message' => 'Borrador eliminado correctamente',
        ]);
    }
}
