<?php

namespace App\Services\Ventas;

use App\Support\FechaNegocio;
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

        if ($this->alcance->esCajero($usuarioId)) {
            $query->where('t.usuario_id', $usuarioId);
        }

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
            $query->whereRaw(FechaNegocio::diaSql('t.fecha_apertura').' >= ?', [$filtros['desde']]);
        }
        if (! empty($filtros['hasta'])) {
            $query->whereRaw(FechaNegocio::diaSql('t.fecha_apertura').' <= ?', [$filtros['hasta']]);
        }

        $paginador = $query
            ->orderByDesc('t.fecha_apertura')
            ->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);

        $paginador->setCollection(
            $paginador->getCollection()->map(fn ($turno) => $this->adjuntarResumenCobros($turno))
        );

        return $paginador;
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

            $resumen = $this->resumenCobrosTurno($id);
            $efectivoCobrado = (float) $resumen['efectivo_cobrado'];
            $esperado = round((float) $turno->saldo_inicial + $efectivoCobrado, 2);
            $contado = round((float) $datos['efectivo_contado'], 2);
            $diferencia = round($contado - $esperado, 2);

            $antes = $this->obtener($id);
            DB::table('ventas.turnos_caja')->where('id', $id)->update([
                'fecha_cierre' => now(),
                'efectivo_esperado' => $esperado,
                'efectivo_contado' => $contado,
                'diferencia' => $diferencia,
                'efectivo_cobrado' => $resumen['efectivo_cobrado'],
                'transferencia_cobrada' => $resumen['transferencia_cobrada'],
                'tarjeta_cobrada' => $resumen['tarjeta_cobrada'],
                'deposito_cobrado' => $resumen['deposito_cobrado'],
                'otros_cobrado' => $resumen['otros_cobrado'],
                'total_cobrado' => $resumen['total_cobrado'],
                'cantidad_cobros' => $resumen['cantidad_cobros'],
                'estado' => 'CERRADA',
                'tipo_cierre' => 'MANUAL',
                'requiere_arqueo' => false,
                'conciliado_at' => now(),
                'conciliado_por' => $usuarioId,
                'observaciones_cierre' => $datos['observaciones'] ?? null,
                'cerrado_por' => $usuarioId,
                'updated_at' => now(),
            ]);

            $cerrado = $this->obtener($id);
            $this->auditar('ventas', 'CERRAR_CAJA', 'ventas.turnos_caja', $id, $antes, $cerrado, 'Cierre de turno de caja.');

            return $cerrado;
        });
    }

    public function conciliar(int $id, array $datos, int $usuarioId): object
    {
        return DB::transaction(function () use ($id, $datos, $usuarioId): object {
            $turno = DB::table('ventas.turnos_caja')->where('id', $id)->lockForUpdate()->first();

            if (! $turno) {
                throw ValidationException::withMessages(['turno_id' => 'El turno de caja no existe.']);
            }

            if ($turno->estado !== 'CERRADA' || ! (bool) $turno->requiere_arqueo) {
                throw ValidationException::withMessages([
                    'turno_id' => 'Este turno no tiene un arqueo automático pendiente de conciliación.',
                ]);
            }

            $this->alcance->validarSede($usuarioId, (int) $turno->sede_id, 'maneja_caja');

            if (! $this->puedeCerrarTurnoAjeno($usuarioId)) {
                throw ValidationException::withMessages([
                    'turno_id' => 'Solo un supervisor o administrador puede conciliar un cierre automático.',
                ]);
            }

            $contado = round((float) $datos['efectivo_contado'], 2);
            $esperado = round((float) $turno->efectivo_esperado, 2);
            $diferencia = round($contado - $esperado, 2);

            $antes = $this->obtener($id);

            DB::table('ventas.turnos_caja')->where('id', $id)->update([
                'efectivo_contado' => $contado,
                'diferencia' => $diferencia,
                'requiere_arqueo' => false,
                'conciliado_at' => now(),
                'conciliado_por' => $usuarioId,
                'observaciones_cierre' => $datos['observaciones'] ?? $turno->observaciones_cierre,
                'updated_at' => now(),
            ]);

            $conciliado = $this->obtener($id);
            $this->auditar(
                'ventas',
                'CONCILIAR_CAJA',
                'ventas.turnos_caja',
                $id,
                $antes,
                $conciliado,
                'Conciliación de cierre automático de caja.'
            );

            return $conciliado;
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

        $turno = $query->orderByDesc('t.fecha_apertura')->first();

        return $turno ? $this->adjuntarResumenCobros($turno) : null;
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
        $soloUsuario = $this->alcance->esCajero($usuarioId);

        $base = fn () => DB::table('ventas.turnos_caja as t')
            ->whereIn('t.sede_id', $sedes)
            ->when($soloUsuario, fn ($q) => $q->where('t.usuario_id', $usuarioId));

        return [
            'caja' => $base()->join('ventas.cajas as c', 'c.id', '=', 't.caja_id')->distinct()->orderBy('c.nombre')->pluck('c.nombre')->values(),
            'sede' => $base()->join('institucional.sedes as s', 's.id_sede', '=', 't.sede_id')->distinct()->orderBy('s.nombre')->pluck('s.nombre')->values(),
            'cajero' => $base()->join('seguridad.users as u', 'u.id', '=', 't.usuario_id')->distinct()->orderBy('u.name')->pluck('u.name')->values(),
            'estado' => ['ABIERTA', 'CERRADA'],
        ];
    }

    /**
     * Corrige el método de un cobro mal registrado (p. ej. efectivo que fue transferencia).
     * Solo administradores; no cambia monto, cliente ni venta. Deja motivo y auditoría, y
     * recalcula el efectivo esperado del turno. Un turno ya cerrado vuelve a quedar pendiente
     * de conciliación para revisar el arqueo con los valores corregidos.
     */
    public function corregirMetodoPago(int $pagoId, array $datos, int $usuarioId): object
    {
        return DB::transaction(function () use ($pagoId, $datos, $usuarioId): object {
            if (! $this->alcance->puedeGestionarCartera($usuarioId)) {
                throw ValidationException::withMessages(['pago' => 'Solo un administrador puede corregir el método de un cobro.']);
            }
            $pago = DB::table('ventas.pagos')->where('id', $pagoId)->lockForUpdate()->first();
            if (! $pago || $pago->estado !== 'CONFIRMADO' || (float) $pago->monto <= 0) {
                throw ValidationException::withMessages(['pago' => 'Solo se puede corregir un cobro confirmado.']);
            }
            if ($pago->operacion_cobro_id && DB::table('ventas.pagos')->where('operacion_cobro_id', $pago->operacion_cobro_id)
                ->where('id', '<>', $pagoId)->where('estado', 'CONFIRMADO')->exists()) {
                throw ValidationException::withMessages(['pago' => 'Este cobro tiene varios pagos (varias cuentas o métodos): para cambiar el método, anúlalo y vuelve a cobrarlo. El número y el banco sí se pueden corregir.']);
            }
            $nuevo = strtoupper((string) $datos['metodo_pago']);
            // Transferencias y depósitos llevan banco del catálogo; los demás métodos no.
            $conBanco = in_array($nuevo, ['TRANSFERENCIA', 'DEPOSITO'], true);
            $institucion = null;
            if ($conBanco) {
                $institucion = DB::table('configuracion.instituciones_financieras')
                    ->where('id', (int) ($datos['institucion_financiera_id'] ?? 0))->where('activo', true)->first(['id', 'nombre']);
                if (! $institucion) {
                    throw ValidationException::withMessages(['institucion_financiera_id' => 'Elige el banco o cooperativa de la ' . ($nuevo === 'DEPOSITO' ? 'papeleta de depósito.' : 'transferencia.')]);
                }
            }
            if ($nuevo === $pago->metodo_pago && trim((string) ($datos['referencia'] ?? '')) === trim((string) ($pago->referencia ?? ''))
                && (int) ($institucion->id ?? 0) === (int) ($pago->institucion_financiera_id ?? 0)) {
                throw ValidationException::withMessages(['metodo_pago' => 'No hay cambios para guardar.']);
            }
            $turno = $pago->turno_caja_id
                ? DB::table('ventas.turnos_caja')->where('id', $pago->turno_caja_id)->lockForUpdate()->first() : null;
            if ($turno) {
                $this->alcance->validarSede($usuarioId, (int) $turno->sede_id, 'maneja_caja');
            }

            $usuario = DB::table('seguridad.users')->where('id', $usuarioId)->value('name') ?: 'Administrador';
            $nota = sprintf('Corrección de método: %s → %s%s%s. Motivo: %s (%s, %s).',
                $pago->metodo_pago, $nuevo, ! empty($datos['referencia']) ? ' (ref. ' . trim($datos['referencia']) . ')' : '',
                $institucion ? ', ' . $institucion->nombre : '',
                trim($datos['motivo']), $usuario, now()->format('d/m/Y H:i'));
            $esEfectivo = $nuevo === 'EFECTIVO';
            DB::table('ventas.pagos')->where('id', $pagoId)->update([
                'metodo_pago' => $nuevo,
                'referencia' => ! empty($datos['referencia']) ? trim($datos['referencia']) : ($esEfectivo ? null : $pago->referencia),
                'efectivo_recibido' => $esEfectivo ? $pago->monto : null,
                'cambio_entregado' => $esEfectivo ? 0 : null,
                'institucion_financiera_id' => $institucion->id ?? null,
                'banco' => $institucion->nombre ?? null,
                'observaciones' => trim(($pago->observaciones ? $pago->observaciones . ' ' : '') . $nota),
                'updated_at' => now(),
            ]);
            $this->auditar('ventas', 'CORREGIR_METODO_PAGO', 'ventas.pagos', $pagoId, $pago,
                DB::table('ventas.pagos')->where('id', $pagoId)->first(), $nota);

            if ($turno && $turno->estado === 'CERRADA') {
                $antes = $this->obtener((int) $turno->id);
                $resumen = $this->resumenCobrosTurno((int) $turno->id);
                $esperado = round((float) $turno->saldo_inicial + (float) $resumen['efectivo_cobrado'], 2);
                $contado = $turno->efectivo_contado !== null ? round((float) $turno->efectivo_contado, 2) : null;
                DB::table('ventas.turnos_caja')->where('id', $turno->id)->update($resumen + [
                    'efectivo_esperado' => $esperado,
                    'diferencia' => $contado !== null ? round($contado - $esperado, 2) : null,
                    // El arqueo se hizo con otros valores: vuelve a revisarse.
                    'requiere_arqueo' => true,
                    'conciliado_at' => null,
                    'conciliado_por' => null,
                    'observaciones_cierre' => trim(($turno->observaciones_cierre ? $turno->observaciones_cierre . ' ' : '')
                        . "Reabierto para conciliar: {$nota}"),
                    'updated_at' => now(),
                ]);
                $this->auditar('ventas', 'RECALCULAR_CAJA', 'ventas.turnos_caja', (int) $turno->id, $antes,
                    $this->obtener((int) $turno->id), 'Recalculo por corrección de método de cobro.');
            }

            return DB::table('ventas.pagos')->where('id', $pagoId)->first();
        });
    }

    /**
     * Completa o corrige el banco y el número de comprobante de las transferencias o
     * depósitos de un cobro. El monto, el método y la caja no cambian; queda en el
     * historial del pago y en auditoría. Solo administradores.
     */
    public function completarBancoCobro(string $operacion, array $datos, int $usuarioId): array
    {
        return DB::transaction(function () use ($operacion, $datos, $usuarioId): array {
            if (! $this->alcance->puedeGestionarCartera($usuarioId)) {
                throw ValidationException::withMessages(['pago' => 'Solo un administrador puede completar el banco de un cobro.']);
            }
            $metodo = strtoupper((string) $datos['metodo_pago']);
            $pagos = DB::table('ventas.pagos')
                ->when(str_starts_with($operacion, 'PAGO-'),
                    fn ($q) => $q->where('id', (int) substr($operacion, 5))->whereNull('operacion_cobro_id'),
                    fn ($q) => $q->where('operacion_cobro_id', $operacion))
                ->where('estado', 'CONFIRMADO')
                ->where('metodo_pago', $metodo)
                ->lockForUpdate()
                ->get();
            if ($pagos->isEmpty()) {
                throw ValidationException::withMessages(['pago' => 'El cobro no tiene pagos confirmados por ' . mb_strtolower($metodo) . '.']);
            }
            foreach ($pagos as $pago) {
                if ($pago->turno_caja_id) {
                    $sede = DB::table('ventas.turnos_caja')->where('id', $pago->turno_caja_id)->value('sede_id');
                    if ($sede) {
                        $this->alcance->validarSede($usuarioId, (int) $sede, 'maneja_caja');
                    }
                }
            }

            $institucion = DB::table('configuracion.instituciones_financieras')
                ->where('id', (int) $datos['institucion_financiera_id'])->where('activo', true)->first(['id', 'nombre']);
            if (! $institucion) {
                throw ValidationException::withMessages(['institucion_financiera_id' => 'El banco o cooperativa elegido no está activo.']);
            }
            $referencia = trim((string) ($datos['referencia'] ?? ''));
            if ($referencia === '' && $pagos->contains(fn ($p) => trim((string) $p->referencia) === '')) {
                throw ValidationException::withMessages(['referencia' => 'Escribe el número de comprobante.']);
            }

            $usuario = DB::table('seguridad.users')->where('id', $usuarioId)->value('name') ?: 'Administrador';
            foreach ($pagos as $pago) {
                $nuevaReferencia = $referencia !== '' ? $referencia : $pago->referencia;
                $nota = sprintf('Banco completado: %s%s%s. %s(%s, %s).',
                    $institucion->nombre,
                    $pago->banco && $pago->banco !== $institucion->nombre ? " (antes {$pago->banco})" : '',
                    $nuevaReferencia !== $pago->referencia ? ', comprobante ' . $nuevaReferencia . ($pago->referencia ? " (antes {$pago->referencia})" : '') : '',
                    trim((string) ($datos['motivo'] ?? '')) !== '' ? 'Motivo: ' . trim($datos['motivo']) . ' ' : '',
                    $usuario, now()->format('d/m/Y H:i'));
                DB::table('ventas.pagos')->where('id', $pago->id)->update([
                    'institucion_financiera_id' => $institucion->id,
                    'banco' => $institucion->nombre,
                    'referencia' => $nuevaReferencia,
                    'observaciones' => trim(($pago->observaciones ? $pago->observaciones . ' ' : '') . $nota),
                    'updated_at' => now(),
                ]);
                $this->auditar('ventas', 'COMPLETAR_BANCO', 'ventas.pagos', (int) $pago->id, $pago,
                    DB::table('ventas.pagos')->where('id', $pago->id)->first(), $nota);
            }

            return ['pagos_actualizados' => $pagos->count(), 'banco' => $institucion->nombre, 'referencia' => $referencia ?: $pagos->first()->referencia];
        });
    }

    public function resumenCobrosTurno(int $turnoId): array
    {
        $resumen = DB::table('ventas.pagos')
            ->where('turno_caja_id', $turnoId)
            ->where('estado', 'CONFIRMADO')
            ->selectRaw("COALESCE(SUM(CASE WHEN metodo_pago = 'EFECTIVO' THEN monto ELSE 0 END), 0) as efectivo")
            ->selectRaw("COALESCE(SUM(CASE WHEN metodo_pago = 'TRANSFERENCIA' THEN monto ELSE 0 END), 0) as transferencia")
            ->selectRaw("COALESCE(SUM(CASE WHEN metodo_pago = 'TARJETA' THEN monto ELSE 0 END), 0) as tarjeta")
            ->selectRaw("COALESCE(SUM(CASE WHEN metodo_pago = 'DEPOSITO' THEN monto ELSE 0 END), 0) as deposito")
            ->selectRaw("COALESCE(SUM(CASE WHEN metodo_pago NOT IN ('EFECTIVO','TRANSFERENCIA','TARJETA','DEPOSITO') THEN monto ELSE 0 END), 0) as otros")
            ->selectRaw('COALESCE(SUM(monto), 0) as total')
            ->selectRaw("COUNT(DISTINCT COALESCE(operacion_cobro_id, 'PAGO-' || id::text)) as cantidad")
            ->first();

        return [
            'efectivo_cobrado' => round((float) ($resumen->efectivo ?? 0), 2),
            'transferencia_cobrada' => round((float) ($resumen->transferencia ?? 0), 2),
            'tarjeta_cobrada' => round((float) ($resumen->tarjeta ?? 0), 2),
            'deposito_cobrado' => round((float) ($resumen->deposito ?? 0), 2),
            'otros_cobrado' => round((float) ($resumen->otros ?? 0), 2),
            'total_cobrado' => round((float) ($resumen->total ?? 0), 2),
            'cantidad_cobros' => (int) ($resumen->cantidad ?? 0),
        ];
    }

    private function adjuntarResumenCobros(object $turno): object
    {
        $resumen = $this->resumenCobrosTurno((int) $turno->id);

        foreach ($resumen as $campo => $valor) {
            $turno->{$campo} = $valor;
        }

        $turno->efectivo_esperado_actual = round(
            (float) ($turno->saldo_inicial ?? 0) + (float) $resumen['efectivo_cobrado'],
            2
        );

        return $turno;
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
