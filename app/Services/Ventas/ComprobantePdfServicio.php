<?php

namespace App\Services\Ventas;

class ComprobantePdfServicio
{
    public function generar(object $venta): string
    {
        $ops = [];
        $this->rect($ops, 0, 782, 595, 60, 0.08, 0.08, 0.08);
        $this->text($ops, 36, 812, 'REVIVE SPORTS', 18, true, [0.83, 0.63, 0.09]);
        $this->text($ops, 36, 792, strtoupper((string) ($venta->sede_nombre ?? 'REVIVE')), 9, false, [1, 1, 1]);

        $estado = strtoupper((string) ($venta->estado_nombre ?? $venta->estado ?? 'PENDIENTE'));
        $titulo = str_contains($estado, 'PAGAD') ? 'COMPROBANTE DE VENTA' : 'CUENTA PENDIENTE';
        $numero = $venta->comprobante?->numero ?? $venta->numero ?? ('VENTA-' . $venta->id);

        $this->text($ops, 36, 754, $titulo, 15, true);
        $this->text($ops, 36, 736, 'N.º ' . $numero, 9, false, [0.35, 0.38, 0.43]);
        $this->line($ops, 36, 722, 559, 722, [0.88, 0.89, 0.91]);

        $cliente = $venta->cliente_nombre ?? 'Consumidor final';
        $fecha = $venta->fecha_venta ? date('d/m/Y H:i', strtotime((string) $venta->fecha_venta)) : '-';
        $this->labelValue($ops, 36, 696, 'Cliente', $cliente, 260);
        $this->labelValue($ops, 320, 696, 'Fecha', $fecha, 220);
        $this->labelValue($ops, 36, 660, 'Código', $venta->codigo_deportista ?? '-', 260);
        $this->labelValue($ops, 320, 660, 'Caja', $venta->caja_nombre ?? 'Pendiente de caja', 220);

        $this->text($ops, 36, 620, 'DETALLE', 11, true);
        $this->line($ops, 36, 610, 559, 610, [0.88, 0.89, 0.91]);
        $this->text($ops, 36, 592, 'Descripción', 8, true, [0.35, 0.38, 0.43]);
        $this->text($ops, 382, 592, 'Cant.', 8, true, [0.35, 0.38, 0.43]);
        $this->text($ops, 438, 592, 'Unit.', 8, true, [0.35, 0.38, 0.43]);
        $this->text($ops, 513, 592, 'Total', 8, true, [0.35, 0.38, 0.43]);

        $y = 570;
        foreach (($venta->detalles ?? []) as $detalle) {
            if ($y < 210) {
                break;
            }
            $descripcion = (string) ($detalle->descripcion ?? 'Ítem');
            $lineas = $this->wrap($descripcion, 48);
            foreach ($lineas as $indice => $linea) {
                $this->text($ops, 36, $y, $linea, 9, $indice === 0);
                if ($indice === 0) {
                    $this->text($ops, 388, $y, $this->numero($detalle->cantidad ?? 0), 9);
                    $this->text($ops, 438, $y, $this->dinero($detalle->precio_unitario ?? 0), 9);
                    $this->text($ops, 505, $y, $this->dinero($detalle->total_linea ?? 0), 9, true);
                }
                $y -= 13;
            }
            $y -= 8;
            $this->line($ops, 36, $y + 3, 559, $y + 3, [0.94, 0.94, 0.95]);
        }

        $y -= 4;
        $this->text($ops, 390, $y, 'Subtotal', 9, false, [0.35, 0.38, 0.43]);
        $this->text($ops, 505, $y, $this->dinero($venta->subtotal ?? 0), 9, true);
        $y -= 18;
        $this->text($ops, 390, $y, 'Descuento', 9, false, [0.35, 0.38, 0.43]);
        $this->text($ops, 505, $y, $this->dinero($venta->descuento ?? 0), 9, true);
        $y -= 18;
        $this->text($ops, 390, $y, 'Impuesto', 9, false, [0.35, 0.38, 0.43]);
        $this->text($ops, 505, $y, $this->dinero($venta->impuesto ?? 0), 9, true);
        $y -= 12;
        $this->line($ops, 390, $y, 559, $y, [0.83, 0.63, 0.09]);
        $y -= 24;
        $this->text($ops, 390, $y, 'TOTAL', 12, true);
        $this->text($ops, 500, $y, $this->dinero($venta->total ?? 0), 14, true, [0.69, 0.48, 0]);

        $pagado = collect($venta->pagos ?? [])->where('estado', 'CONFIRMADO')->sum(fn ($p) => (float) $p->monto);
        $saldo = max(0, (float) ($venta->total ?? 0) - $pagado);
        $y -= 42;
        $this->text($ops, 36, $y, 'PAGO', 11, true);
        $this->line($ops, 36, $y - 10, 559, $y - 10, [0.88, 0.89, 0.91]);
        $y -= 30;

        $ultimoPago = collect($venta->pagos ?? [])->where('estado', 'CONFIRMADO')->last();
        $this->labelValue($ops, 36, $y, 'Método', $ultimoPago?->metodo_pago ?? 'Pendiente', 220);
        $this->labelValue($ops, 280, $y, 'Estado', $estado, 260);
        $y -= 36;
        $this->labelValue($ops, 36, $y, 'Pagado', $this->dinero($pagado), 220);
        $this->labelValue($ops, 280, $y, 'Saldo', $this->dinero($saldo), 260);

        if (!empty($venta->membresia_codigo)) {
            $y -= 46;
            $this->text($ops, 36, $y, 'MEMBRESÍA / PASE', 11, true);
            $this->line($ops, 36, $y - 10, 559, $y - 10, [0.88, 0.89, 0.91]);
            $y -= 30;
            $this->labelValue($ops, 36, $y, 'Contrato', $venta->membresia_codigo, 220);
            $this->labelValue($ops, 280, $y, 'Plan', $venta->membresia_plan_nombre ?? '-', 260);
        }

        $this->line($ops, 36, 72, 559, 72, [0.88, 0.89, 0.91]);
        $this->text($ops, 36, 52, 'Gracias por entrenar con Revive.', 9, true, [0.35, 0.38, 0.43]);
        $this->text($ops, 410, 52, 'Documento generado por Revive', 7, false, [0.5, 0.52, 0.56]);

        return $this->pdf(implode("\n", $ops));
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
