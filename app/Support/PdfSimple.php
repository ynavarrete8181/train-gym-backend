<?php

namespace App\Support;

/**
 * Documento PDF de varias páginas A4 con Helvetica, sin librerías externas.
 * Lleva un cursor vertical: cada bloque baja el cursor y salta de página cuando no cabe.
 */
class PdfSimple
{
    public const ANCHO = 595;
    public const ALTO = 842;
    public const MARGEN = 36;

    private array $paginas = [[]];
    private ?array $imagenLogo = null;
    public float $y = 0;

    /** Se llama al abrir cada página nueva (encabezado). */
    public $alAbrirPagina = null;

    public function __construct()
    {
        $this->y = self::ALTO - self::MARGEN;
    }

    public function asegurar(float $alto): void
    {
        if ($this->y - $alto < self::MARGEN + 20) {
            $this->paginas[] = [];
            $this->y = self::ALTO - self::MARGEN;
            if ($this->alAbrirPagina) ($this->alAbrirPagina)($this);
        }
    }

    public function texto(float $x, float $y, string $texto, float $tamano = 9, bool $negrita = false, array $rgb = [0.07, 0.09, 0.1]): void
    {
        [$r, $g, $b] = $rgb;
        $this->op(sprintf('%.3F %.3F %.3F rg BT /%s %.1F Tf %.1F %.1F Td (%s) Tj ET',
            $r, $g, $b, $negrita ? 'F2' : 'F1', $tamano, $x, $y, $this->escapar($texto)));
    }

    public function linea(float $x1, float $y1, float $x2, float $y2, array $rgb = [0.88, 0.89, 0.91]): void
    {
        [$r, $g, $b] = $rgb;
        $this->op(sprintf('%.3F %.3F %.3F RG 0.6 w %.1F %.1F m %.1F %.1F l S', $r, $g, $b, $x1, $y1, $x2, $y2));
    }

    public function rectangulo(float $x, float $y, float $w, float $h, array $rgb): void
    {
        [$r, $g, $b] = $rgb;
        $this->op(sprintf('%.3F %.3F %.3F rg %.1F %.1F %.1F %.1F re f', $r, $g, $b, $x, $y, $w, $h));
    }

    /**
     * Dibuja el logo oficial horizontal (public/brand/revive-logo-horizontal.jpg) con la
     * altura indicada. Devuelve false si el archivo no existe, para usar un texto en su lugar.
     */
    public function logo(float $x, float $y, float $alto): bool
    {
        if (! $this->imagenLogo) {
            $ruta = public_path('brand/revive-logo-horizontal.jpg');
            $info = is_file($ruta) ? @getimagesize($ruta) : false;
            if (! $info || ($info[2] ?? null) !== IMAGETYPE_JPEG) {
                return false;
            }
            $this->imagenLogo = ['datos' => file_get_contents($ruta), 'ancho' => $info[0], 'alto' => $info[1]];
        }
        $ancho = $alto * $this->imagenLogo['ancho'] / $this->imagenLogo['alto'];
        $this->op(sprintf('q %.1F 0 0 %.1F %.1F %.1F cm /Logo Do Q', $ancho, $alto, $x, $y));

        return true;
    }

    /** Parte un texto en líneas que caben en el ancho dado (aproximación para Helvetica). */
    public function partir(string $texto, float $ancho, float $tamano = 8.5): array
    {
        $max = max(8, (int) floor($ancho / ($tamano * 0.5)));
        $lineas = [];
        foreach (preg_split("/\r?\n/", $texto) as $parrafo) {
            $lineas = array_merge($lineas, explode("\n", wordwrap($parrafo, $max, "\n", true)));
        }
        return $lineas ?: [''];
    }

