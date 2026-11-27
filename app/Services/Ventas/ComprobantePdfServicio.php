<?php

namespace App\Services\Ventas;

class ComprobantePdfServicio
{
    public function generar(object $venta): string
    {
        $ops = [];

        $negro = [0.055, 0.055, 0.055];
        $dorado = [0.83, 0.63, 0.09];
        $gris = [0.40, 0.43, 0.47];
        $borde = [0.88, 0.89, 0.91];
        $suave = [0.975, 0.978, 0.982];

        $estado = strtoupper((string) ($venta->estado_nombre ?? $venta->estado ?? 'PENDIENTE'));
        $pagada = str_contains($estado, 'PAGAD');
        $titulo = $pagada ? 'COMPROBANTE DE VENTA' : 'CUENTA PENDIENTE';
        $numero = $venta->comprobante?->numero ?? $venta->numero ?? ('VENTA-' . $venta->id);
        $cliente = $venta->cliente_nombre ?? 'Consumidor final';
        $fecha = $venta->fecha_venta ? date('d/m/Y H:i', strtotime((string) $venta->fecha_venta)) : '-';

        $pagos = collect($venta->pagos ?? []);
        $pagado = (float) $pagos->where('estado', 'CONFIRMADO')->sum(fn ($p) => (float) $p->monto);
        $saldo = max(0, (float) ($venta->total ?? 0) - $pagado);
        $pagosConfirmados = $pagos->where('estado', 'CONFIRMADO')->values();
        $metodosResumen = $pagosConfirmados
            ->groupBy('metodo_pago')
            ->map(function ($grupo, $metodo) {
                $refs = $grupo->map(fn ($p) => trim(($p->referencia ?? '') . (! empty($p->banco) ? ' · ' . $p->banco : ''), ' ·'))
                    ->filter()->unique()->implode(', ');
                return strtoupper((string) $metodo) . ' ' . $this->dinero($grupo->sum(fn ($p) => (float) $p->monto)) . ($refs ? " (N.º {$refs})" : '');
            })
            ->implode(' · ');

        // Encabezado de marca
        // Fondo negro puro para que se funda con el fondo del logo oficial.
        $this->rect($ops, 0, 754, 595, 88, 0, 0, 0);
        $logo = $this->logo();
        if ($logo) {
            $alto = 50;
            $ancho = round($alto * $logo['ancho'] / $logo['alto'], 1);
            $ops[] = sprintf('q %.1F 0 0 %.1F %.1F %.1F cm /Logo Do Q', $ancho, $alto, 30, 780);
        } else {
            $this->text($ops, 36, 806, 'REVIVE', 23, true, $dorado);
            $this->text($ops, 120, 806, 'SPORTS', 23, true, [1, 1, 1]);
        }
        $this->text($ops, 36, 766, strtoupper((string) ($venta->sede_nombre ?? 'REVIVE')), 8, true, [0.80, 0.80, 0.80]);

        // Estado y número
        $this->text($ops, 36, 720, $titulo, 15, true);
        // El comprobante lleva su propio número; se muestra junto al número de venta
        // con el que la operación aparece en Ventas, Cobros e Inventario.
        $numeroVenta = $venta->numero ?? null;
        $this->text($ops, 36, 701, 'N.º ' . $numero, 8.5, true, $gris);
        if ($numeroVenta && $numeroVenta !== $numero) {
            $this->text($ops, 36, 689, 'Venta N.º ' . $numeroVenta, 8.5, false, $gris);
        }
        $this->statusPill($ops, 430, 705, $pagada ? 'PAGADO' : 'PENDIENTE', $pagada);
        $this->line($ops, 36, 679, 559, 679, $borde);

        // Información principal
        $this->sectionTitle($ops, 36, 660, 'DATOS DE LA OPERACIÓN', $dorado);
        $this->rect($ops, 36, 573, 523, 72, $suave[0], $suave[1], $suave[2]);
        $this->labelValue($ops, 50, 628, 'Cliente', $cliente, 230);
        $this->labelValue($ops, 310, 628, 'Fecha', $fecha, 210);
        $this->labelValue($ops, 50, 598, 'Identificación', $venta->cliente_identificacion ?? '-', 230);
        $this->labelValue($ops, 310, 598, 'Caja', $venta->caja_nombre ?? 'Pendiente de caja', 210);

        // Detalle
        $this->sectionTitle($ops, 36, 554, 'DETALLE', $dorado);
        $this->rect($ops, 36, 516, 523, 25, 0.95, 0.955, 0.96);
        $this->text($ops, 48, 525, 'Descripción', 8, true, $gris);
        $this->text($ops, 382, 525, 'Cant.', 8, true, $gris);
        $this->text($ops, 438, 525, 'Unit.', 8, true, $gris);
        $this->text($ops, 511, 525, 'Total', 8, true, $gris);

        $y = 496;
        $periodo = $venta->membresia_periodo ?? null;
        $esPase = ($venta->membresia_tipo_producto ?? null) === 'PASE_DIARIO';
        $fechaCorta = fn ($f) => $f ? date('d/m/Y', strtotime((string) $f)) : '-';

        foreach (($venta->detalles ?? []) as $detalle) {
            if ($y < 330) break;

            $descripcion = (string) ($detalle->descripcion ?? 'Ítem');
            $lineas = $this->wrap($descripcion, 46);

            foreach ($lineas as $indice => $linea) {
                $this->text($ops, 48, $y, $linea, 9, $indice === 0);

                if ($indice === 0) {
                    $this->text($ops, 386, $y, $this->numero($detalle->cantidad ?? 0), 9);
                    $this->text($ops, 438, $y, $this->dinero($detalle->precio_unitario ?? 0), 9);
                    $this->text($ops, 505, $y, $this->dinero($detalle->total_linea ?? 0), 9, true);
                }

                $y -= 13;
            }

            // Explica de dónde sale el valor de cada línea.
            $nota = match (true) {
                ($detalle->tipo_item ?? null) === 'MEMBRESIA' && $esPase => sprintf('Tarifa del pase en %s · uso del %s',
                    $venta->sede_nombre ?? 'la sede', $fechaCorta($periodo->fecha_inicio ?? $venta->membresia_fecha_inicio ?? null)),
                ($detalle->tipo_item ?? null) === 'MEMBRESIA' => sprintf('%sPeríodo %s: del %s al %s',
                    ! empty($venta->membresia_modalidad_nombre) ? $venta->membresia_modalidad_nombre . ' · ' : '',
                    $periodo->numero_periodo ?? '-', $fechaCorta($periodo->fecha_inicio ?? null), $fechaCorta($periodo->fecha_fin ?? null)),
                ! empty($detalle->producto_id) => 'Producto · precio de venta en ' . ($venta->sede_nombre ?? 'la sede'),
                default => null,
            };
            // Congelación de tiempo dentro del período: explica por qué el mes termina después.
            if ($nota && ($detalle->tipo_item ?? null) === 'MEMBRESIA' && ! $esPase && $periodo && ! empty($venta->membresia_id)) {
                $congelacion = \Illuminate\Support\Facades\DB::table('membresias.membresia_suspensiones')
                    ->where('membresia_id', $venta->membresia_id)->where('tipo', 'TIEMPO')
                    ->whereIn('estado', ['VIGENTE', 'FINALIZADA'])
                    ->where('fecha_inicio', '<=', $periodo->fecha_fin)
                    ->whereRaw('COALESCE(fecha_fin_real, fecha_fin_prevista) >= ?', [$periodo->fecha_inicio])
                    ->orderByDesc('id')->first();
                if ($congelacion) {
                    $finCongelacion = $congelacion->fecha_fin_real ?? $congelacion->fecha_fin_prevista;
                    $diasCongelados = (int) round((strtotime((string) $finCongelacion) - strtotime((string) $congelacion->fecha_inicio)) / 86400) + 1;
                    $nota .= sprintf(' · Incluye %d días de congelación (%s al %s)', $diasCongelados,
                        $fechaCorta($congelacion->fecha_inicio), $fechaCorta($finCongelacion));
                }
            }
            if ($nota) {
                foreach ($this->wrap($nota, 105) as $linea) {
                    $this->text($ops, 48, $y + 1, $linea, 7.5, false, $gris);
                    $y -= 10;
                }
                $y -= 1;
            }

            // Separador con aire arriba y abajo, sin tocar la línea siguiente.
            $y -= 4;
            $this->line($ops, 48, $y, 548, $y, [0.94, 0.945, 0.95]);
            $y -= 14;
        }

        // El descuento va como una línea propia, con su origen y motivo.
        $descuento = (float) ($venta->descuento ?? 0);
        if ($descuento > 0) {
            $info = $venta->descuento_detalle ?? null;
            $titulo = 'Descuento aplicado';
            if ($info) {
                $titulo .= $info->tipo === 'PORCENTAJE'
                    ? ' · ' . $this->numero($info->valor) . '% sobre ' . ($info->plan_nombre ?? 'la membresía')
                    : ' · valor fijo sobre ' . ($info->plan_nombre ?? 'la membresía');
            }
            $this->text($ops, 500, $y, '-' . $this->dinero($descuento), 9, true, [0.04, 0.45, 0.29]);
            foreach ($this->wrap($titulo, 72) as $linea) {
                $this->text($ops, 48, $y, $linea, 9, true, [0.04, 0.45, 0.29]);
                $y -= 12;
            }
            $porque = $info
                ? 'Beneficio autorizado para el cliente'
                    . ($info->fecha_fin ? ', vigente hasta el ' . $fechaCorta($info->fecha_fin) : '')
                    . (trim((string) $info->motivo) !== '' ? '. Motivo: ' . trim((string) $info->motivo) : '')
                : 'Descuento registrado en la venta.';
            foreach ($this->wrap($porque, 105) as $linea) {
                $this->text($ops, 48, $y, $linea, 7.5, false, $gris);
                $y -= 10;
            }
            $y -= 2;
            $this->line($ops, 48, $y, 548, $y, [0.94, 0.945, 0.95]);
        }

        // Totales
        $y -= 22;
        $this->rect($ops, 342, $y - 78, 217, 92, 0.985, 0.985, 0.985);
        $this->summaryLine($ops, 356, $y, 'Subtotal', $this->dinero($venta->subtotal ?? 0), false);
        $y -= 18;
        $this->summaryLine($ops, 356, $y, 'Descuento', ($descuento > 0 ? '-' : '') . $this->dinero($descuento), false);
        $y -= 18;
        $this->summaryLine($ops, 356, $y, 'Impuesto', $this->dinero($venta->impuesto ?? 0), false);
        $y -= 9;
        $this->line($ops, 356, $y, 545, $y, $dorado);
        $y -= 21;
        $this->summaryLine($ops, 356, $y, 'TOTAL', $this->dinero($venta->total ?? 0), true);

        // Pago y saldo: cómo se pagó, quién cobró y el cambio entregado.
        $y -= 48;
        $this->sectionTitle($ops, 36, $y, 'RESUMEN DE PAGO', $dorado);
        $y -= 16;
        $ultimoPago = $pagosConfirmados->last();
        // Si lo pagó un tutor (en un cobro de varias cuentas), se identifica quién pagó.
        $pagoTutor = $pagosConfirmados->first(fn ($p) => ! empty($p->pagador_nombre));
        $extraTutor = $pagoTutor ? 30 : 0;
        $this->rect($ops, 36, $y - 62 - $extraTutor, 523, 66 + $extraTutor, $suave[0], $suave[1], $suave[2]);
        $recibido = (float) $pagosConfirmados->where('metodo_pago', 'EFECTIVO')->sum(fn ($p) => (float) ($p->efectivo_recibido ?? $p->monto));
        $cambio = (float) $pagosConfirmados->where('metodo_pago', 'EFECTIVO')->sum(fn ($p) => (float) ($p->cambio_entregado ?? 0));
        $this->labelValue($ops, 50, $y - 8, 'Métodos', $metodosResumen ?: 'Pendiente', 260);
        $this->labelValue($ops, 330, $y - 8, 'Pagado', $this->dinero($pagado), 100);
        $this->labelValue($ops, 445, $y - 8, 'Saldo', $this->dinero($saldo), 100);
        $this->labelValue($ops, 50, $y - 38, 'Cobro', $ultimoPago
            ? ($ultimoPago->codigo_cobro ?? '-') . ' · ' . ($ultimoPago->cobrado_por_nombre ?? '') : '-', 260);
        if ($recibido > 0) {
            $this->labelValue($ops, 330, $y - 38, 'Efectivo recibido', $this->dinero($recibido), 100);
            $this->labelValue($ops, 445, $y - 38, 'Cambio entregado', $this->dinero($cambio), 100);
        }
        if ($pagoTutor) {
            $this->labelValue($ops, 50, $y - 68, 'Pagado por (tutor)', $pagoTutor->pagador_nombre
                . (! empty($pagoTutor->pagador_identificacion) ? ' · C.I. ' . $pagoTutor->pagador_identificacion : ''), 260);
            $this->labelValue($ops, 330, $y - 68, 'Comprobante de cobro (todas sus cuentas)', 'N.º ' . ($pagoTutor->codigo_cobro ?? '-'), 215);
        }
        $y -= 82 + $extraTutor;

        // Membresía/pase
        if (! empty($venta->membresia_codigo) && $y > 150) {
            $this->sectionTitle($ops, 36, $y, $esPase ? 'PASE' : 'MEMBRESÍA', $dorado);
            $y -= 24;
            $this->labelValue($ops, 36, $y, 'Contrato', $venta->membresia_codigo, 160);
            $this->labelValue($ops, 215, $y, 'Plan', $venta->membresia_plan_nombre ?? '-', 170);
            $this->labelValue($ops, 404, $y, $esPase ? 'Sede de uso' : 'Modalidad', $esPase ? ($venta->sede_nombre ?? '-') : ($venta->membresia_modalidad_nombre ?? '-'), 145);
            $y -= 31;

            $desde = $periodo->fecha_inicio ?? $venta->membresia_fecha_inicio ?? null;
            $hasta = $periodo->fecha_fin ?? $venta->membresia_fecha_fin ?? null;
            $precioAplicado = $periodo
                ? max(0, (float) $periodo->precio - (float) ($periodo->descuento_importe ?? 0))
                : (float) ($venta->membresia_precio_aplicado ?? $venta->total ?? 0);
            $detallePrecio = $periodo && (float) ($periodo->descuento_importe ?? 0) > 0
                ? $this->dinero($precioAplicado) . ' (' . $this->dinero($periodo->precio) . ' - ' . $this->dinero($periodo->descuento_importe) . ' desc.)'
                : $this->dinero($precioAplicado);
            $this->labelValue($ops, 36, $y, $esPase ? 'Válido para' : 'Período cobrado', $fechaCorta($desde) . ($desde !== $hasta ? ' al ' . $fechaCorta($hasta) : ''), 250);
            $this->labelValue($ops, 310, $y, 'Valor del ' . ($esPase ? 'pase' : 'período'), $detallePrecio, 240);
        }

        // Pie institucional
        $this->line($ops, 36, 74, 559, 74, $borde);
        $this->text($ops, 36, 54, 'Gracias por entrenar con Revive.', 9, true, $gris);
        $this->text($ops, 385, 54, 'Documento generado por Revive', 7, false, [0.52, 0.54, 0.57]);

        return $this->pdf(implode("\n", $ops), $logo);
    }

