<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ImportarCatalogoGeograficoEcuador extends Command
{
    protected $signature = 'geografia:importar-dpa
                            {archivo : Ruta del CSV UTF-8 con encabezados normalizados del INEC}
                            {--version=2026 : Versión oficial del clasificador}
                            {--simular : Validar el catálogo sin guardar cambios}';

    protected $description = 'Importa provincias, cantones y parroquias DPA sin cambiar identificadores históricos.';

    public function handle(): int
    {
        $archivo = (string) $this->argument('archivo');
        if (! is_file($archivo) || ! is_readable($archivo)) {
            $this->error('El archivo CSV no existe o no se puede leer.');
            return self::FAILURE;
        }

        $handle = fopen($archivo, 'rb');
        if ($handle === false) {
            $this->error('No se pudo abrir el CSV.');
            return self::FAILURE;
        }

        try {
            $headers = fgetcsv($handle, 0, ',');
            $esperados = ['provincia_codigo', 'provincia', 'canton_codigo', 'canton', 'parroquia_codigo', 'parroquia', 'tipo'];
            $headers = array_map(fn ($s) => trim((string) $s), $headers ?: []);
            if ($headers !== $esperados) {
                throw ValidationException::withMessages([
                    'archivo' => 'Se esperan exactamente las columnas: ' . implode(',', $esperados),
                ]);
            }

            $provincias = [];
            $cantones = [];
            $parroquias = [];
            $linea = 1;
            while (($row = fgetcsv($handle, 0, ',')) !== false) {
                $linea++;
                if (count($row) === 1 && trim((string) $row[0]) === '') continue;
                if (count($row) !== count($esperados)) {
                    throw new \RuntimeException("Fila {$linea}: número de columnas incorrecto.");
                }

                [$pc, $pn, $cc, $cn, $rc, $rn, $tipo] = array_map('trim', $row);
                // DPA es texto: los ceros iniciales nunca deben eliminarse.
                if (! preg_match('/^[0-9]{2}$/', $pc)
                    || ! preg_match('/^[0-9]{4}$/', $cc)
                    || ! preg_match('/^[0-9]{6}$/', $rc)
                    || substr($cc, 0, 2) !== $pc
                    || substr($rc, 0, 4) !== $cc
                    || $pn === '' || $cn === '' || $rn === '') {
                    throw new \RuntimeException("Fila {$linea}: códigos DPA o nombres inválidos.");
                }

                if ((isset($provincias[$pc]) && $provincias[$pc] !== $pn)
                    || (isset($cantones[$cc]) && $cantones[$cc]['nombre'] !== $cn)
                    || (isset($parroquias[$rc]) && $parroquias[$rc]['nombre'] !== $rn)) {
                    throw new \RuntimeException("Fila {$linea}: código duplicado con nombres incompatibles.");
                }

                $provincias[$pc] = $pn;
                $cantones[$cc] = ['nombre' => $cn, 'provincia_codigo' => $pc];
                $parroquias[$rc] = ['nombre' => $rn, 'canton_codigo' => $cc, 'tipo' => $tipo ?: null];
            }

            if (count($provincias) !== 24 || count($cantones) < 200 || count($parroquias) < 1000) {
                throw new \RuntimeException('Archivo incompleto: deben existir 24 provincias, 200+ cantones y 1000+ parroquias.');
            }

            $this->info(sprintf(
                'Validado: %d provincias, %d cantones y %d parroquias.',
                count($provincias), count($cantones), count($parroquias)
            ));
            if ($this->option('simular')) {
                $this->info('Simulación terminada: no se modificó la base de datos.');
                return self::SUCCESS;
            }

            $version = (string) $this->option('version');
            DB::transaction(function () use ($provincias, $cantones, $parroquias, $version): void {
                $fecha = now();
                foreach ($provincias as $codigo => $nombre) {
                    DB::table('geografia.provincias')->updateOrInsert(['codigo_dpa' => $codigo], [
                        'nombre' => $nombre, 'activo' => true, 'fuente_version' => $version, 'updated_at' => $fecha,
                    ]);
                }
                foreach ($cantones as $codigo => $data) {
                    DB::table('geografia.cantones')->updateOrInsert(['codigo_dpa' => $codigo], [
                        ...$data, 'activo' => true, 'fuente_version' => $version, 'updated_at' => $fecha,
                    ]);
                }
                foreach ($parroquias as $codigo => $data) {
                    DB::table('geografia.parroquias')->updateOrInsert(['codigo_dpa' => $codigo], [
                        ...$data, 'activo' => true, 'fuente_version' => $version, 'updated_at' => $fecha,
                    ]);
                }
                // Las jurisdicciones anteriores no se eliminan: podrían estar referenciadas por personas.
            });

            $this->info('Catálogos cargados correctamente, conservando códigos e historial.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        } finally {
            fclose($handle);
        }
    }
}
