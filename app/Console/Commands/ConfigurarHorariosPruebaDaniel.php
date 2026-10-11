<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ConfigurarHorariosPruebaDaniel extends Command
{
    protected $signature = 'revive:configurar-horarios-prueba-daniel';
    protected $description = 'Asigna a Daniel Palma horarios base ya configurados, solo en local/testing.';

    public function handle(): int
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

        $nombres = [
            'Funcional tarde',
            'Fuerza tarde',
            'Fuerza noche',
            'Semi personalizado tarde',
            'Evaluación inicial mañana',
        ];

        $bloques = DB::table('gimnasio.horario_bloques as hb')
            ->join('gimnasio.servicios as sv', 'hb.servicio_id', '=', 'sv.id')
            ->whereIn('hb.nombre', $nombres)
            ->where('hb.activo', true)
            ->orderBy('hb.nombre')
            ->get([
                'hb.id',
                'hb.nombre',
                'sv.nombre as servicio_nombre',
            ]);

        $faltantes = collect($nombres)->diff($bloques->pluck('nombre'));
        if ($faltantes->isNotEmpty()) {
            $this->error('Faltan horarios base. Ejecuta primero: php artisan migrate');
            $this->line('No encontrados: '.$faltantes->implode(', '));
            return self::FAILURE;
        }

        foreach ($bloques as $bloque) {
            DB::table('gimnasio.horario_entrenadores')->updateOrInsert(
                [
                    'entrenador_id' => (int) $entrenador->id,
                    'horario_bloque_id' => (int) $bloque->id,
                ],
                [
                    'activo' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            $this->line("• {$bloque->nombre} | {$bloque->servicio_nombre}");
        }

        $this->newLine();
        $this->info('Horarios base asignados a Daniel correctamente.');
        $this->info('Revísalos en Equipo > Entrenadores y luego en Clientes > Entrenador y horario.');

        return self::SUCCESS;
    }
}
