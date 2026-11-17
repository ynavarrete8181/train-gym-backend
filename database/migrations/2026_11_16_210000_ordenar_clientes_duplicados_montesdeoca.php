<?php

use App\Services\Auditoria\AuditoriaServicio;
use App\Services\CuentasCobrar\CarteraServicio;
use App\Services\Gimnasio\CodigoClienteServicio;
use App\Services\Gimnasio\ContratoMembresiaServicio;
use App\Services\Gimnasio\DeportistaServicio;
use App\Services\Gimnasio\DescuentoDeportistaServicio;
use App\Services\Gimnasio\EmisionCuotasServicio;
use App\Services\Gimnasio\HistorialComercialMembresiaServicio;
use App\Services\Configuracion\EstadoCatalogoServicio;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Familia Montesdeoca y fichas duplicadas (revisión del 16/11/2026).
 *
 * Lo que pasó:
 * - Del Sistema Base venían 3UJHG "Carlitos Montesdeoca" y AZVJW "Ema Montesdeoca"; el 14/11 se volvieron
 *   a registrar los niños (SUQTJ y 5EK69) y como tutor se eligió por error a 3UJHG en lugar del papá,
 *   Carlos Montesdeoca (6Y25Y), que es quien paga.
 * - El 16/11, para reutilizar las fichas repetidas, 6Y25Y se renombró a "Dean Saldarreaga" (y con él sus
 *   ventas y su pago de $60), 3UJHG a "Carlos Montesdeoca" con un contrato nuevo (MEMB-000032) y AZVJW a
 *   "PAOLA CAMBIAR Amen".
 * - La transferencia del 13/11 por $200 (comprobante 77961867) pagó el período del 04/10 al 08/11 de los
 *   tres: $70 Ema, $70 Carlitos y $60 Carlos. Se registró como si fuera el mes del 09/11 al 08/12, y los
 *   $70 de Carlitos no se registraron (su venta quedó en $10 por un descuento de $70 mal digitado).
 *
 * Lo que se corrige (todo con historial; nada se borra):
 * - 6Y25Y vuelve a ser Carlos Montesdeoca, deportista y tutor responsable de pago de sus dos hijos.
 * - Dean Saldarreaga se crea como cliente nuevo, sin historial ajeno.
 * - MEMB-000032 (Entrenamiento Dirigido desde el 09/11) pasa a la ficha de Carlos.
 * - 3UJHG y AZVJW recuperan su nombre original y se inactivan por duplicadas; TRUSR (Raimon Palma) igual.
 * - MEMB-000011 queda como el período pagado del 04/10 al 08/11; la venta duplicada de MEMB-000012 se anula.
 * - MEMB-000020 y MEMB-000021: período 1 del 04/10 al 08/11 (pagado) y período 2 del 09/11 al 08/12 por cobrar.
 */
