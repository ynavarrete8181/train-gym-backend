<?php

namespace App\Services\Reportes\Documentos;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReporteExcelDocumentoServicio
{
    public function __construct(private readonly ReporteMembreteServicio $membrete)
    {
    }

    public function descargar(
        string $nombreArchivo,
        string $titulo,
        string $descripcion,
        array $columnas,
        array $filas,
        array $metadata,
        int $usuarioId,
        array $filaTotal = [],
    ): StreamedResponse {
        $membrete = $this->membrete->construir($titulo, $descripcion, $metadata, $usuarioId);

        $spreadsheet = new Spreadsheet();
        $hoja = $spreadsheet->getActiveSheet();
        $hoja->setTitle(mb_substr($titulo, 0, 31));

        $ultimaColumna = $this->letraColumna(max(1, count($columnas)));

        $logo = $membrete['logo_path'];
        if (is_file($logo)) {
            $drawing = new Drawing();
            $drawing->setName('Revive');
            $drawing->setDescription('Revive');
            $drawing->setPath($logo);
            $drawing->setHeight(88);
            $drawing->setCoordinates('A1');
            $drawing->setOffsetX(2);
            $drawing->setOffsetY(1);
            $drawing->setWorksheet($hoja);
        }

        $hoja->mergeCells("B1:{$ultimaColumna}1");
        $hoja->setCellValue('B1', 'Centro de Entrenamiento Físico Revive');
        $hoja->mergeCells("B2:{$ultimaColumna}2");
        $hoja->setCellValue('B2', 'Reporte de ' . $membrete['titulo']);

        $periodo = $membrete['metadata']['Período'] ?? '';
        $sedes = $membrete['metadata']['Sedes'] ?? 'Todas las sedes';

        $detalle = collect([
            'Generado por: ' . $membrete['generado_por'],
            'Rol: ' . $membrete['rol'],
            $periodo ? 'Período: ' . $periodo : null,
            'Sedes: ' . $sedes,
        ])->filter()->implode('   ·   ');

        $hoja->mergeCells("B3:{$ultimaColumna}3");
        $hoja->setCellValue('B3', $detalle);

        $hoja->getColumnDimension('A')->setWidth(17);

        $hoja->getRowDimension(1)->setRowHeight(26);
        $hoja->getRowDimension(2)->setRowHeight(28);
        $hoja->getRowDimension(3)->setRowHeight(22);

        $hoja->getStyle("B1:{$ultimaColumna}1")
            ->getFont()->setBold(true)->setSize(12)->getColor()->setARGB('FF171717');
        $hoja->getStyle("B2:{$ultimaColumna}2")
            ->getFont()->setBold(true)->setSize(15)->getColor()->setARGB('FF5B4700');
        $hoja->getStyle("B3:{$ultimaColumna}3")
            ->getFont()->setSize(9)->getColor()->setARGB('FF5F6368');

        $hoja->getStyle("B1:{$ultimaColumna}3")
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);

        $hoja->getStyle("A4:{$ultimaColumna}4")
            ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF5C400');
        $hoja->getRowDimension(4)->setRowHeight(3);

        $filtros = $membrete['metadata']['Filtros aplicados'] ?? '';
        $filaEncabezado = 5;

        if ($filtros) {
            $hoja->mergeCells("A5:{$ultimaColumna}5");
            $hoja->setCellValue('A5', $filtros);
            $hoja->getStyle("A5:{$ultimaColumna}5")
                ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFDF5');
            $hoja->getStyle("A5:{$ultimaColumna}5")->getFont()->setSize(8)->getColor()->setARGB('FF555555');
            $hoja->getStyle("A5:{$ultimaColumna}5")->getAlignment()->setWrapText(true);
            $filaEncabezado = 6;
        }

        foreach (array_values($columnas) as $indice => $encabezado) {
            $hoja->setCellValue([$indice + 1, $filaEncabezado], $encabezado);
        }

