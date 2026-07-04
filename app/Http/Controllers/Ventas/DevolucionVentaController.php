<?php

namespace App\Http\Controllers\Ventas;

use App\Http\Controllers\Controller;
use App\Services\Ventas\DevolucionVentaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DevolucionVentaController extends Controller
{
    public function __construct(private DevolucionVentaService $devolucionVentaService)
    {
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

    private function assertCanUseSede(Request $request, ?int $sedeId): void
    {
        $user = $request->user();

        if (!$user || !$sedeId || in_array('ADMINISTRADOR', $this->roleCodes($request), true)) {
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

    public function buscar(Request $request)
    {
        $data = $request->validate([
            'termino' => ['nullable', 'string', 'max:120'],
            'sede_id' => ['nullable', 'integer'],
        ]);

        $sedeId = isset($data['sede_id']) ? (int) $data['sede_id'] : null;
        $this->assertCanUseSede($request, $sedeId);

        return response()->json([
            'data' => $this->devolucionVentaService->buscarVentas([
                'termino' => $data['termino'] ?? null,
                'sede_id' => $sedeId,
            ]),
        ]);
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'termino' => ['nullable', 'string', 'max:120'],
            'sede_id' => ['nullable', 'integer'],
        ]);

        $sedeId = isset($data['sede_id']) ? (int) $data['sede_id'] : null;
        $this->assertCanUseSede($request, $sedeId);

        return response()->json([
            'data' => $this->devolucionVentaService->historial([
                'termino' => $data['termino'] ?? null,
                'sede_id' => $sedeId,
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'venta_id' => ['required', 'integer'],
            'tipo' => ['required', 'string', 'in:DEVOLUCION,ANULACION'],
            'motivo' => ['required', 'string', 'max:120'],
            'observacion' => ['nullable', 'string'],
            'reintegra_stock' => ['nullable', 'boolean'],
            'detalles' => ['nullable', 'array'],
            'detalles.*.venta_detalle_id' => ['required_with:detalles', 'integer'],
            'detalles.*.cantidad' => ['required_with:detalles', 'numeric', 'gt:0'],
        ]);

        $ventaSedeId = DB::table('ventas.ventas')->where('id', (int) $data['venta_id'])->value('sede_id');
        $this->assertCanUseSede($request, $ventaSedeId ? (int) $ventaSedeId : null);

        try {
            return response()->json([
                'message' => $data['tipo'] === 'ANULACION'
                    ? 'Venta anulada correctamente'
                    : 'Devolución registrada correctamente',
                'data' => $this->devolucionVentaService->registrar($data, $request),
            ], 201);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
