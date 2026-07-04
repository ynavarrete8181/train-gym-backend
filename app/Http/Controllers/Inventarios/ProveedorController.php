<?php

namespace App\Http\Controllers\Inventarios;

use App\Http\Controllers\Controller;
use App\Services\Inventarios\ProveedorService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProveedorController extends Controller
{
    public function __construct(private ProveedorService $proveedorService)
    {
    }

    public function index()
    {
        return response()->json($this->proveedorService->all());
    }

    public function show(int $id)
    {
        $proveedor = $this->proveedorService->find($id);

        if (!$proveedor) {
            return response()->json(['message' => 'Proveedor no encontrado'], 404);
        }

        return response()->json($proveedor);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules(true));
        $proveedor = $this->proveedorService->create($validated, $request);

        return response()->json($proveedor, 201);
    }

    public function update(Request $request, int $id)
    {
        $validated = $request->validate($this->rules(false, $id));
        $proveedor = $this->proveedorService->update($id, $validated, $request);

        if (!$proveedor) {
            return response()->json(['message' => 'Proveedor no encontrado'], 404);
        }

        return response()->json($proveedor);
    }

    public function destroy(Request $request, int $id)
    {
        $proveedor = $this->proveedorService->delete($id, $request);

        if (!$proveedor) {
            return response()->json(['message' => 'Proveedor no encontrado'], 404);
        }

        return response()->json([
            'message' => 'Proveedor desactivado correctamente',
            'data' => $proveedor,
        ]);
    }

    private function rules(bool $isCreate, ?int $id = null): array
    {
        $rucRule = $isCreate
            ? 'nullable|string|unique:inventario.proveedores,prov_ruc'
            : ['nullable', 'string', Rule::unique('inventario.proveedores', 'prov_ruc')->ignore($id, 'prov_id')];

        return [
            'ruc' => $rucRule,
            'nombre' => 'required|string|max:255',
            'direccion' => 'nullable|string',
            'telefono' => 'nullable|string',
            'correo' => 'nullable|email',
            'estado' => 'nullable|boolean',
        ];
    }
}