    /**
     * Recibo de un cobro que pagó varias cuentas a la vez (por ejemplo, un tutor
     * que paga su membresía y la de sus representados): una línea por cuenta.
     */
    public function generarCobro(\Illuminate\Support\Collection $pagos, \Illuminate\Support\Collection $ventas): string
    {
        $ops = [];
        $dorado = [0.83, 0.63, 0.09];
        $gris = [0.40, 0.43, 0.47];
        $borde = [0.88, 0.89, 0.91];
        $suave = [0.975, 0.978, 0.982];
        $fechaCorta = fn ($f) => $f ? date('d/m/Y', strtotime((string) $f)) : '-';

        $primero = $pagos->first();
        $pagador = $primero->pagador_nombre ?: ($ventas->first()->cliente_nombre ?? 'Cliente');
        $identificacion = $primero->pagador_nombre ? ($primero->pagador_identificacion ?: '-') : ($ventas->first()->cliente_identificacion ?? '-');

        $this->rect($ops, 0, 754, 595, 88, 0, 0, 0);
        $logo = $this->logo();
        if ($logo) {
            $alto = 50;
            $ancho = round($alto * $logo['ancho'] / $logo['alto'], 1);
            $ops[] = sprintf('q %.1F 0 0 %.1F %.1F %.1F cm /Logo Do Q', $ancho, $alto, 30, 780);
        } else {
            $this->text($ops, 36, 806, 'REVIVE', 23, true, $dorado);
            $this->text($ops, 120, 806, 'SPORTS', 23, true, [1, 1, 1]);
        }
        $this->text($ops, 36, 766, strtoupper((string) ($ventas->first()->sede_nombre ?? 'REVIVE')), 8, true, [0.80, 0.80, 0.80]);

        $saldoTotal = (float) $ventas->sum(fn ($v) => max(0, (float) $v->total - (float) collect($v->pagos ?? [])->where('estado', 'CONFIRMADO')->sum(fn ($p) => (float) $p->monto)));
        $this->text($ops, 36, 720, 'COMPROBANTE DE COBRO', 15, true);
        $this->text($ops, 36, 701, 'N.º ' . $primero->codigo_cobro, 8.5, true, $gris);
        $this->statusPill($ops, 430, 705, $saldoTotal > 0 ? 'PARCIAL' : 'PAGADO', $saldoTotal <= 0);
        $this->line($ops, 36, 679, 559, 679, $borde);

        $this->sectionTitle($ops, 36, 660, 'DATOS DEL COBRO', $dorado);
        $this->rect($ops, 36, 573, 523, 72, $suave[0], $suave[1], $suave[2]);
        $this->labelValue($ops, 50, 628, $primero->pagador_nombre ? 'Pagado por (tutor)' : 'Cliente', $pagador, 230);
        $this->labelValue($ops, 310, 628, 'Fecha', date('d/m/Y H:i', strtotime((string) $primero->fecha_pago)), 210);
        $this->labelValue($ops, 50, 598, 'Identificación', $identificacion, 230);
        $this->labelValue($ops, 310, 598, 'Caja', $primero->caja_nombre ?? '-', 210);

        $this->sectionTitle($ops, 36, 554, 'CUENTAS PAGADAS', $dorado);
        $this->rect($ops, 36, 516, 523, 25, 0.95, 0.955, 0.96);
        $this->text($ops, 48, 525, 'Cliente y concepto', 8, true, $gris);
        $this->text($ops, 438, 525, 'Valor', 8, true, $gris);
        $this->text($ops, 505, 525, 'Pagado', 8, true, $gris);

        $y = 496;
        foreach ($ventas as $venta) {
            if ($y < 250) break;
            $pagadoAqui = (float) $pagos->where('venta_id', $venta->id)->sum(fn ($p) => (float) $p->monto);
            $this->text($ops, 438, $y, $this->dinero($venta->total ?? 0), 9);
            $this->text($ops, 505, $y, $this->dinero($pagadoAqui), 9, true);
            foreach ($this->wrap((string) ($venta->cliente_nombre ?? 'Cliente'), 60) as $linea) {
                $this->text($ops, 48, $y, $linea, 9, true);
                $y -= 12;
            }
            $periodo = $venta->membresia_periodo ?? null;
            $lineas = ! empty($venta->membresia_codigo)
                ? [
                    sprintf('Venta %s · %s · %s%s', $venta->numero ?? '', $venta->membresia_codigo, $venta->membresia_plan_nombre ?? 'Membresía',
                        ! empty($venta->membresia_modalidad_nombre) ? ' · ' . $venta->membresia_modalidad_nombre : ''),
                    sprintf('Período %s: del %s al %s', $periodo->numero_periodo ?? '-', $fechaCorta($periodo->fecha_inicio ?? null), $fechaCorta($periodo->fecha_fin ?? null)),
                ]
                : ['Venta ' . ($venta->numero ?? '') . ' · ' . ($venta->concepto ?? 'Venta')];
            foreach ($lineas as $texto) {
                foreach ($this->wrap($texto, 105) as $linea) {
                    $this->text($ops, 48, $y, $linea, 7.5, false, $gris);
                    $y -= 10;
                }
            }
            $y += 4;
            $this->line($ops, 48, $y, 548, $y, [0.94, 0.945, 0.95]);
            $y -= 14;
        }

        $total = (float) $pagos->sum(fn ($p) => (float) $p->monto);
        $y -= 10;
        $this->rect($ops, 342, $y - 30, 217, 44, 0.985, 0.985, 0.985);
        $this->line($ops, 356, $y + 4, 545, $y + 4, $dorado);
        $this->summaryLine($ops, 356, $y - 14, 'TOTAL PAGADO', $this->dinero($total), true);

        $y -= 66;
        $this->sectionTitle($ops, 36, $y, 'RESUMEN DE PAGO', $dorado);
        $y -= 16;
        $porMetodo = $pagos->groupBy('metodo_pago')
            ->map(function ($grupo, $metodo) {
                $refs = $grupo->map(fn ($p) => trim(($p->referencia ?? '') . (! empty($p->banco) ? ' · ' . $p->banco : ''), ' ·'))
                    ->filter()->unique()->implode(', ');
                return strtoupper((string) $metodo) . ' ' . $this->dinero($grupo->sum(fn ($p) => (float) $p->monto)) . ($refs ? " (N.º {$refs})" : '');
            });
        $recibido = (float) $pagos->where('metodo_pago', 'EFECTIVO')->sum(fn ($p) => (float) ($p->efectivo_recibido ?? $p->monto));
        $cambio = (float) $pagos->where('metodo_pago', 'EFECTIVO')->sum(fn ($p) => (float) ($p->cambio_entregado ?? 0));
        // Cada método en su propia línea, completo.
        $lineasMetodos = $porMetodo->values()->flatMap(fn ($texto) => $this->wrap($texto, 68))->all();
        $extra = (count($lineasMetodos) - 1) * 12;
        $this->rect($ops, 36, $y - 62 - $extra, 523, 66 + $extra, $suave[0], $suave[1], $suave[2]);
        $this->text($ops, 50, $y - 8, 'Métodos', 7, false, [0.45, 0.48, 0.52]);
        foreach ($lineasMetodos as $i => $linea) {
            $this->text($ops, 50, $y - 21 - $i * 12, $linea, 9, true);
        }
        $this->labelValue($ops, 445, $y - 8, 'Saldo', $this->dinero($saldoTotal), 100);
        $this->labelValue($ops, 50, $y - 38 - $extra, 'Cobrado por', (string) ($primero->cobrado_por_nombre ?? '-'), 260);
        if ($recibido > 0) {
            $this->labelValue($ops, 330, $y - 38 - $extra, 'Efectivo recibido', $this->dinero($recibido), 100);
            $this->labelValue($ops, 445, $y - 38 - $extra, 'Cambio entregado', $this->dinero($cambio), 100);
        }

        $this->line($ops, 36, 74, 559, 74, $borde);
        $this->text($ops, 36, 54, 'Gracias por entrenar con Revive.', 9, true, $gris);
        $this->text($ops, 385, 54, 'Documento generado por Revive', 7, false, [0.52, 0.54, 0.57]);

        return $this->pdf(implode("\n", $ops), $logo);
    }

