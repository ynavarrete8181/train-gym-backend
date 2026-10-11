<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrarDatosGimnasioV2 extends Command
{
    protected $signature = 'gimnasio:migrar-v2';
    protected $description = 'Migrate gym data from db_gimnasio_v2 to db_gimnasio';

    public function handle()
    {
        $this->info("Iniciando migración desde V2...");

        $this->migrarPlanes();
        $this->migrarDeportistas();
        $this->migrarSocioMembresias();

        $this->info("¡Migración completada exitosamente!");
    }

    private function migrarPlanes()
    {
        $this->info("Migrando Planes/Membresías...");
        $planesV2 = DB::connection('pgsql_v2')->table('socios.membresias')->get();

        foreach ($planesV2 as $plan) {
            $existe = DB::table('gimnasio.planes')->where('id', $plan->id)->exists();
            if (!$existe) {
                DB::table('gimnasio.planes')->insert([
                    'id' => $plan->id,
                    'codigo' => 'PLAN-' . str_pad($plan->id, 4, '0', STR_PAD_LEFT),
                    'nombre' => $plan->nombre,
                    'descripcion' => $plan->descripcion,
                    'tipo_duracion' => 'DIAS',
                    'duracion' => $plan->duracion_dias,
                    'precio_base' => $plan->precio,
                    'tarifa_inscripcion' => 0,
                    'activo' => $plan->activa,
                    'created_at' => $plan->created_at,
                    'updated_at' => $plan->updated_at,
                ]);
            }
        }
        $this->info("Planes migrados: " . count($planesV2));
    }

    private function migrarDeportistas()
    {
        $this->info("Migrando Deportistas (Socios) y Usuarios...");
        $sociosV2 = DB::connection('pgsql_v2')->table('socios.socios')
            ->join('core.personas', 'socios.socios.persona_id', '=', 'core.personas.id')
            ->select('socios.socios.*', 'core.personas.nombres', 'core.personas.apellidos', 'core.personas.numero_identificacion', 'core.personas.email', 'core.personas.telefono', 'core.personas.fecha_nacimiento', 'core.personas.sexo')
            ->get();

        foreach ($sociosV2 as $socio) {
            $cedula = $socio->numero_identificacion ?: ('SOCIO-'.$socio->id);
            $email = $socio->email ?: ($cedula . '@revive.local');

            $usuario = DB::table('seguridad.users')->where('cedula', $cedula)->first();
            
            if (!$usuario) {
                $usuarioId = DB::table('seguridad.users')->insertGetId([
                    'name' => trim($socio->nombres . ' ' . $socio->apellidos),
                    'nombres' => $socio->nombres,
                    'apellidos' => $socio->apellidos,
                    'cedula' => $cedula,
                    'email' => $email,
                    'password' => bcrypt($cedula),
                    'usr_estado' => 1,
                    'created_at' => $socio->created_at,
                    'updated_at' => $socio->updated_at,
                ]);
            } else {
                $usuarioId = $usuario->id;
            }

            $existe = DB::table('gimnasio.deportistas')->where('id', $socio->id)->exists();
            if (!$existe) {
                DB::table('gimnasio.deportistas')->insert([
                    'id' => $socio->id,
                    'usuario_id' => $usuarioId,
                    'codigo_deportista' => $socio->codigo_socio ?: ('DEP-' . str_pad($socio->id, 4, '0', STR_PAD_LEFT)),
                    'fecha_nacimiento' => $socio->fecha_nacimiento,
                    'genero' => $socio->sexo,
                    'telefono' => $socio->telefono,
                    'estado' => 'ACTIVO',
                    'created_at' => $socio->created_at,
                    'updated_at' => $socio->updated_at,
                ]);
            }
        }
        $this->info("Deportistas migrados: " . count($sociosV2));
    }

    private function migrarSocioMembresias()
    {
        $this->info("Migrando Contratos de Membresía...");
        $contratosV2 = DB::connection('pgsql_v2')->table('socios.socio_membresias')->get();

        foreach ($contratosV2 as $contrato) {
            $existe = DB::table('gimnasio.membresias')->where('id', $contrato->id)->exists();
            if (!$existe) {
                $deportistaExists = DB::table('gimnasio.deportistas')->where('id', $contrato->socio_id)->exists();
                $planExists = DB::table('gimnasio.planes')->where('id', $contrato->membresia_id)->exists();
                
                if ($deportistaExists && $planExists) {
                    DB::table('gimnasio.membresias')->insert([
                        'id' => $contrato->id,
                        'deportista_id' => $contrato->socio_id,
                        'plan_id' => $contrato->membresia_id,
                        'codigo_contrato' => 'MEMB-' . str_pad($contrato->id, 5, '0', STR_PAD_LEFT),
                        'fecha_inicio' => $contrato->fecha_inicio,
                        'fecha_fin' => $contrato->fecha_fin,
                        'estado' => 'ACTIVO',
                        'dias_gracia' => 0,
                        'renovacion_automatica' => false,
                        'created_at' => $contrato->created_at,
                        'updated_at' => $contrato->updated_at,
                    ]);
                }
            }
        }
        
        DB::statement("SELECT setval('gimnasio.planes_id_seq', (SELECT MAX(id) FROM gimnasio.planes))");
        DB::statement("SELECT setval('gimnasio.deportistas_id_seq', (SELECT MAX(id) FROM gimnasio.deportistas))");
        DB::statement("SELECT setval('gimnasio.membresias_id_seq', (SELECT MAX(id) FROM gimnasio.membresias))");

        $this->info("Contratos migrados.");
    }
}