return new class extends Migration
{
    private const MOTIVO_TUTOR = 'Tutor elegido por error el 14/11 (ficha duplicada 3UJHG). El tutor y responsable de pago es su papá, Carlos Montesdeoca (6Y25Y).';
    private const RENOMBRADO = '2026-11-16 20:49:00';

    public function up(): void
    {
        $carlos = $this->cliente('6Y25Y');
        $duplicadoCarlitos = $this->cliente('3UJHG');
        $duplicadoEma = $this->cliente('AZVJW');
        $carlitos = $this->cliente('SUQTJ');
        $ema = $this->cliente('5EK69');

        // Solo si la base sigue exactamente como quedó el 16/11.
        if (! $carlos || ! $duplicadoCarlitos || ! $duplicadoEma || ! $carlitos || ! $ema
            || $carlos->nombre_completo !== 'Dean Saldarreaga'
            || $duplicadoCarlitos->nombre_completo !== 'Carlos Montesdeoca') {
            return;
        }

        DB::transaction(function () use ($carlos, $duplicadoCarlitos, $duplicadoEma, $carlitos, $ema): void {
            $this->restaurarCarlosYCrearDean($carlos);
            $this->reasignarTutor([$carlitos, $ema], $carlos, $duplicadoCarlitos);
            $this->moverContratoNuevo('MEMB-000032', $duplicadoCarlitos, $carlos);
            $this->inactivarDuplicado($duplicadoCarlitos, 'Carlitos', 'Montesdeoca',
                'Ficha duplicada de Carlitos Montesdeoca Montalvo (SUQTJ). El 16/11 se había renombrado a "Carlos Montesdeoca"; su contrato y sus representados pasaron a la ficha correcta de Carlos (6Y25Y).');
            $this->inactivarDuplicado($duplicadoEma, 'Ema', 'Montesdeoca',
                'Ficha duplicada de Ema Montesdeoca (5EK69). El 16/11 se había renombrado a "PAOLA CAMBIAR Amen"; no tiene membresías ni ventas.');
            $raimon = $this->cliente('TRUSR');
            if ($raimon && $raimon->nombre_completo === 'Raimon Palma' && ! DB::table('membresias.membresias')->where('deportista_id', $raimon->id)->exists()) {
                $this->inactivarDuplicado($raimon, null, null,
                    'Ficha duplicada de Raymon Palma Sacoto (WSZQ7), mismo tutor Ramon Palma; la ficha vigente es WSZQ7 con su membresía MEMB-000023.');
            }

            $this->cerrarPeriodoAnteriorCarlos('MEMB-000011');
            $this->anularVentaDuplicada('MEMB-000012');
            $this->ordenarPeriodosNino('MEMB-000021', false);
            $this->ordenarPeriodosNino('MEMB-000020', true);

            // Emite las cuotas del período 09/11 – 08/12 igual que el proceso diario.
            app(EmisionCuotasServicio::class)->procesar(now('America/Guayaquil')->toDateString());
        });
    }

    public function down(): void
    {
        // Sin reversión automática: cada corrección queda en el historial del cliente y del contrato.
    }

    private function cliente(string $codigo): ?object
    {
        return DB::table('clientes.deportistas as d')
            ->join('personas.personas as p', 'p.id', '=', 'd.persona_id')
            ->where('d.codigo_deportista', $codigo)
            ->first(['d.id', 'd.persona_id', 'd.codigo_deportista', 'd.estado', 'd.sede_principal_id', 'p.nombre_completo', 'p.nombres', 'p.apellidos']);
    }

    private function auditar(string $tabla, int $id, string $accion, mixed $antes, mixed $despues, string $descripcion): void
    {
        app(AuditoriaServicio::class)->registrar([
            'modulo' => 'clientes', 'tabla' => $tabla, 'accion' => $accion, 'registro_id' => $id,
            'datos_antes' => $antes, 'datos_despues' => $despues, 'descripcion' => mb_substr($descripcion, 0, 255),
        ]);
    }

    private function renombrar(object $cliente, string $nombres, string $apellidos, string $motivo): void
    {
        $antes = DB::table('personas.personas')->where('id', $cliente->persona_id)->first();
        DB::table('personas.personas')->where('id', $cliente->persona_id)->update([
            'nombres' => $nombres, 'apellidos' => $apellidos, 'nombre_completo' => trim("$nombres $apellidos"), 'updated_at' => now(),
        ]);
        $this->auditar('personas.personas', (int) $cliente->persona_id, 'CORREGIR_NOMBRE', $antes,
            DB::table('personas.personas')->where('id', $cliente->persona_id)->first(), $motivo);
    }

    private function movimiento(int $membresiaId, string $tipo, string $motivo, array $detalle = []): void
    {
        DB::table('membresias.membresia_movimientos')->insert([
            'membresia_id' => $membresiaId, 'tipo' => $tipo, 'estado' => 'CONFIRMADO',
            'fecha_efectiva' => now('America/Guayaquil')->toDateString(), 'motivo' => $motivo,
            'usuario_id' => null, 'autorizado_por' => null,
            'detalle' => json_encode($detalle, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function restaurarCarlosYCrearDean(object $carlos): void
    {
        $this->renombrar($carlos, 'Carlos', 'Montesdeoca',
            'Se restaura el nombre de Carlos Montesdeoca (6Y25Y): el 16/11 se renombró a "Dean Saldarreaga" y con él su pago de $60 (COBRO-20261114-0003). Dean se registra como cliente nuevo.');

        $existeDean = DB::table('personas.personas as p')->join('clientes.deportistas as d', 'd.persona_id', '=', 'p.id')
            ->whereRaw("LOWER(p.nombre_completo) = 'dean saldarreaga'")->exists();
        if (! $existeDean) {
            app(DeportistaServicio::class)->crear([
                'codigo_deportista' => app(CodigoClienteServicio::class)->generar(),
                'estado' => 'ACTIVO',
                'sede_principal_id' => $carlos->sede_principal_id,
                'requiere_representante_legal' => false,
                'persona' => ['nombres' => 'Dean', 'apellidos' => 'Saldarreaga'],
            ]);
        }
    }

    /** @param array<int, object> $hijos */
    private function reasignarTutor(array $hijos, object $tutor, object $tutorErrado): void
    {
        $deportistas = app(DeportistaServicio::class);
        $deportistas->marcarTutor((int) $tutor->persona_id);

        foreach ($hijos as $hijo) {
            $antes = DB::table('clientes.deportista_representantes')->where('deportista_id', $hijo->id)->where('activo', true)->get();
            if ($antes->contains(fn ($r) => (int) $r->representante_persona_id === (int) $tutor->persona_id)) {
                continue;
            }
            $deportistas->finalizarTutores((int) $hijo->id, null, self::MOTIVO_TUTOR);
            DB::table('clientes.deportista_representantes')->insert([
                'deportista_id' => $hijo->id, 'representante_persona_id' => $tutor->persona_id,
                'tipo_relacion' => 'TUTOR', 'es_principal' => true, 'responsable_pago' => true, 'activo' => true,
                'fecha_inicio' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->auditar('clientes.deportista_representantes', (int) $hijo->id, 'CAMBIAR_TUTOR', $antes,
                DB::table('clientes.deportista_representantes')->where('deportista_id', $hijo->id)->where('activo', true)->get(),
                self::MOTIVO_TUTOR);
        }

        // La ficha duplicada deja de ser tutora: ya no tiene representados.
        if (! DB::table('clientes.deportista_representantes')->where('representante_persona_id', $tutorErrado->persona_id)->where('activo', true)->exists()) {
            DB::table('clientes.tutores')->where('persona_id', $tutorErrado->persona_id)->update(['activo' => false, 'updated_at' => now()]);
        }
    }

    private function moverContratoNuevo(string $codigo, object $de, object $a): void
    {
        $m = DB::table('membresias.membresias')->where('codigo_contrato', $codigo)->first();
        if (! $m || (int) $m->deportista_id !== (int) $de->id) {
            return;
        }
        DB::table('membresias.membresias')->where('id', $m->id)->update(['deportista_id' => $a->id, 'updated_at' => now()]);
        $ventas = DB::table('ventas.ventas')->where('membresia_id', $m->id)->pluck('id');
        DB::table('ventas.ventas')->whereIn('id', $ventas)->update(['cliente_id' => $a->id, 'updated_at' => now()]);
        DB::table('membresias.descuentos_deportistas')->where('deportista_id', $de->id)->where('created_at', '>=', self::RENOMBRADO)
            ->update(['deportista_id' => $a->id, 'updated_at' => now()]);
        DB::table('entrenamiento.asignaciones_entrenador_cliente')->where('membresia_id', $m->id)->update(['deportista_id' => $a->id, 'updated_at' => now()]);
        // Lo que se registró a nombre de la ficha duplicada desde que se renombró pertenece a Carlos.
        foreach (['acceso.asistencias', 'acceso.eventos', 'agenda.reservas_dia'] as $tabla) {
            DB::table($tabla)->where('cliente_id', $de->id)->where('created_at', '>=', self::RENOMBRADO)
                ->update(['cliente_id' => $a->id, 'updated_at' => now()]);
        }
        foreach ($ventas as $ventaId) {
            app(CarteraServicio::class)->sincronizarVenta((int) $ventaId);
        }
        $this->movimiento((int) $m->id, 'CORRECCION_DATOS',
            "El contrato se registró el 16/11 en la ficha duplicada {$de->codigo_deportista}, renombrada como Carlos Montesdeoca. Pasa a la ficha correcta de Carlos Montesdeoca ({$a->codigo_deportista}) con su venta y su descuento; el contrato y los importes no cambian.",
            ['deportista_anterior' => (int) $de->id, 'codigo_anterior' => $de->codigo_deportista,
                'deportista_nuevo' => (int) $a->id, 'codigo_nuevo' => $a->codigo_deportista]);
    }

    private function inactivarDuplicado(object $cliente, ?string $nombres, ?string $apellidos, string $motivo): void
    {
        if ($nombres !== null) {
            $this->renombrar($cliente, $nombres, (string) $apellidos, 'Se restaura el nombre original. ' . $motivo);
        }
        $antes = DB::table('clientes.deportistas')->where('id', $cliente->id)->first();
        DB::table('clientes.deportistas')->where('id', $cliente->id)->update(['estado' => 'INACTIVO', 'updated_at' => now()]);
        app(DeportistaServicio::class)->finalizarTutores((int) $cliente->id, null, 'Ficha inactivada por duplicada.');
        $this->auditar('clientes.deportistas', (int) $cliente->id, 'INACTIVAR', $antes,
            DB::table('clientes.deportistas')->where('id', $cliente->id)->first(), 'Inactivada por duplicada. ' . $motivo);
    }

    private function cerrarPeriodoAnteriorCarlos(string $codigo): void
    {
        $m = DB::table('membresias.membresias')->where('codigo_contrato', $codigo)->first();
        $periodo = $m ? DB::table('membresias.membresia_periodos')->where('membresia_id', $m->id)->where('numero_periodo', 1)->first() : null;
        $venta = $periodo?->venta_id ? DB::table('ventas.ventas')->where('id', $periodo->venta_id)->first() : null;
        if (! $periodo || ! $venta || $venta->estado !== 'PAGADA' || substr((string) $periodo->fecha_inicio, 0, 10) !== '2026-11-04') {
            return;
        }
        $vencida = app(EstadoCatalogoServicio::class)->aplicar(['estado' => 'VENCIDA'], 'MEMBRESIA');
        DB::table('membresias.membresia_periodos')->where('id', $periodo->id)
            ->update(['fecha_inicio' => '2026-10-04', 'fecha_fin' => '2026-11-08', 'estado' => 'VENCIDA', 'updated_at' => now()]);
        DB::table('membresias.membresias')->where('id', $m->id)->update([
            'fecha_inicio' => '2026-10-04', 'fecha_fin' => '2026-11-08', 'renovacion_automatica' => false,
            'estado' => $vencida['estado'], 'estado_id' => $vencida['estado_id'] ?? null, 'updated_at' => now(),
        ]);
        DB::table('entrenamiento.asignaciones_entrenador_cliente')->where('membresia_id', $m->id)->where('estado', 'ACTIVO')
            ->update(['estado' => 'FINALIZADO', 'fecha_fin' => '2026-11-08', 'updated_at' => now()]);
        DB::table('ventas.ventas')->where('id', $venta->id)->update([
            'observaciones' => trim(($venta->observaciones ?? '') . ' Corresponde al período del 04/10 al 08/11, pagado con la transferencia del 13/11 (comprobante 77961867).'),
            'updated_at' => now(),
        ]);
        $this->movimiento((int) $m->id, 'CORRECCION_DATOS',
            'Los $60 de la transferencia familiar del 13/11 (comprobante 77961867, $200) pagaron el período del 04/10 al 08/11, no el del 04/11 al 03/12. '
            . 'El período queda del 04/10 al 08/11 y el contrato vencido, sin renovación automática: desde el 09/11 Carlos sigue con MEMB-000032 (Entrenamiento Dirigido).',
            ['fecha_inicio_anterior' => '2026-11-04', 'fecha_fin_anterior' => '2026-12-03',
                'fecha_inicio_nueva' => '2026-10-04', 'fecha_fin_nueva' => '2026-11-08', 'venta' => $venta->numero]);
    }

    private function anularVentaDuplicada(string $codigo): void
    {
        $m = DB::table('membresias.membresias')->where('codigo_contrato', $codigo)->first();
        if (! $m || $m->estado !== 'CANCELADA') {
            return;
        }
        $motivo = 'Venta duplicada: MEMB-000012 se creó por doble registro el 01/11 junto con MEMB-000011, que es la válida y está pagada.';
        foreach (DB::table('ventas.ventas')->where('membresia_id', $m->id)->where('estado', 'PENDIENTE')->get() as $venta) {
            if (DB::table('ventas.pagos')->where('venta_id', $venta->id)->where('estado', 'CONFIRMADO')->exists()) {
                continue;
            }
            $anulada = app(EstadoCatalogoServicio::class)->aplicar([
                'estado' => 'ANULADA', 'observaciones' => trim(($venta->observaciones ?? '') . ' Anulada: ' . $motivo), 'updated_at' => now(),
            ], 'VENTA');
            DB::table('ventas.ventas')->where('id', $venta->id)->update($anulada);
            DB::table('ventas.comprobantes')->where('venta_id', $venta->id)->update(['estado' => 'ANULADO', 'updated_at' => now()]);
            DB::table('membresias.membresia_cuotas')->where('venta_id', $venta->id)->update(['estado' => 'ANULADA', 'updated_at' => now()]);
            DB::table('membresias.membresia_periodos')->where('venta_id', $venta->id)->update(['estado' => 'CANCELADA', 'updated_at' => now()]);
            app(CarteraServicio::class)->sincronizarVenta((int) $venta->id);
            $cuentaId = DB::table('cuentas_cobrar.cuentas')->where('venta_id', $venta->id)->value('id');
            if ($cuentaId) {
                DB::table('cuentas_cobrar.compromisos_pago')->where('cuenta_id', $cuentaId)->where('estado', 'PENDIENTE')
                    ->update(['estado' => 'CANCELADO', 'updated_at' => now()]);
            }
            app(AuditoriaServicio::class)->registrar([
                'modulo' => 'ventas', 'tabla' => 'ventas.ventas', 'accion' => 'ANULAR', 'registro_id' => $venta->id,
                'datos_antes' => $venta, 'datos_despues' => DB::table('ventas.ventas')->where('id', $venta->id)->first(), 'descripcion' => $motivo,
            ]);
            $this->movimiento((int) $m->id, 'CORRECCION_DATOS', "Se anula la venta {$venta->numero} de $" . number_format((float) $venta->total, 2) . '. ' . $motivo,
                ['venta' => $venta->numero, 'total' => (float) $venta->total]);
        }
    }

    /**
     * El pago registrado corresponde al período del 04/10 al 08/11; el período del 09/11 al 08/12 se cobra aparte.
     */
    private function ordenarPeriodosNino(string $codigo, bool $esCarlitos): void
    {
        $m = DB::table('membresias.membresias')->where('codigo_contrato', $codigo)->first();
        if (! $m || (int) $m->cantidad_periodos_contratados !== 1) {
            return;
        }
        $periodo = DB::table('membresias.membresia_periodos')->where('membresia_id', $m->id)->where('numero_periodo', 1)->first();
        if (! $periodo || substr((string) $periodo->fecha_inicio, 0, 10) !== '2026-11-09') {
            return;
        }
        $venta = DB::table('ventas.ventas')->where('id', $periodo->venta_id)->first();
        $pagosVenta = $venta ? DB::table('ventas.pagos')->where('venta_id', $venta->id)->where('estado', 'CONFIRMADO')->exists() : false;
        if (! $venta || ($esCarlitos ? $pagosVenta : $venta->estado !== 'PAGADA')) {
            return;
        }

        // 1. Descuento de $10 desde el 04/10 (el de $70 fue un error de digitación y nunca se cobró).
        $descuentos = DB::table('membresias.descuentos_deportistas')->where('deportista_id', $m->deportista_id)->where('activo', true)->orderBy('id')->get();
        foreach ($descuentos as $d) {
            if (abs((float) $d->valor - 70) < 0.009 && $d->tipo === 'FIJO') {
                DB::table('membresias.descuentos_deportistas')->where('id', $d->id)->update(['activo' => false, 'updated_at' => now()]);
                $this->movimiento((int) $m->id, 'DESCUENTO_EDITADO',
                    'Se inactiva el descuento de $70.00: fue un error de digitación el 14/11 (el correcto es $10.00) y nunca se cobró.',
                    ['descuento_id' => (int) $d->id, 'anterior' => ['valor' => 70.0, 'activo' => true], 'nuevo' => ['activo' => false], 'correccion' => true]);
            } elseif (abs((float) $d->valor - 10) < 0.009 && $d->tipo === 'FIJO' && substr((string) $d->fecha_inicio, 0, 10) > '2026-10-04') {
                DB::table('membresias.descuentos_deportistas')->where('id', $d->id)->update(['fecha_inicio' => '2026-10-04', 'updated_at' => now()]);
                $this->movimiento((int) $m->id, 'DESCUENTO_EDITADO',
                    'El descuento de $10.00 rige desde el 04/10, inicio real del contrato: el período del 04/10 al 08/11 se pagó con ese descuento ($70).',
                    ['descuento_id' => (int) $d->id, 'anterior' => ['fecha_inicio' => substr((string) $d->fecha_inicio, 0, 10)],
                        'nuevo' => ['fecha_inicio' => '2026-10-04'], 'correccion' => true]);
            }
        }

        // 2. El período registrado pasa a ser el del 04/10 al 08/11.
        DB::table('membresias.membresia_periodos')->where('id', $periodo->id)
            ->update(['fecha_inicio' => '2026-10-04', 'fecha_fin' => '2026-11-08', 'updated_at' => now()]);
        DB::table('membresias.membresias')->where('id', $m->id)
            ->update(['fecha_inicio' => '2026-10-04', 'fecha_fin' => '2026-11-08', 'updated_at' => now()]);
        $nota = $esCarlitos
            ? ' Corresponde al período del 04/10 al 08/11, pagado con la transferencia del 13/11 (comprobante 77961867, $200 por la familia); falta registrar ese cobro en caja.'
            : ' Corresponde al período del 04/10 al 08/11, pagado con la transferencia del 13/11 (comprobante 77961867, $200 por la familia).';
        DB::table('ventas.ventas')->where('id', $venta->id)->update(['observaciones' => trim(($venta->observaciones ?? '') . $nota), 'updated_at' => now()]);
        $this->movimiento((int) $m->id, 'CORRECCION_DATOS',
            'La transferencia del 13/11 pagó el período del 04/10 al 08/11 y se registró como si fuera del 09/11 al 08/12. '
            . 'El período 1 queda del 04/10 al 08/11 y se agrega el período 2, del 09/11 al 08/12, por cobrar.',
            ['fecha_inicio_anterior' => '2026-11-09', 'fecha_fin_anterior' => '2026-12-08',
                'fecha_inicio_nueva' => '2026-10-04', 'fecha_fin_nueva' => '2026-11-08', 'venta' => $venta->numero]);

        // 3. Venta del período 1 de Carlitos con su descuento real ($80 − $10 = $70).
        if ($esCarlitos) {
            app(ContratoMembresiaServicio::class)->recalcularVentasPendientes((int) $m->id, null,
                'Corrección: el descuento de $70 fue un error de digitación; el correcto es $10 desde el 04/10.');
        }

        // 4. Período 2 (09/11 – 08/12) con su cuota, como una renovación del mismo contrato.
        $precio = round((float) $periodo->precio, 2);
        $periodoId = DB::table('membresias.membresia_periodos')->insertGetId([
            'membresia_id' => $m->id, 'numero_periodo' => 2, 'fecha_inicio' => '2026-11-09', 'fecha_fin' => '2026-12-08',
            'precio' => $precio, 'estado' => 'PROGRAMADO', 'renovacion_manual' => true, 'venta_id' => null,
            'generado_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cuotaId = DB::table('membresias.membresia_cuotas')->insertGetId([
            'membresia_id' => $m->id, 'periodo_id' => $periodoId, 'numero_cuota' => 2, 'monto' => $precio,
            'fecha_programada_emision' => '2026-11-09', 'fecha_vencimiento' => '2026-11-09', 'estado' => 'PROGRAMADA',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('membresias.membresias')->where('id', $m->id)
            ->update(['cantidad_periodos_contratados' => 2, 'fecha_fin' => '2026-12-08', 'updated_at' => now()]);
        app(DescuentoDeportistaServicio::class)->continuarEnPeriodo($periodoId, null);
        app(HistorialComercialMembresiaServicio::class)->registrarContrato((int) $m->id, 'RENOVACION_MANUAL', [
            'periodo_id' => $periodoId, 'cuota_id' => $cuotaId, 'numero_periodo_nuevo' => 2,
            'fecha_inicio' => '2026-11-09', 'fecha_fin' => '2026-12-08', 'precio' => $precio,
            'fecha_fin_anterior' => '2026-11-08', 'fecha_fin_nueva' => '2026-12-08', 'origen' => 'CORRECCION_DATOS',
        ], 'Período del 09/11 al 08/12: el pago registrado correspondía al período anterior (04/10 – 08/11).');
    }
};
