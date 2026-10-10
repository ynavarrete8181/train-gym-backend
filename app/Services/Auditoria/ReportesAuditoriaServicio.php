<?php

namespace App\Services\Auditoria;

use Illuminate\Support\Facades\DB;

class ReportesAuditoriaServicio
{
    public function consultar(array $filtros): array
    {
        $tipo = mb_strtoupper((string) ($filtros['tipo'] ?? 'USUARIO'));

        return match ($tipo) {
            'MODULO' => $this->porModulo($filtros),
            'CRITICOS' => $this->cambiosCriticos($filtros),
            'ACCESOS_FALLIDOS' => $this->accesosFallidos($filtros),
            'ERRORES_RECURRENTES' => $this->erroresRecurrentes($filtros),
            default => $this->porUsuario($filtros),
        };
    }

    private function porUsuario(array $filtros): array
    {
        $query = DB::table('auditoria.eventos')
            ->selectRaw("COALESCE(usuario_nombre, 'Sistema') as usuario")
            ->selectRaw("COALESCE(rol, '—') as rol")
            ->selectRaw('COUNT(*) as eventos')
            ->selectRaw("COUNT(DISTINCT modulo) as modulos")
            ->selectRaw('MAX(created_at) as ultima_actividad');

        $this->fechas($query, $filtros);
        $this->buscar($query, $filtros['busqueda'] ?? null, ['usuario_nombre', 'rol', 'modulo', 'accion']);

        $query->groupBy('usuario_nombre', 'rol');

        return $this->paginar($query->orderByDesc('eventos'), $filtros);
    }

    private function porModulo(array $filtros): array
    {
        $query = DB::table('auditoria.eventos')
            ->selectRaw("COALESCE(modulo, 'Sin módulo') as modulo")
            ->selectRaw('COUNT(*) as eventos')
            ->selectRaw('COUNT(DISTINCT usuario_id) as usuarios')
            ->selectRaw('COUNT(DISTINCT accion) as acciones')
            ->selectRaw('MAX(created_at) as ultima_actividad');

        $this->fechas($query, $filtros);
        $this->buscar($query, $filtros['busqueda'] ?? null, ['modulo', 'accion', 'tabla']);

        $query->groupBy('modulo');

        return $this->paginar($query->orderByDesc('eventos'), $filtros);
    }

    private function cambiosCriticos(array $filtros): array
    {
        $acciones = [
            'ELIMINAR',
            'ANULAR',
            'CERRAR_CAJA',
            'CONCILIAR_CAJA',
            'ACTUALIZAR_COMPROMISO',
            'RENOVAR_MEMBRESIA',
            'VINCULAR_VENTA',
        ];

        $query = DB::table('auditoria.eventos')
            ->whereIn('accion', $acciones)
            ->select([
                'id',
                'created_at',
                'usuario_nombre as usuario',
                'rol',
                'modulo',
                'tabla',
                'registro_id',
                'accion',
                'descripcion',
                'ip',
            ]);

        $this->fechas($query, $filtros);
        $this->buscar($query, $filtros['busqueda'] ?? null, ['usuario_nombre', 'rol', 'modulo', 'tabla', 'registro_id', 'accion', 'descripcion']);

        return $this->paginar($query->orderByDesc('created_at'), $filtros);
    }

    private function accesosFallidos(array $filtros): array
    {
        $query = DB::table('auditoria.accesos')
            ->where('tipo', 'LOGIN_FALLIDO')
            ->selectRaw("COALESCE(email, 'Sin correo') as usuario")
            ->selectRaw("COALESCE(ip, 'Sin IP') as ip")
            ->selectRaw('COUNT(*) as intentos')
            ->selectRaw('MAX(created_at) as ultimo_intento')
            ->selectRaw('MAX(motivo) as ultimo_motivo');

        $this->fechas($query, $filtros);
        $this->buscar($query, $filtros['busqueda'] ?? null, ['email', 'ip', 'motivo']);

        $query->groupBy('email', 'ip');

        return $this->paginar($query->orderByDesc('intentos'), $filtros);
    }

    private function erroresRecurrentes(array $filtros): array
    {
        $query = DB::table('logs.eventos')
            ->where('nivel', 'ERROR')
            ->selectRaw("COALESCE(modulo, 'general') as modulo")
            ->selectRaw("COALESCE(accion, 'excepcion') as accion")
            ->selectRaw('mensaje')
            ->selectRaw('COUNT(*) as ocurrencias')
            ->selectRaw('MAX(created_at) as ultima_ocurrencia');

        $this->fechas($query, $filtros);
        $this->buscar($query, $filtros['busqueda'] ?? null, ['modulo', 'accion', 'mensaje']);

        $query->groupBy('modulo', 'accion', 'mensaje');

        return $this->paginar($query->orderByDesc('ocurrencias'), $filtros);
    }

    private function fechas($query, array $filtros): void
    {
        if (! empty($filtros['desde'])) {
            $query->where('created_at', '>=', $filtros['desde'] . ' 00:00:00');
        }

        if (! empty($filtros['hasta'])) {
            $query->where('created_at', '<=', $filtros['hasta'] . ' 23:59:59');
        }
    }

    private function buscar($query, ?string $texto, array $campos): void
    {
        if (! $texto) {
            return;
        }

        $valor = '%' . mb_strtolower($texto) . '%';
        $query->where(function ($q) use ($campos, $valor): void {
            foreach ($campos as $indice => $campo) {
                $metodo = $indice === 0 ? 'whereRaw' : 'orWhereRaw';
                $q->{$metodo}("LOWER(COALESCE({$campo}::text, '')) LIKE ?", [$valor]);
            }
        });
    }

    private function paginar($query, array $filtros): array
    {
        $paginador = $query->paginate(
            $filtros['per_page'] ?? 10,
            ['*'],
            'page',
            $filtros['page'] ?? 1
        );

        return [
            'datos' => $paginador->items(),
            'meta' => [
                'pagina_actual' => $paginador->currentPage(),
                'por_pagina' => $paginador->perPage(),
                'total' => $paginador->total(),
                'ultima_pagina' => $paginador->lastPage(),
            ],
        ];
    }
}
