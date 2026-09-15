<?php

namespace App\Console\Commands;

use App\Services\Gimnasio\ServicioAgendaServicio;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ConfigurarHorariosPruebaDaniel extends Command
{
    protected $signature = 'revive:configurar-horarios-prueba-daniel';
    protected $description = 'Configura horarios de prueba para Daniel Palma usando Servicios y Agenda, solo en local/testing.';

    public function handle(ServicioAgendaServicio $agenda): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Este comando solo puede ejecutarse en entornos local o testing.');
            return self::FAILURE;
        }

        $usuario = DB::table('seguridad.users')
            ->whereRaw('LOWER(email) = ?', ['daniel.palma@revive.local'])
            ->first();

        if (! $usuario) {
            $this->error('No se encontró el usuario daniel.palma@revive.local.');
            return self::FAILURE;
        }

        $entrenador = DB::table('gimnasio.entrenadores')
            ->where('usuario_id', $usuario->id)
            ->where('estado', 'ACTIVO')
            ->first();

        if (! $entrenador) {
            $this->error('Daniel Palma no tiene un perfil de entrenador activo.');
            return self::FAILURE;
        }

        $sede = DB::table('institucional.sedes')
            ->where('activo', true)
            ->orderBy('id_sede')
            ->first();

        if (! $sede) {
            $this->error('No existe ninguna sede activa para configurar los horarios.');
            return self::FAILURE;
        }

        $servicios = DB::table('gimnasio.servicios')
            ->where('activo', true)
            ->orderBy('id')
            ->get();

        if ($servicios->isEmpty()) {
            $this->error('No existen servicios activos. Configura al menos un servicio antes de crear horarios.');
            return self::FAILURE;
        }

        $servicioMusculacion = $this->buscarServicio($servicios, ['muscul', 'gimnas', 'entrenamiento']) ?? $servicios->first();
        $servicioFuncional = $this->buscarServicio($servicios, ['funcion', 'cross', 'circuit'])
            ?? $servicios->firstWhere('id', '!=', $servicioMusculacion->id)
            ?? $servicioMusculacion;

        $configuraciones = [
            [
                'nombre' => 'Tarde 18:00',
                'servicio' => $servicioMusculacion,
                'dias' => ['LUNES', 'MIERCOLES', 'VIERNES'],
                'hora_inicio' => '18:00',
                'hora_fin' => '19:00',
                'capacidad' => 12,
            ],
            [
                'nombre' => 'Noche 19:00',
                'servicio' => $servicioFuncional,
                'dias' => ['MARTES', 'JUEVES'],
                'hora_inicio' => '19:00',
                'hora_fin' => '20:00',
                'capacidad' => 10,
            ],
        ];

        $this->info("Sede seleccionada: {$sede->nombre}.");

        foreach ($configuraciones as $config) {
            $servicio = $config['servicio'];

            $bloqueExistente = DB::table('gimnasio.horario_bloques')
                ->where('nombre', $config['nombre'])
                ->where('servicio_id', $servicio->id)
                ->first();

            $bloque = $agenda->guardarHorarioBloque(
                [
                    'nombre' => $config['nombre'],
                    'servicio_id' => $servicio->id,
                    'hora_inicio' => $config['hora_inicio'],
                    'hora_fin' => $config['hora_fin'],
                    'capacidad' => $config['capacidad'],
                    'activo' => true,
                ],
                [(int) $sede->id_sede],
                $config['dias'],
                $bloqueExistente?->id,
            );

            $bloqueId = (int) ($bloque->id ?? 0);

            if (! $bloqueId) {
                $bloqueId = (int) DB::table('gimnasio.horario_bloques')
                    ->where('nombre', $config['nombre'])
                    ->where('servicio_id', $servicio->id)
                    ->value('id');
            }

            if (! $bloqueId) {
                throw new \RuntimeException("No se pudo resolver el ID del bloque {$config['nombre']} para {$servicio->nombre}.");
            }

            DB::table('gimnasio.horario_bloques')
                ->where('id', $bloqueId)
                ->update(['activo' => true, 'updated_at' => now()]);

            $bloquePersistido = DB::table('gimnasio.horario_bloques')
                ->where('id', $bloqueId)
                ->first();

            if (! $bloquePersistido) {
                throw new \RuntimeException("No se pudo confirmar el bloque {$config['nombre']} con ID {$bloqueId}.");
            }

            DB::table('gimnasio.horario_entrenadores')->updateOrInsert(
                [
                    'entrenador_id' => (int) $entrenador->id,
                    'horario_bloque_id' => $bloqueId,
                ],
                [
                    'activo' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            $dias = implode(', ', $config['dias']);
            $this->line("• #{$bloqueId} {$config['nombre']} | {$servicio->nombre} | {$sede->nombre} | {$dias} | {$config['hora_inicio']}-{$config['hora_fin']} | cupo {$config['capacidad']}");
        }

        $this->newLine();
        $this->info('Horarios de prueba configurados y asignados a Daniel correctamente.');
        $this->info('Revísalos en Servicios y Agenda > Horarios y luego en Clientes > Entrenador y horario.');

        return self::SUCCESS;
    }

    private function buscarServicio($servicios, array $terminos): ?object
    {
        foreach ($terminos as $termino) {
            $coincidencia = $servicios->first(
                fn ($servicio) => str_contains(mb_strtolower((string) $servicio->nombre), mb_strtolower($termino))
            );

            if ($coincidencia) {
                return $coincidencia;
            }
        }

        return null;
    }
}