    /**
     * Tabla con encabezado y filas; las celdas largas pasan a varias líneas.
     * $columnas: [['titulo' => 'Fecha', 'ancho' => 80], ...]
     */
    public function tabla(array $columnas, array $filas, float $tamano = 8): void
    {
        $x0 = self::MARGEN;
        $total = self::ANCHO - 2 * self::MARGEN;
        $encabezado = function () use ($columnas, $x0, $total, $tamano) {
            $this->asegurar(22);
            $this->rectangulo($x0, $this->y - 16, $total, 18, [0.95, 0.955, 0.96]);
            $x = $x0 + 6;
            foreach ($columnas as $c) {
                $this->texto($x, $this->y - 11, $c['titulo'], $tamano, true, [0.40, 0.43, 0.47]);
                $x += $c['ancho'];
            }
            $this->y -= 20;
        };
        $encabezado();
        if (! $filas) {
            $this->texto($x0 + 6, $this->y - 10, 'Sin registros.', $tamano, false, [0.40, 0.43, 0.47]);
            $this->y -= 18;
            return;
        }
        foreach ($filas as $fila) {
            $celdas = [];
            $alto = 1;
            foreach ($columnas as $i => $c) {
                $celdas[$i] = $this->partir((string) ($fila[$i] ?? ''), $c['ancho'] - 10, $tamano);
                $alto = max($alto, count($celdas[$i]));
            }
            $altoFila = $alto * ($tamano + 3) + 6;
            $pagina = count($this->paginas);
            $this->asegurar($altoFila + 2);
            if (count($this->paginas) !== $pagina) $encabezado();
            $x = $x0 + 6;
            foreach ($columnas as $i => $c) {
                foreach ($celdas[$i] as $n => $linea) {
                    $this->texto($x, $this->y - 10 - $n * ($tamano + 3), $linea, $tamano);
                }
                $x += $c['ancho'];
            }
            $this->y -= $altoFila;
            $this->linea($x0, $this->y + 2, $x0 + $total, $this->y + 2);
        }
        $this->y -= 6;
    }

    public function generar(): string
    {
        $paginas = count($this->paginas);
        $objetos = [];
        $objetos[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objetos[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objetos[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $hijos = [];
        $idLogo = 5 + $paginas * 2;
        $xobject = '';
        if ($this->imagenLogo) {
            $objetos[$idLogo] = sprintf('<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>',
                $this->imagenLogo['ancho'], $this->imagenLogo['alto'], strlen($this->imagenLogo['datos']))
                ."\nstream\n".$this->imagenLogo['datos']."\nendstream";
            $xobject = ' /XObject << /Logo '.$idLogo.' 0 R >>';
        }
        foreach ($this->paginas as $i => $ops) {
            $ops[] = sprintf('0.55 0.58 0.62 rg BT /F1 7.5 Tf %.1F 20 Td (%s) Tj ET', self::ANCHO - self::MARGEN - 60,
                $this->escapar('Página '.($i + 1).' de '.$paginas));
            $contenido = implode("\n", $ops);
            $idPagina = 5 + $i * 2;
            $objetos[$idPagina] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.self::ANCHO.' '.self::ALTO.'] '
                .'/Resources << /Font << /F1 3 0 R /F2 4 0 R >>'.$xobject.' >> /Contents '.($idPagina + 1).' 0 R >>';
            $objetos[$idPagina + 1] = '<< /Length '.strlen($contenido)." >>\nstream\n".$contenido."\nendstream";
            $hijos[] = $idPagina.' 0 R';
        }
        $objetos[2] = '<< /Type /Pages /Kids ['.implode(' ', $hijos).'] /Count '.$paginas.' >>';
        ksort($objetos);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objetos as $id => $objeto) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$objeto."\nendobj\n";
        }
        $xref = strlen($pdf);
        $total = max(array_keys($objetos));
        $pdf .= "xref\n0 ".($total + 1)."\n0000000000 65535 f \n";
        for ($id = 1; $id <= $total; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }
        return $pdf."trailer\n<< /Size ".($total + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
    }

    private function op(string $op): void
    {
        $this->paginas[count($this->paginas) - 1][] = $op;
    }

    private function escapar(string $texto): string
    {
        $codificado = iconv('UTF-8', 'Windows-1252//TRANSLIT', $texto);
        $codificado = $codificado === false ? preg_replace('/[^\x20-\x7E]/', '', $texto) : $codificado;
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $codificado);
    }
}