    private function sectionTitle(array &$ops, float $x, float $y, string $titulo, array $dorado): void
    {
        $this->rect($ops, $x, $y - 3, 3, 14, $dorado[0], $dorado[1], $dorado[2]);
        $this->text($ops, $x + 10, $y, $titulo, 10, true);
    }

    private function statusPill(array &$ops, float $x, float $y, string $label, bool $pagada): void
    {
        if ($pagada) {
            $this->rect($ops, $x, $y - 9, 100, 22, 0.91, 0.97, 0.94);
            $this->text($ops, $x + 21, $y - 1, $label, 8, true, [0.04, 0.45, 0.29]);
            return;
        }

        $this->rect($ops, $x, $y - 9, 100, 22, 1.0, 0.96, 0.82);
        $this->text($ops, $x + 14, $y - 1, $label, 8, true, [0.55, 0.40, 0.0]);
    }

    private function summaryLine(array &$ops, float $x, float $y, string $label, string $valor, bool $total): void
    {
        $this->text($ops, $x, $y, $label, $total ? 11 : 8.5, $total, $total ? [0.07, 0.09, 0.13] : [0.40, 0.43, 0.47]);
        $this->text($ops, 500, $y, $valor, $total ? 13 : 9, true, $total ? [0.69, 0.48, 0] : [0.07, 0.09, 0.13]);
    }

