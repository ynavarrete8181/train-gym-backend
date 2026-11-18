<?php

namespace App\Http\Controllers\Api\Configuracion;

use App\Http\Controllers\Controller;
use App\Services\Auditoria\AuditoriaServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Catálogo de bancos y cooperativas: se crean, editan e inactivan; nunca se eliminan. */
class InstitucionFinancieraControlador extends Controller
{
    private const TABLA = 'configuracion.instituciones_financieras';

    public function __construct(private readonly AuditoriaServicio $auditoria)
    {
    }

    /** Todas, para administrar; con `activas=1`, solo las que se pueden elegir al cobrar. */
    public function index(Request $request)
    {
        $query = DB::table(self::TABLA)
            ->orderByRaw("CASE tipo WHEN 'BANCO' THEN 1 WHEN 'COOPERATIVA' THEN 2 WHEN 'MUTUALISTA' THEN 3 ELSE 4 END")
            ->orderBy('orden')
            ->orderBy('nombre');
        if ($request->boolean('activas')) {
            $query->where('activo', true);
        }

        return ApiResponse::exito('Instituciones financieras consultadas.', $query->get());
    }

    public function store(Request $request)
    {
        $datos = $this->validar($request);
        $id = DB::table(self::TABLA)->insertGetId($datos + ['created_at' => now(), 'updated_at' => now()]);
        $nuevo = DB::table(self::TABLA)->where('id', $id)->first();
        $this->auditar('CREAR', $id, null, $nuevo, "Institución financiera creada: {$nuevo->nombre}.");

        return ApiResponse::exito('Institución financiera creada.', (array) $nuevo, [], 201);
    }

    public function update(Request $request, int $id)
    {
        $antes = $this->buscar($id);
        $datos = $this->validar($request, $id);
        DB::table(self::TABLA)->where('id', $id)->update($datos + ['updated_at' => now()]);
        $despues = DB::table(self::TABLA)->where('id', $id)->first();
        $this->auditar('ACTUALIZAR', $id, $antes, $despues, "Institución financiera actualizada: {$despues->nombre}.");

        return ApiResponse::exito('Institución financiera actualizada.', (array) $despues);
    }

    public function estado(Request $request, int $id)
    {
        $antes = $this->buscar($id);
        $activo = (bool) $request->validate(['activo' => 'required|boolean'])['activo'];
        DB::table(self::TABLA)->where('id', $id)->update(['activo' => $activo, 'updated_at' => now()]);
        $despues = DB::table(self::TABLA)->where('id', $id)->first();
        $this->auditar($activo ? 'ACTIVAR' : 'INACTIVAR', $id, $antes, $despues,
            ($activo ? 'Institución financiera activada: ' : 'Institución financiera inactivada: ') . $despues->nombre . '.');

        return ApiResponse::exito($activo ? 'Institución activada.' : 'Institución inactivada. Los cobros anteriores la conservan.', (array) $despues);
    }

    private function validar(Request $request, ?int $id = null): array
    {
        $datos = $request->validate([
            'nombre' => 'required|string|max:120',
            'tipo' => ['required', Rule::in(['BANCO', 'COOPERATIVA', 'MUTUALISTA', 'OTRA'])],
            'orden' => 'nullable|integer|min:0|max:9999',
            'activo' => 'boolean',
        ]);
        $datos['nombre'] = preg_replace('/\s+/', ' ', trim($datos['nombre']));
        $repetido = DB::table(self::TABLA)->whereRaw('LOWER(nombre) = ?', [mb_strtolower($datos['nombre'])])
            ->when($id, fn ($q) => $q->where('id', '<>', $id))->exists();
        if ($repetido) {
            throw ValidationException::withMessages(['nombre' => 'Ya existe una institución con ese nombre.']);
        }
        $datos['orden'] = $datos['orden'] ?? 100;

        return $datos;
    }

    private function buscar(int $id): object
    {
        $registro = DB::table(self::TABLA)->where('id', $id)->first();
        if (! $registro) {
            throw ValidationException::withMessages(['id' => 'La institución financiera no existe.']);
        }

        return $registro;
    }

    private function auditar(string $accion, int $id, ?object $antes, ?object $despues, string $descripcion): void
    {
        $this->auditoria->registrar([
            'modulo' => 'configuracion', 'tabla' => self::TABLA, 'accion' => $accion, 'registro_id' => $id,
            'datos_antes' => $antes, 'datos_despues' => $despues, 'descripcion' => $descripcion,
        ]);
    }
}
