<?php

namespace App\Http\Controllers\Inventarios;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventarioInicialController extends Controller
{
    /**
     * Registra el inventario inicial de un producto en una sede.
     */
    public function store(Request $request, int $id)
    {
        $data = $request->validate([
            'sede_id' => ['required', 'integer'],
            'cantidad' => ['required', 'numeric', 'gte:0'],
            'stock_minimo' => ['nullable', 'numeric', 'gte:0'],
            'ubicacion' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            DB::beginTransaction();

            // 1. Actualizar o Crear Existencia
            DB::table('inventario_existencias')->updateOrInsert(
                ['producto_id' => $id, 'sede_id' => $data['sede_id']],
                [
                    'cantidad' => $data['cantidad'],
                    'stock_minimo' => $data['stock_minimo'] ?? 0,
                    'ubicacion' => $data['ubicacion'] ?? null,
                    'updated_at' => now(),
                ]
            );

            // 2. Registrar el movimiento para auditoría
            DB::table('inventario_movimientos')->insert([
                'producto_id' => $id,
                'sede_id' => $data['sede_id'],
                'tipo_movimiento' => 'ENTRADA',
                'motivo' => 'INVENTARIO INICIAL',
                'cantidad' => $data['cantidad'],
                'fecha' => now(),
                'user_id' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Inventario inicial registrado correctamente',
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error al guardar inventario: ' . $e->getMessage()], 500);
        }
    }
}