    private function labelValue(array &$ops, float $x, float $y, string $label, string $value, int $width): void
    {
        $this->text($ops, $x, $y, $label, 7, false, [0.45, 0.48, 0.52]);
        // Si el dato no cabe, se achica la letra; si aún no cabe, pasa a dos líneas pequeñas.
        if (mb_strlen($value) <= (int) ($width / 5.0)) {
            $this->text($ops, $x, $y - 13, $value, 9, true);
            return;
        }
        $lineas = $this->wrap($value, max(14, (int) ($width / 4.1)));
        $this->text($ops, $x, $y - 13, $lineas[0], 7.5, true);
        if (isset($lineas[1])) {
            $this->text($ops, $x, $y - 21.5, implode(' ', array_slice($lineas, 1)), 7.5, true);
        }
    }

    private function text(array &$ops, float $x, float $y, string $text, float $size = 10, bool $bold = false, array $rgb = [0.07, 0.09, 0.13]): void
    {
        [$r, $g, $b] = $rgb;
        $font = $bold ? 'F2' : 'F1';
        $escaped = $this->escape($text);
        $ops[] = sprintf('%.3F %.3F %.3F rg BT /%s %.1F Tf %.1F %.1F Td (%s) Tj ET', $r, $g, $b, $font, $size, $x, $y, $escaped);
    }

