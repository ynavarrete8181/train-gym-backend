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
        $ultimoPago = $pagos->where('estado', 'CONFIRMADO')->last();

        // Encabezado de marca
        $this->rect($ops, 0, 754, 595, 88, $negro[0], $negro[1], $negro[2]);
        $this->text($ops, 36, 806, 'REVIVE', 23, true, $dorado);
        $this->text($ops, 120, 806, 'SPORTS', 23, true, [1, 1, 1]);
        $this->text($ops, 36, 782, strtoupper((string) ($venta->sede_nombre ?? 'REVIVE')), 8.5, true, [0.86, 0.86, 0.86]);

        // Estado y número
        $this->text($ops, 36, 720, $titulo, 15, true);
        $this->text($ops, 36, 701, 'N.º ' . $numero, 8.5, false, $gris);
        $this->statusPill($ops, 430, 705, $pagada ? 'PAGADO' : 'PENDIENTE', $pagada);
        $this->line($ops, 36, 684, 559, 684, $borde);

        // Información principal
        $this->sectionTitle($ops, 36, 660, 'DATOS DE LA OPERACIÓN', $dorado);
        $this->rect($ops, 36, 584, 523, 60, $suave[0], $suave[1], $suave[2]);
        $this->labelValue($ops, 50, 628, 'Cliente', $cliente, 230);
        $this->labelValue($ops, 310, 628, 'Fecha', $fecha, 210);
        $this->labelValue($ops, 50, 598, 'Código', $venta->codigo_deportista ?? '-', 230);
        $this->labelValue($ops, 310, 598, 'Caja', $venta->caja_nombre ?? 'Pendiente de caja', 210);

        // Detalle
        $this->sectionTitle($ops, 36, 554, 'DETALLE', $dorado);
        $this->rect($ops, 36, 516, 523, 25, 0.95, 0.955, 0.96);
        $this->text($ops, 48, 525, 'Descripción', 8, true, $gris);
        $this->text($ops, 382, 525, 'Cant.', 8, true, $gris);
        $this->text($ops, 438, 525, 'Unit.', 8, true, $gris);
        $this->text($ops, 511, 525, 'Total', 8, true, $gris);

        $y = 496;
        foreach (($venta->detalles ?? []) as $detalle) {
            if ($y < 290) break;

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

            $y -= 7;
            $this->line($ops, 48, $y + 2, 548, $y + 2, [0.94, 0.945, 0.95]);
        }

        // Totales
        $y -= 6;
        $this->rect($ops, 342, $y - 78, 217, 92, 0.985, 0.985, 0.985);
        $this->summaryLine($ops, 356, $y, 'Subtotal', $this->dinero($venta->subtotal ?? 0), false);
        $y -= 18;
        $this->summaryLine($ops, 356, $y, 'Descuento', $this->dinero($venta->descuento ?? 0), false);
        $y -= 18;
        $this->summaryLine($ops, 356, $y, 'Impuesto', $this->dinero($venta->impuesto ?? 0), false);
        $y -= 9;
        $this->line($ops, 356, $y, 545, $y, $dorado);
        $y -= 21;
        $this->summaryLine($ops, 356, $y, 'TOTAL', $this->dinero($venta->total ?? 0), true);

        // Pago y saldo
        $pagoY = 210;
        $this->sectionTitle($ops, 36, $pagoY + 46, 'RESUMEN DE PAGO', $dorado);
        $this->rect($ops, 36, $pagoY - 4, 523, 35, $suave[0], $suave[1], $suave[2]);
        $this->labelValue($ops, 50, $pagoY + 20, 'Método', $ultimoPago?->metodo_pago ?? 'Pendiente', 145);
        $this->labelValue($ops, 210, $pagoY + 20, 'Pagado', $this->dinero($pagado), 120);
        $this->labelValue($ops, 350, $pagoY + 20, 'Saldo pendiente', $this->dinero($saldo), 180);

        // Membresía/pase
        if (! empty($venta->membresia_codigo)) {
            $this->sectionTitle($ops, 36, 166, 'MEMBRESÍA / PASE', $dorado);
            $this->labelValue($ops, 36, 142, 'Contrato', $venta->membresia_codigo, 220);
            $this->labelValue($ops, 280, 142, 'Plan', $venta->membresia_plan_nombre ?? '-', 260);
        }

        // Pie institucional
        $this->line($ops, 36, 74, 559, 74, $borde);
        $this->text($ops, 36, 54, 'Gracias por entrenar con Revive.', 9, true, $gris);
        $this->text($ops, 385, 54, 'Documento generado por Revive', 7, false, [0.52, 0.54, 0.57]);

        return $this->pdf(implode("\n", $ops));
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
        $this->text($ops, $x, $y - 13, mb_strimwidth($value, 0, max(12, (int) ($width / 6.2)), '…', 'UTF-8'), 9, true);
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
        return explode("\n", wordwrap($text, $max, "\n", true));
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

    private function pdf(string $stream): string
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objects[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objects[6] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";

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
