<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AsignarDeportistasPruebaDaniel extends Command
{
    protected $signature = 'revive:asignar-deportistas-prueba-daniel';
    protected $description = 'Asigna los dos deportistas de prueba actuales a Daniel Palma solo en local/testing.';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Este comando solo puede ejecutarse en entornos local o testing.');
            return 1;
        }

        $usuarioDaniel = DB::table('seguridad.users')
            ->whereRaw('LOWER(email) = ?', ['daniel.palma@revive.local'])
            ->first();

        if (! $usuarioDaniel) {
            $this->error('No se encontró el usuario daniel.palma@revive.local.');
            return 1;
        }

        $entrenador = DB::table('gimnasio.entrenadores')
            ->where('usuario_id', $usuarioDaniel->id)
            ->where('estado', 'ACTIVO')
            ->first();

        if (! $entrenador) {
            $this->error('Daniel Palma no tiene un perfil de entrenador activo.');
            return 1;
        }

        $nombres = [
            'Maria Daniela Pinargote Palma',
            'Yandry Moisés Navarrete Mendoza',
        ];

        $usuarios = DB::table('seguridad.users')
            ->whereIn('name', $nombres)
            ->get(['id', 'name']);

        $faltantes = collect($nombres)->diff($usuarios->pluck('name'));
        if ($faltantes->isNotEmpty()) {
            $this->error('No se encontraron todos los deportistas de prueba: '.$faltantes->implode(', '));
            return 1;
        }

        $horarioBloqueId = DB::table('gimnasio.horario_entrenadores')
            ->where('entrenador_id', $entrenador->id)
            ->where('activo', true)
            ->orderBy('id')
            ->value('horario_bloque_id');

        $creadas = 0;
        $existentes = 0;

        DB::transaction(function () use ($usuarios, $entrenador, $horarioBloqueId, &$creadas, &$existentes): void {
            foreach ($usuarios as $usuario) {
                $deportista = DB::table('gimnasio.deportistas')
                    ->where('usuario_id', $usuario->id)
                    ->where('estado', 'ACTIVO')
                    ->first();

                if (! $deportista) {
                    throw new \RuntimeException("{$usuario->name} no tiene un perfil de deportista activo.");
                }

                $yaExiste = DB::table('gimnasio.asignaciones_entrenador_cliente')
                    ->where('entrenador_id', $entrenador->id)
                    ->where('deportista_id', $deportista->id)
                    ->where('estado', 'ACTIVO')
                    ->exists();

                if ($yaExiste) {
                    $existentes++;
                    continue;
                }

                DB::table('gimnasio.asignaciones_entrenador_cliente')->insert([
                    'entrenador_id' => $entrenador->id,
                    'deportista_id' => $deportista->id,
                    'horario_bloque_id' => $horarioBloqueId,
                    'membresia_id' => null,
                    'tipo_asignacion' => 'SEGUIMIENTO',
                    'estado' => 'ACTIVO',
                    'fecha_inicio' => now()->toDateString(),
                    'observaciones' => 'Asignación local de prueba para validar flujo ENTRENADOR.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $creadas++;
            }
        });

        $this->info("Asignaciones nuevas: {$creadas}.");
        $this->info("Asignaciones que ya existían: {$existentes}.");

        if ($horarioBloqueId) {
            $this->info("Se utilizó el horario activo #{$horarioBloqueId} de Daniel para las asignaciones nuevas.");
        } else {
            $this->warn('Daniel todavía no tiene un horario activo asignado; los deportistas se asignaron sin horario.');
        }

        return 0;
    }
}
