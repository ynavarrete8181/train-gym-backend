<?php

namespace App\Services\Reportes;

use Illuminate\Support\Facades\DB;

class ReporteServicio
{
    public function disponibles(array $filtros)
    {
        $query = DB::table('reportes.definiciones');
        $this->buscar($query, $filtros['busqueda'] ?? null, ['codigo', 'nombre', 'categoria', 'descripcion']);
        $this->filtrarTexto($query, 'categoria', $filtros['categoria'] ?? null);
        $this->filtrarBooleano($query, 'activo', $filtros['activo'] ?? null);

        return $query->orderBy('categoria')->orderBy('nombre')->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function historial(array $filtros)
    {
        $query = DB::table('reportes.ejecuciones')
            ->join('reportes.definiciones', 'reportes.ejecuciones.definicion_id', '=', 'reportes.definiciones.id')
            ->leftJoin('seguridad.users', 'reportes.ejecuciones.usuario_id', '=', 'seguridad.users.id')
            ->select(
                'reportes.ejecuciones.*',
                'reportes.definiciones.codigo',
                'reportes.definiciones.nombre as reporte_nombre',
                'reportes.definiciones.categoria',
                'seguridad.users.name as usuario_nombre'
            );

        $this->buscar($query, $filtros['busqueda'] ?? null, ['reportes.definiciones.nombre', 'reportes.definiciones.categoria', 'seguridad.users.name']);
        $this->filtrarTexto($query, 'reportes.definiciones.categoria', $filtros['categoria'] ?? null);
        $this->filtrarTexto($query, 'reportes.ejecuciones.estado', $filtros['estado'] ?? null);
        $this->filtrarTexto($query, 'reportes.ejecuciones.formato', $filtros['formato'] ?? null);

        return $query->orderByDesc('reportes.ejecuciones.generado_at')->orderByDesc('reportes.ejecuciones.id')->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function generar(array $datos, ?int $usuarioId): object
    {
        $definicion = DB::table('reportes.definiciones')->where('codigo', $datos['codigo'])->where('activo', true)->first();
        abort_if(! $definicion, 404, 'Reporte no encontrado.');

        $resultado = $this->resultadoPorCodigo($definicion->codigo);
        $id = DB::table('reportes.ejecuciones')->insertGetId([
            'definicion_id' => $definicion->id,
            'usuario_id' => $usuarioId,
            'formato' => $datos['formato'] ?? 'VISTA',
            'estado' => 'GENERADO',
            'filtros' => json_encode($datos['filtros'] ?? []),
            'resultado' => json_encode($resultado),
            'generado_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('reportes.ejecuciones')
            ->join('reportes.definiciones', 'reportes.ejecuciones.definicion_id', '=', 'reportes.definiciones.id')
            ->select('reportes.ejecuciones.*', 'reportes.definiciones.codigo', 'reportes.definiciones.nombre as reporte_nombre', 'reportes.definiciones.categoria')
            ->where('reportes.ejecuciones.id', $id)
            ->first();
    }

    public function opcionesFiltro(): array
    {
        return [
            'categoria' => DB::table('reportes.definiciones')->distinct()->orderBy('categoria')->pluck('categoria')->values(),
            'estado' => DB::table('reportes.ejecuciones')->distinct()->orderBy('estado')->pluck('estado')->values(),
            'formato' => DB::table('reportes.ejecuciones')->distinct()->orderBy('formato')->pluck('formato')->values(),
        ];
    }

    private function resultadoPorCodigo(string $codigo): array
    {
        return match ($codigo) {
            'MEMBRESIAS-VIGENCIA' => [
                'activas' => DB::table('gimnasio.membresias')->where('estado', 'ACTIVA')->count(),
                'vencidas' => DB::table('gimnasio.membresias')->where('estado', 'VENCIDA')->count(),
                'por_vencer' => DB::table('gimnasio.membresias')->where('estado', 'ACTIVA')->whereDate('fecha_fin', '<=', now()->addDays(7))->count(),
            ],
            'VENTAS-PERIODO' => [
                'transacciones' => DB::table('ventas.ventas')->where('estado', '<>', 'ANULADA')->count(),
                'total' => (float) DB::table('ventas.ventas')->where('estado', '<>', 'ANULADA')->sum('total'),
            ],
            'ASISTENCIA-CLIENTES' => [
                'asistencias' => DB::table('acceso.asistencias')->count(),
                'clientes' => DB::table('acceso.asistencias')->distinct('cliente_id')->count('cliente_id'),
            ],
            'PROGRESO-FISICO' => [
                'registros' => DB::table('entrenamiento.rm_registros')->count(),
                'clientes' => DB::table('entrenamiento.rm_registros')->distinct('cliente_id')->count('cliente_id'),
            ],
            default => [
                'clientes_activos' => DB::table('gimnasio.deportistas')->where('estado', 'ACTIVO')->count(),
                'membresias_activas' => DB::table('gimnasio.membresias')->where('estado', 'ACTIVA')->count(),
                'ventas_mes' => (float) DB::table('ventas.ventas')->whereMonth('fecha_venta', now()->month)->whereYear('fecha_venta', now()->year)->where('estado', '<>', 'ANULADA')->sum('total'),
                'asistencias_hoy' => DB::table('acceso.asistencias')->whereDate('fecha_hora', now()->toDateString())->count(),
            ],
        };
    }

    private function buscar($query, ?string $busqueda, array $columnas): void
    {
        if (empty($busqueda)) return;
        $texto = mb_strtolower($busqueda);
        $query->where(function ($q) use ($columnas, $texto): void {
            foreach ($columnas as $columna) $q->orWhereRaw("LOWER({$columna}) LIKE ?", ["%{$texto}%"]);
        });
    }

    private function filtrarTexto($query, string $columna, mixed $valor): void
    {
        if (empty($valor)) return;
        is_array($valor) ? $query->whereIn($columna, array_filter($valor)) : $query->whereRaw("LOWER({$columna}) LIKE ?", ['%' . mb_strtolower($valor) . '%']);
    }

    private function filtrarBooleano($query, string $columna, mixed $valor): void
    {
        if ($valor === null || $valor === '') return;
        $query->where($columna, filter_var($valor, FILTER_VALIDATE_BOOLEAN));
    }
}
