<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrarHorarios extends Command
{
    protected $signature = 'gimnasio:migrar-horarios';

    public function handle()
    {
        $this->info("Migrando horarios desde V2...");

        $horariosV2 = DB::connection('pgsql_v2')->table('train_gimnasio.horarios_gym as h')
            ->join('train_gimnasio.tipos_servicios as ts', 'h.tipo_servicio_id', '=', 'ts.id')
            ->select('h.*', 'ts.nombre as servicio_nombre')
            ->get();

        $diasMapa = [
            1 => 'LUNES',
            2 => 'MARTES',
            3 => 'MIERCOLES',
            4 => 'JUEVES',
            5 => 'VIERNES',
            6 => 'SABADO',
            7 => 'DOMINGO'
        ];

        $insertados = 0;

        foreach ($horariosV2 as $hV2) {
            // Find the new service ID based on name
            $nuevoServicio = DB::table('gimnasio.servicios')->where('nombre', $hV2->servicio_nombre)->first();

            if (!$nuevoServicio) {
                $this->warn("No se encontro el servicio: " . $hV2->servicio_nombre);
                continue;
            }

            // Create a Horario Bloque
            $bloqueId = DB::table('gimnasio.horario_bloques')->insertGetId([
                'nombre' => 'Horario Base - ' . $hV2->servicio_nombre,
                'servicio_id' => $nuevoServicio->id,
                'activo' => $hV2->activo,
                'created_at' => $hV2->created_at,
                'updated_at' => $hV2->updated_at
            ]);

            // Get dias for this horario in V2
            $diasV2 = DB::connection('pgsql_v2')->table('train_gimnasio.horarios_gym_dias')
                ->where('horario_id', $hV2->id)
                ->get();

            foreach ($diasV2 as $dV2) {
                $diaSemanaStr = $diasMapa[$dV2->dia_semana] ?? null;
                
                if ($diaSemanaStr) {
                    DB::table('gimnasio.horarios_servicio')->insert([
                        'horario_bloque_id' => $bloqueId,
                        'servicio_id' => $nuevoServicio->id,
                        'sede_id' => $hV2->sede_id,
                        'dia_semana' => $diaSemanaStr,
                        'hora_inicio' => $hV2->hora_apertura,
                        'hora_fin' => $hV2->hora_cierre,
                        'capacidad' => $hV2->capacidad_maxima,
                        'activo' => $hV2->activo,
                        'created_at' => $hV2->created_at,
                        'updated_at' => $hV2->updated_at
                    ]);
                    $insertados++;
                }
            }
        }

        $this->info("Migrados {$insertados} detalles de horarios de servicio.");
    }
}
