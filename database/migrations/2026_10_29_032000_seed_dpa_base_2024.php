<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Fuente histórica de 2024, no representa la clasificación oficial de 2026.
        // La carga 2026 se realizará posteriormente con el archivo oficial validado.
        $archivo = database_path('catalogos/geografia/dpa_ecuador_2024_base.csv');
        if (! is_readable($archivo)) {
            throw new RuntimeException('Falta archivo del catálogo DPA base 2024.');
        }

        $h = fopen($archivo, 'rb');
        try {
            $cabecera = fgetcsv($h);
            $esperada = ['provincia_codigo', 'provincia', 'canton_codigo', 'canton', 'parroquia_codigo', 'parroquia', 'tipo'];
            if ($cabecera !== $esperada) {
                throw new RuntimeException('Encabezado DPA incompatible.');
            }

            $provincias = [];
            $cantones = [];
            $parroquias = [];
            while (($fila = fgetcsv($h)) !== false) {
                if (count($fila) !== 7) {
                    throw new RuntimeException('Fila DPA de formato inválido.');
                }
                [$pc, $pn, $cc, $cn, $rc, $rn, $tipo] = $fila;
                if (! preg_match('/^\d{2}$/', $pc) || ! preg_match('/^\d{4}$/', $cc)
                    || ! preg_match('/^\d{6}$/', $rc) || substr($rc, 0, 4) !== $cc
                    || substr($cc, 0, 2) !== $pc) {
                    throw new RuntimeException('Jerarquía DPA inválida.');
                }
                $provincias[$pc] = ['codigo_dpa' => $pc, 'nombre' => $pn, 'activo' => true, 'fuente_version' => '2024'];
                $cantones[$cc] = ['codigo_dpa' => $cc, 'provincia_codigo' => $pc, 'nombre' => $cn, 'activo' => true, 'fuente_version' => '2024'];
                $parroquias[$rc] = ['codigo_dpa' => $rc, 'canton_codigo' => $cc, 'nombre' => $rn, 'tipo' => $tipo, 'activo' => true, 'fuente_version' => '2024'];
            }

            if (count($provincias) !== 24 || count($cantones) !== 221 || count($parroquias) !== 1401) {
                throw new RuntimeException('Conteos inesperados en catálogo DPA base 2024.');
            }

            // No sobrescribir el catálogo oficial cuando una instalación ya dispone de DPA actualizado.
            if (DB::table('geografia.provincias')->where('fuente_version', '2026')->exists()) {
                return;
            }

            $fecha = now();
            foreach (['geografia.provincias' => $provincias, 'geografia.cantones' => $cantones,
                      'geografia.parroquias' => $parroquias] as $tabla => $items) {
                foreach (array_chunk(array_values($items), 200) as $lote) {
                    DB::table($tabla)->insertOrIgnore(array_map(
                        fn (array $item): array => [...$item, 'created_at' => $fecha, 'updated_at' => $fecha],
                        $lote
                    ));
                }
            }
        } finally {
            fclose($h);
        }
    }

    public function down(): void
    {
        // No eliminar las ubicaciones territoriales: personas podrían tener referencias.
    }
};