    private function line(array &$ops, float $x1, float $y1, float $x2, float $y2, array $rgb): void
    {
        [$r, $g, $b] = $rgb;
        $ops[] = sprintf('%.3F %.3F %.3F RG 0.6 w %.1F %.1F m %.1F %.1F l S', $r, $g, $b, $x1, $y1, $x2, $y2);
    }

    private function rect(array &$ops, float $x, float $y, float $w, float $h, float $r, float $g, float $b): void
    {
        $ops[] = sprintf('%.3F %.3F %.3F rg %.1F %.1F %.1F %.1F re f', $r, $g, $b, $x, $y, $w, $h);
    }

    private function wrap(string $text, int $max): array
    {
        $lineas = [];
        $actual = '';
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $palabra) {
            $candidata = $actual === '' ? $palabra : $actual . ' ' . $palabra;
            if ($actual !== '' && mb_strlen($candidata) > $max) {
                $lineas[] = $actual;
                $actual = $palabra;
            } else {
                $actual = $candidata;
            }
        }
        if ($actual !== '' || ! $lineas) {
            $lineas[] = $actual;
        }

        return $lineas;
    }

    private function dinero(mixed $valor): string
    {
        return '$' . number_format((float) $valor, 2, '.', ',');
    }

    private function numero(mixed $valor): string
    {
        $numero = (float) $valor;
        return fmod($numero, 1.0) === 0.0 ? (string) (int) $numero : number_format($numero, 2, '.', '');
    }

    private function escape(string $text): string
    {
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        $encoded = $encoded === false ? preg_replace('/[^\x20-\x7E]/', '', $text) : $encoded;
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded);
    }

    /** Logo oficial horizontal (JPEG) para el encabezado. */
    private function logo(): ?array
    {
        $ruta = public_path('brand/revive-logo-horizontal.jpg');
        $info = is_file($ruta) ? @getimagesize($ruta) : false;
        if (! $info || ($info[2] ?? null) !== IMAGETYPE_JPEG) {
            return null;
        }

        return ['datos' => file_get_contents($ruta), 'ancho' => $info[0], 'alto' => $info[1]];
    }

    private function pdf(string $stream, ?array $logo = null): string
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $xobject = $logo ? ' /XObject << /Logo 7 0 R >>' : '';
        $objects[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >>' . $xobject . ' >> /Contents 6 0 R >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objects[6] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        if ($logo) {
            $objects[7] = sprintf('<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>', $logo['ancho'], $logo['alto'], strlen($logo['datos']))
                . "\nstream\n" . $logo['datos'] . "\nendstream";
        }

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id <= count($objects); $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }

        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xref . "\n%%EOF";

        return $pdf;
    }
}