        $hoja->getStyle("A{$filaEncabezado}:{$ultimaColumna}{$filaEncabezado}")
            ->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $hoja->getStyle("A{$filaEncabezado}:{$ultimaColumna}{$filaEncabezado}")
            ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF2F2F2F');
        $hoja->getStyle("A{$filaEncabezado}:{$ultimaColumna}{$filaEncabezado}")
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $hoja->getRowDimension($filaEncabezado)->setRowHeight(22);

        $fila = $filaEncabezado + 1;
        foreach ($filas as $indiceFila => $registro) {
            foreach (array_values($columnas) as $indice => $_) {
                $valor = $registro[$indice] ?? '';
                $hoja->setCellValue([$indice + 1, $fila], $valor);
            }

            if ($indiceFila % 2 === 1) {
                $hoja->getStyle("A{$fila}:{$ultimaColumna}{$fila}")
                    ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFAFAFA');
            }

            $fila++;
        }

        $ultimaFilaRegistros = $fila - 1;
        $ultimaFilaDocumento = $ultimaFilaRegistros;

        if ($filaTotal && $filas) {
            foreach (array_values($columnas) as $indice => $_) {
                $valor = $filaTotal[$indice] ?? '';
                if (is_callable($valor)) {
                    $valor = $valor($filas);
                }
                $hoja->setCellValue([$indice + 1, $fila], $valor);
            }

            $hoja->getStyle("A{$fila}:{$ultimaColumna}{$fila}")
                ->getFont()->setBold(true)->getColor()->setARGB('FF171717');
            $hoja->getStyle("A{$fila}:{$ultimaColumna}{$fila}")
                ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF2F2F2');
            $hoja->getStyle("A{$fila}:{$ultimaColumna}{$fila}")
                ->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setARGB('FFB0B0B0');
            $ultimaFilaDocumento = $fila;
            $fila++;
        }

        if ($ultimaFilaRegistros >= $filaEncabezado + 1) {
            $hoja->setAutoFilter("A{$filaEncabezado}:{$ultimaColumna}{$ultimaFilaRegistros}");
        }

        $hoja->freezePane('A' . ($filaEncabezado + 1));

        $hoja->getStyle("A{$filaEncabezado}:{$ultimaColumna}{$ultimaFilaDocumento}")
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFE1E1E1');

        $hoja->getStyle("A" . ($filaEncabezado + 1) . ":{$ultimaColumna}{$ultimaFilaDocumento}")
            ->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        for ($col = 1; $col <= count($columnas); $col++) {
            $letra = $this->letraColumna($col);
            $hoja->getColumnDimension($letra)->setAutoSize(true);
        }

        $hoja->getPageSetup()
            ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
            ->setFitToPage(true)
            ->setFitToWidth(1)
            ->setFitToHeight(0);

        $hoja->getPageSetup()->setPrintArea("A1:{$ultimaColumna}{$ultimaFilaDocumento}");
        $hoja->getPageMargins()
            ->setTop(0.35)
            ->setBottom(0.55)
            ->setLeft(0.45)
            ->setRight(0.45)
            ->setHeader(0.15)
            ->setFooter(0.2);

        $fechaGeneracion = $membrete['generado_el'];
        $hoja->getHeaderFooter()->setOddFooter(
            '&LRevive · Sistema de Gestión'
            . '&CGenerado: ' . $fechaGeneracion
            . '&RPágina &P-&N'
        );

        $hoja->getHeaderFooter()->setEvenFooter(
            '&LRevive · Sistema de Gestión'
            . '&CGenerado: ' . $fechaGeneracion
            . '&RPágina &P-&N'
        );

        $archivo = preg_replace('/[^A-Za-z0-9_-]+/', '-', $nombreArchivo)
            . '-' . now()->format('Ymd-His') . '.xlsx';

        return response()->streamDownload(
            function () use ($spreadsheet): void {
                (new Xlsx($spreadsheet))->save('php://output');
                $spreadsheet->disconnectWorksheets();
            },
            $archivo,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
            ]
        );
    }

    private function letraColumna(int $numero): string
    {
        $letra = '';
        while ($numero > 0) {
            $modulo = ($numero - 1) % 26;
            $letra = chr(65 + $modulo) . $letra;
            $numero = intdiv($numero - 1, 26);
        }

        return $letra;
    }
}
