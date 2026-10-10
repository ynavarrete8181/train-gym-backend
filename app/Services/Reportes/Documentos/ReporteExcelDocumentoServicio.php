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
            $drawing->setHeight(58);
            $drawing->setCoordinates('A1');
            $drawing->setOffsetX(8);
            $drawing->setOffsetY(6);
            $drawing->setWorksheet($hoja);
        }

        $hoja->mergeCells("B1:{$ultimaColumna}1");
        $hoja->setCellValue('B1', $membrete['institucion'] . ' - ' . $membrete['subtitulo']);
        $hoja->mergeCells("B2:{$ultimaColumna}2");
        $hoja->setCellValue('B2', $membrete['titulo']);
        $hoja->mergeCells("B3:{$ultimaColumna}3");
        $hoja->setCellValue('B3', $membrete['descripcion']);

        $hoja->getStyle("A1:{$ultimaColumna}3")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFF8F3DC');
        $hoja->getStyle("B1:{$ultimaColumna}1")->getFont()->setBold(true)->setSize(13)->getColor()->setARGB('FF3C2E08');
        $hoja->getStyle("B2:{$ultimaColumna}2")->getFont()->setBold(true)->setSize(16)->getColor()->setARGB('FF171717');
        $hoja->getStyle("B3:{$ultimaColumna}3")->getFont()->setSize(10)->getColor()->setARGB('FF5F6368');

        $metadataBase = [
            'Generado por' => $membrete['generado_por'],
            'Rol' => $membrete['rol'],
            'Correo' => $membrete['correo'],
            'Generado el' => $membrete['generado_el'],
        ];

        $filaMeta = 5;
        foreach (array_merge($metadataBase, $membrete['metadata']) as $etiqueta => $valor) {
            if ($valor === null || $valor === '' || $valor === []) {
                continue;
            }

            $hoja->setCellValue("A{$filaMeta}", $etiqueta);
            $hoja->setCellValue("B{$filaMeta}", is_array($valor) ? implode(', ', $valor) : (string) $valor);
            $hoja->mergeCells("B{$filaMeta}:{$ultimaColumna}{$filaMeta}");
            $hoja->getStyle("A{$filaMeta}")->getFont()->setBold(true)->getColor()->setARGB('FF5A470D');
            $filaMeta++;
        }

        $filaEncabezado = $filaMeta + 1;
        foreach (array_values($columnas) as $indice => $encabezado) {
            $hoja->setCellValue([$indice + 1, $filaEncabezado], $encabezado);
        }

        $hoja->getStyle("A{$filaEncabezado}:{$ultimaColumna}{$filaEncabezado}")
            ->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $hoja->getStyle("A{$filaEncabezado}:{$ultimaColumna}{$filaEncabezado}")
            ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF4F3C0A');
        $hoja->getStyle("A{$filaEncabezado}:{$ultimaColumna}{$filaEncabezado}")
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);

        $fila = $filaEncabezado + 1;
        foreach ($filas as $registro) {
            foreach (array_values($columnas) as $indice => $_) {
                $valor = $registro[$indice] ?? '';
                $hoja->setCellValue([$indice + 1, $fila], $valor);
            }
            $fila++;
        }

        if ($fila > $filaEncabezado + 1) {
            $hoja->setAutoFilter("A{$filaEncabezado}:{$ultimaColumna}" . ($fila - 1));
        }

        $hoja->freezePane('A' . ($filaEncabezado + 1));
        $hoja->getStyle("A{$filaEncabezado}:{$ultimaColumna}" . max($filaEncabezado, $fila - 1))
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFE1E1E1');
        $hoja->getStyle("A" . ($filaEncabezado + 1) . ":{$ultimaColumna}" . max($filaEncabezado + 1, $fila - 1))
            ->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        for ($col = 1; $col <= count($columnas); $col++) {
            $hoja->getColumnDimension($this->letraColumna($col))->setAutoSize(true);
        }

        $hoja->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
        $hoja->getPageMargins()->setTop(0.35)->setBottom(0.35)->setLeft(0.3)->setRight(0.3);
        $hoja->getHeaderFooter()->setOddFooter('&LRevive&C' . $titulo . '&RPágina &P de &N');

        $archivo = preg_replace('/[^A-Za-z0-9_-]+/', '-', $nombreArchivo) . '-' . now()->format('Ymd-His') . '.xlsx';

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
