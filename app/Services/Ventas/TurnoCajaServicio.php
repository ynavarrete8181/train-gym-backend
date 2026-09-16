<?php

namespace App\Services\Ventas;

use App\Services\Concerns\RegistraAuditoria;
use App\Services\Seguridad\AlcanceOperativoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TurnoCajaServicio
{
    use RegistraAuditoria;

    public function __construct(private readonly AlcanceOperativoService $alcance)
    {
    }

    public function listar(array $filtros, ?int $usuarioId = null)
    {
        $query = DB::table('ventas.turnos_caja as t')
            ->join('ventas.cajas as c', 'c.id', '=', 't.caja_id')
            ->join('institucional.sedes as s', 's.id_sede', '=', 't.sede_id')
            ->join('seguridad.users as u', 'u.id', '=', 't.usuario_id')
            ->leftJoin('seguridad.users as uc', 'uc.id', '=', 't.cerrado_por')
            ->select(
                't.*',
                'c.codigo as caja_codigo',
                'c.nombre as caja_nombre',
                's.nombre as sede_nombre',
                'u.name as cajero_nombre',
                'uc.name as cerrado_por_nombre'
            );

        $this->alcance->aplicarSedes($query, 't.sede_id', $usuarioId, 'maneja_caja');

        if (! empty($filtros['busqueda'])) {
            $texto = mb_strtolower($filtros['busqueda']);
            $query->where(function ($q) use ($texto): void {
                $q->whereRaw('LOWER(c.nombre) LIKE ?', ["%{$texto}%"])
                    ->orWhereRaw('LOWER(c.codigo) LIKE ?', ["%{$texto}%"])
                    ->orWhereRaw('LOWER(s.nombre) LIKE ?', ["%{$texto}%"])
                    ->orWhereRaw('LOWER(u.name) LIKE ?', ["%{$texto}%"])
                    ->orWhereRaw('LOWER(t.estado) LIKE ?', ["%{$texto}%"]);
            });
        }

        $this->filtrarTexto($query, 'c.nombre', $filtros['caja'] ?? null);
        $this->filtrarTexto($query, 's.nombre', $filtros['sede'] ?? null);
        $this->filtrarTexto($query, 'u.name', $filtros['cajero'] ?? null);
        $this->filtrarTexto($query, 't.estado', $filtros['estado'] ?? null);

        if (! empty($filtros['desde'])) {
            $query->whereDate('t.fecha_apertura', '>=', $filtros['desde']);
        }
        if (! empty($filtros['hasta'])) {
            $query->whereDate('t.fecha_apertura', '<=', $filtros['hasta']);
        }

        return $query
            ->orderByDesc('t.fecha_apertura')
            ->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function abrir(array $datos, int $usuarioId): object
    {
        return DB::transaction(function () use ($datos, $usuarioId): object {
            $caja = DB::table('ventas.cajas')->where('id', $datos['caja_id'])->where('activa', true)->first();
            if (! $caja) {
                throw ValidationException::withMessages(['caja_id' => 'La caja seleccionada no está activa.']);
            }

            $sedeId = $this->alcance->validarSede($usuarioId, (int) $caja->sede_id, 'maneja_caja');

            if (DB::table('ventas.turnos_caja')->where('caja_id', $caja->id)->where('estado', 'ABIERTA')->exists()) {
                throw ValidationException::withMessages(['caja_id' => 'Esta caja ya tiene un turno abierto.']);
            }

            if (DB::table('ventas.turnos_caja')->where('usuario_id', $usuarioId)->where('estado', 'ABIERTA')->exists()) {
                throw ValidationException::withMessages(['caja_id' => 'Ya tienes un turno de caja abierto. Debes cerrarlo antes de abrir otro.']);
            }

            $id = DB::table('ventas.turnos_caja')->insertGetId([
                'caja_id' => $caja->id,
                'usuario_id' => $usuarioId,
                'sede_id' => $sedeId,
                'fecha_apertura' => now(),
                'saldo_inicial' => round((float) ($datos['saldo_inicial'] ?? 0), 2),
                'estado' => 'ABIERTA',
                'observaciones_apertura' => $datos['observaciones'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $turno = $this->obtener($id);
            $this->auditar('ventas', 'ABRIR_CAJA', 'ventas.turnos_caja', $id, null, $turno, 'Apertura de turno de caja.');

            return $turno;
        });
    }

    public function cerrar(int $id, array $datos, int $usuarioId): object
    {
        return DB::transaction(function () use ($id, $datos, $usuarioId): object {
            $turno = DB::table('ventas.turnos_caja')->where('id', $id)->lockForUpdate()->first();
            if (! $turno) {
                throw ValidationException::withMessages(['turno_id' => 'El turno de caja no existe.']);
            }
            if ($turno->estado !== 'ABIERTA') {
                throw ValidationException::withMessages(['turno_id' => 'El turno de caja ya está cerrado.']);
            }

            $this->alcance->validarSede($usuarioId, (int) $turno->sede_id, 'maneja_caja');

            if ((int) $turno->usuario_id !== $usuarioId && ! $this->puedeCerrarTurnoAjeno($usuarioId)) {
                throw ValidationException::withMessages(['turno_id' => 'Solo el cajero del turno o un supervisor/administrador puede cerrarlo.']);
            }

            $efectivoCobrado = (float) DB::table('ventas.pagos')
                ->where('turno_caja_id', $id)
                ->where('estado', 'CONFIRMADO')
                ->where('metodo_pago', 'EFECTIVO')
                ->sum('monto');

            $esperado = round((float) $turno->saldo_inicial + $efectivoCobrado, 2);
            $contado = round((float) $datos['efectivo_contado'], 2);
            $diferencia = round($contado - $esperado, 2);

            $antes = $this->obtener($id);
            DB::table('ventas.turnos_caja')->where('id', $id)->update([
                'fecha_cierre' => now(),
                'efectivo_esperado' => $esperado,
                'efectivo_contado' => $contado,
                'diferencia' => $diferencia,
                'estado' => 'CERRADA',
                'observaciones_cierre' => $datos['observaciones'] ?? null,
                'cerrado_por' => $usuarioId,
                'updated_at' => now(),
            ]);

            $cerrado = $this->obtener($id);
            $this->auditar('ventas', 'CERRAR_CAJA', 'ventas.turnos_caja', $id, $antes, $cerrado, 'Cierre de turno de caja.');

            return $cerrado;
        });
    }

    public function turnoAbiertoUsuario(int $usuarioId, ?int $cajaId = null): ?object
    {
        $query = DB::table('ventas.turnos_caja as t')
            ->join('ventas.cajas as c', 'c.id', '=', 't.caja_id')
            ->join('institucional.sedes as s', 's.id_sede', '=', 't.sede_id')
            ->where('t.usuario_id', $usuarioId)
            ->where('t.estado', 'ABIERTA')
            ->select('t.*', 'c.nombre as caja_nombre', 'c.codigo as caja_codigo', 's.nombre as sede_nombre');

        if ($cajaId) {
            $query->where('t.caja_id', $cajaId);
        }

        return $query->orderByDesc('t.fecha_apertura')->first();
    }

    public function catalogos(int $usuarioId): array
    {
        $sedes = $this->alcance->sedesPermitidas($usuarioId, 'maneja_caja');

        return [
            'cajas' => DB::table('ventas.cajas')
                ->where('activa', true)
                ->whereIn('sede_id', $sedes)
                ->orderBy('nombre')
                ->get(['id', 'codigo', 'nombre', 'sede_id']),
            'sedes' => DB::table('institucional.sedes')
                ->whereIn('id_sede', $sedes)
                ->orderBy('nombre')
                ->get(['id_sede as id', 'nombre']),
            'turno_abierto' => $this->turnoAbiertoUsuario($usuarioId),
        ];
    }

    public function opcionesFiltro(int $usuarioId): array
    {
        $sedes = $this->alcance->sedesPermitidas($usuarioId, 'maneja_caja');

        return [
            'caja' => DB::table('ventas.turnos_caja as t')->join('ventas.cajas as c', 'c.id', '=', 't.caja_id')->whereIn('t.sede_id', $sedes)->distinct()->orderBy('c.nombre')->pluck('c.nombre')->values(),
            'sede' => DB::table('ventas.turnos_caja as t')->join('institucional.sedes as s', 's.id_sede', '=', 't.sede_id')->whereIn('t.sede_id', $sedes)->distinct()->orderBy('s.nombre')->pluck('s.nombre')->values(),
            'cajero' => DB::table('ventas.turnos_caja as t')->join('seguridad.users as u', 'u.id', '=', 't.usuario_id')->whereIn('t.sede_id', $sedes)->distinct()->orderBy('u.name')->pluck('u.name')->values(),
            'estado' => ['ABIERTA', 'CERRADA'],
        ];
    }

    private function obtener(int $id): object
    {
        return DB::table('ventas.turnos_caja as t')
            ->join('ventas.cajas as c', 'c.id', '=', 't.caja_id')
            ->join('institucional.sedes as s', 's.id_sede', '=', 't.sede_id')
            ->join('seguridad.users as u', 'u.id', '=', 't.usuario_id')
            ->leftJoin('seguridad.users as uc', 'uc.id', '=', 't.cerrado_por')
            ->where('t.id', $id)
            ->select('t.*', 'c.codigo as caja_codigo', 'c.nombre as caja_nombre', 's.nombre as sede_nombre', 'u.name as cajero_nombre', 'uc.name as cerrado_por_nombre')
            ->first();
    }

    private function puedeCerrarTurnoAjeno(int $usuarioId): bool
    {
        return DB::table('seguridad.users as u')
            ->join('seguridad.cpu_userrole as r', 'r.id_userrole', '=', 'u.usr_tipo')
            ->where('u.id', $usuarioId)
            ->whereIn('r.role', ['SUPERADMINISTRADOR', 'ADMINISTRADOR', 'SUPERVISOR DE VENTAS'])
            ->exists();
    }

    private function filtrarTexto($query, string $columna, mixed $valor): void
    {
        if (empty($valor)) {
            return;
        }
        is_array($valor)
            ? $query->whereIn($columna, array_filter($valor))
            : $query->whereRaw("LOWER({$columna}) LIKE ?", ['%' . mb_strtolower($valor) . '%']);
    }
}
