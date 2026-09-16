<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            // Los accesos generales no necesitan reservar una sesión concreta.
            DB::table('gimnasio.servicios')
                ->whereIn('nombre', ['Acceso general con pizarra', 'Acceso general Xpadel'])
                ->update(['requiere_reserva' => false, 'updated_at' => now()]);

            $sede = DB::table('institucional.sedes')
                ->whereRaw('LOWER(nombre) = ?', ['revive home'])
                ->where('activo', true)
                ->first();

            if (! $sede) {
                return;
            }

            $configuraciones = [
                [
                    'servicio' => 'Entrenamiento funcional',
                    'nombre' => 'Funcional mañana',
                    'dias' => ['LUNES', 'MARTES', 'MIERCOLES', 'JUEVES', 'VIERNES'],
                    'inicio' => '06:00',
                    'fin' => '07:00',
                    'capacidad' => 12,
                ],
                [
                    'servicio' => 'Entrenamiento funcional',
                    'nombre' => 'Funcional tarde',
                    'dias' => ['LUNES', 'MARTES', 'MIERCOLES', 'JUEVES', 'VIERNES'],
                    'inicio' => '18:00',
                    'fin' => '19:00',
                    'capacidad' => 12,
                ],
                [
                    'servicio' => 'Fuerza e hipertrofia',
                    'nombre' => 'Fuerza tarde',
                    'dias' => ['LUNES', 'MARTES', 'MIERCOLES', 'JUEVES', 'VIERNES'],
                    'inicio' => '17:00',
                    'fin' => '18:00',
                    'capacidad' => 10,
                ],
                [
                    'servicio' => 'Fuerza e hipertrofia',
                    'nombre' => 'Fuerza noche',
                    'dias' => ['LUNES', 'MARTES', 'MIERCOLES', 'JUEVES', 'VIERNES'],
                    'inicio' => '19:00',
                    'fin' => '20:00',
                    'capacidad' => 10,
                ],
                [
                    'servicio' => 'Personalizado 1:1',
                    'nombre' => 'Personalizado mañana',
                    'dias' => ['LUNES', 'MARTES', 'MIERCOLES', 'JUEVES', 'VIERNES'],
                    'inicio' => '07:00',
                    'fin' => '08:00',
                    'capacidad' => 1,
                ],
                [
                    'servicio' => 'Personalizado 1:1',
                    'nombre' => 'Personalizado tarde',
                    'dias' => ['LUNES', 'MARTES', 'MIERCOLES', 'JUEVES', 'VIERNES'],
                    'inicio' => '16:00',
                    'fin' => '17:00',
                    'capacidad' => 1,
                ],
                [
                    'servicio' => 'Semi personalizado',
                    'nombre' => 'Semi personalizado tarde',
                    'dias' => ['LUNES', 'MIERCOLES', 'VIERNES'],
                    'inicio' => '18:00',
                    'fin' => '19:00',
                    'capacidad' => 4,
                ],
                [
                    'servicio' => 'Evaluación física inicial',
                    'nombre' => 'Evaluación inicial mañana',
                    'dias' => ['LUNES', 'MARTES', 'MIERCOLES', 'JUEVES', 'VIERNES'],
                    'inicio' => '08:00',
                    'fin' => '08:45',
                    'capacidad' => 1,
                ],
            ];

            foreach ($configuraciones as $config) {
                $servicio = DB::table('gimnasio.servicios')
                    ->where('nombre', $config['servicio'])
                    ->where('activo', true)
                    ->first();

                if (! $servicio) {
                    continue;
                }

                $capacidad = min((int) $config['capacidad'], (int) $servicio->capacidad_base);

                $bloque = DB::table('gimnasio.horario_bloques')
                    ->where('servicio_id', $servicio->id)
                    ->where('nombre', $config['nombre'])
                    ->first();

                if ($bloque) {
                    DB::table('gimnasio.horario_bloques')
                        ->where('id', $bloque->id)
                        ->update([
                            'activo' => true,
                            'updated_at' => now(),
                        ]);
                    $bloqueId = (int) $bloque->id;
                } else {
                    $bloqueId = (int) DB::table('gimnasio.horario_bloques')->insertGetId([
                        'nombre' => $config['nombre'],
                        'servicio_id' => $servicio->id,
                        'activo' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $diasActivos = [];
                foreach ($config['dias'] as $dia) {
                    $diasActivos[] = $dia;
                    DB::table('gimnasio.horarios_servicio')->updateOrInsert(
                        [
                            'horario_bloque_id' => $bloqueId,
                            'sede_id' => (int) $sede->id_sede,
                            'dia_semana' => $dia,
                        ],
                        [
                            'nombre' => $config['nombre'],
                            'servicio_id' => $servicio->id,
                            'entrenador_id' => null,
                            'hora_inicio' => $config['inicio'],
                            'hora_fin' => $config['fin'],
                            'capacidad' => $capacidad,
                            'activo' => true,
                            'updated_at' => now(),
                            'created_at' => now(),
                        ],
                    );
                }

                DB::table('gimnasio.horarios_servicio')
                    ->where('horario_bloque_id', $bloqueId)
                    ->where('sede_id', (int) $sede->id_sede)
                    ->whereNotIn('dia_semana', $diasActivos)
                    ->update(['activo' => false, 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::table('gimnasio.servicios')
                ->whereIn('nombre', ['Acceso general con pizarra', 'Acceso general Xpadel'])
                ->update(['requiere_reserva' => true, 'updated_at' => now()]);

            $nombres = [
                'Funcional mañana',
                'Funcional tarde',
                'Fuerza tarde',
                'Fuerza noche',
                'Personalizado mañana',
                'Personalizado tarde',
                'Semi personalizado tarde',
                'Evaluación inicial mañana',
            ];

            $ids = DB::table('gimnasio.horario_bloques')->whereIn('nombre', $nombres)->pluck('id');
            DB::table('gimnasio.horarios_servicio')->whereIn('horario_bloque_id', $ids)->update(['activo' => false, 'updated_at' => now()]);
            DB::table('gimnasio.horario_bloques')->whereIn('id', $ids)->update(['activo' => false, 'updated_at' => now()]);
        });
    }
};
