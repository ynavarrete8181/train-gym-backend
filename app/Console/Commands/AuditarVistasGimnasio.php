<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class AuditarVistasGimnasio extends Command
{
    protected $signature = 'revive:auditar-vistas-gimnasio';

    protected $description = 'Detecta consumidores de vistas gimnasio sin alterar la base de datos';

    public function handle(): int
    {
        $hallazgos = [];
        foreach ([app_path(), base_path('routes')] as $directorio) {
            if (! is_dir($directorio)) {
                continue;
            }

            $archivos = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directorio));
            foreach ($archivos as $archivo) {
                if (! $archivo->isFile() || $archivo->getExtension() !== 'php') {
                    continue;
                }

                $ruta = $archivo->getPathname();
                if ($ruta === __FILE__) {
                    continue;
                }

                $contenido = file_get_contents($ruta);
                if ($contenido === false) {
                    continue;
                }

                if (preg_match_all('/(?<![a-zA-Z0-9_])gimnasio\\.[a-z_][a-z0-9_]*/i', $contenido, $coincidencias)) {
                    $hallazgos[] = [
                        'archivo' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $ruta),
                        'referencias' => implode(', ', array_unique($coincidencias[0])),
                    ];
                }
            }
        }

        $vistas = DB::select("
            SELECT table_name
            FROM information_schema.views
            WHERE table_schema = 'gimnasio'
            ORDER BY table_name
        ");

        $this->info('Vistas actuales: '.count($vistas));
        $this->table(['Vista'], array_map(fn ($v) => [$v->table_name], $vistas));
        $this->info('Archivos con referencias al esquema gimnasio: '.count($hallazgos));
        if ($hallazgos) {
            $this->table(['Archivo', 'Referencias'], $hallazgos);
            $this->warn('No eliminar vistas hasta resolver estas referencias y las dependencias de PostgreSQL.');
            return self::FAILURE;
        }

        $this->info('Sin referencias literales en app/ y routes/. Revisar también SQL dinámico y consumidores externos.');
        return self::SUCCESS;
    }
}
