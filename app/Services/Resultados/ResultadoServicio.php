<?php

namespace App\Services\Resultados;

use Illuminate\Support\Facades\DB;

class ResultadoServicio
{
    public function resumen(): array
    {
        return [
            'clientes_activos' => DB::table('gimnasio.deportistas')->where('estado', 'ACTIVO')->count(),
            'membresias_activas' => DB::table('gimnasio.membresias')->where('estado', 'ACTIVA')->count(),
            'asistencias_hoy' => DB::table('acceso.asistencias')->whereDate('fecha_hora', now()->toDateString())->count(),
            'ventas_mes' => (float) DB::table('ventas.ventas')
                ->whereMonth('fecha_venta', now()->month)
                ->whereYear('fecha_venta', now()->year)
                ->where('estado', '<>', 'ANULADA')
                ->sum('total'),
            'reservas_hoy' => DB::table('gimnasio.reservas_dia')->whereDate('fecha', now()->toDateString())->count(),
            'planes_entrenamiento_activos' => DB::table('entrenamiento.planes')->where('estado', 'ACTIVO')->count(),
        ];
    }

    public function asistencia(array $filtros)
    {
        $query = DB::table('acceso.asistencias')
            ->join('gimnasio.deportistas', 'acceso.asistencias.cliente_id', '=', 'gimnasio.deportistas.id')
            ->join('seguridad.users', 'gimnasio.deportistas.usuario_id', '=', 'seguridad.users.id')
            ->leftJoin('institucional.sedes', 'acceso.asistencias.sede_id', '=', 'institucional.sedes.id_sede')
            ->selectRaw("DATE(acceso.asistencias.fecha_hora) as fecha, COALESCE(institucional.sedes.nombre, 'Sin sede') as sede, COUNT(*) as asistencias, COUNT(DISTINCT acceso.asistencias.cliente_id) as clientes")
            ->groupByRaw("DATE(acceso.asistencias.fecha_hora), COALESCE(institucional.sedes.nombre, 'Sin sede')");

        $this->buscar($query, $filtros['busqueda'] ?? null, ['institucional.sedes.nombre']);
        $this->filtrarTexto($query, 'institucional.sedes.nombre', $filtros['sede'] ?? null);
        return $query->orderByDesc('fecha')->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function ventas(array $filtros)
    {
        $query = DB::table('ventas.ventas')
            ->selectRaw('DATE(fecha_venta) as fecha, tipo_venta, estado, COUNT(*) as transacciones, SUM(total) as total')
            ->groupByRaw('DATE(fecha_venta), tipo_venta, estado');

        $this->buscar($query, $filtros['busqueda'] ?? null, ['tipo_venta', 'estado']);
        $this->filtrarTexto($query, 'tipo_venta', $filtros['tipo_venta'] ?? null);
        $this->filtrarTexto($query, 'estado', $filtros['estado'] ?? null);
        return $query->orderByDesc('fecha')->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function progreso(array $filtros)
    {
        $query = DB::table('entrenamiento.rm_registros')
            ->join('gimnasio.deportistas', 'entrenamiento.rm_registros.cliente_id', '=', 'gimnasio.deportistas.id')
            ->join('seguridad.users', 'gimnasio.deportistas.usuario_id', '=', 'seguridad.users.id')
            ->join('entrenamiento.ejercicios', 'entrenamiento.rm_registros.ejercicio_id', '=', 'entrenamiento.ejercicios.id')
            ->select('entrenamiento.rm_registros.*', 'seguridad.users.name as cliente_nombre', 'gimnasio.deportistas.codigo_deportista', 'entrenamiento.ejercicios.nombre as ejercicio_nombre');

        $this->buscar($query, $filtros['busqueda'] ?? null, ['seguridad.users.name', 'gimnasio.deportistas.codigo_deportista', 'entrenamiento.ejercicios.nombre']);
        $this->filtrarTexto($query, 'seguridad.users.name', $filtros['cliente'] ?? null);
        $this->filtrarTexto($query, 'entrenamiento.ejercicios.nombre', $filtros['ejercicio'] ?? null);

        return $query->orderByDesc('fecha_registro')->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function opcionesFiltro(): array
    {
        return [
            'cliente' => DB::table('seguridad.users')->distinct()->orderBy('name')->pluck('name')->values(),
            'ejercicio' => DB::table('entrenamiento.ejercicios')->distinct()->orderBy('nombre')->pluck('nombre')->values(),
            'tipo_venta' => DB::table('ventas.ventas')->distinct()->orderBy('tipo_venta')->pluck('tipo_venta')->values(),
            'estado_venta' => DB::table('ventas.ventas')->distinct()->orderBy('estado')->pluck('estado')->values(),
            'sede' => DB::table('institucional.sedes')->distinct()->orderBy('nombre')->pluck('nombre')->values(),
        ];
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
}
