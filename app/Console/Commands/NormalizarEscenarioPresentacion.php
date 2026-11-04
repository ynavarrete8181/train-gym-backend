<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class NormalizarEscenarioPresentacion extends Command
{
    protected $signature = 'revive:normalizar-escenario-presentacion {--confirm : Confirma expresamente la escritura}';

    protected $description = 'Normaliza los identificadores visibles del escenario sintético para una presentación de Revive.';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) || DB::connection()->getDatabaseName() !== 'db_gimnasio') {
            $this->error('Operación permitida únicamente en db_gimnasio local/testing.');

            return self::FAILURE;
        }
        if (! $this->option('confirm')) {
            $this->error('Añade --confirm para normalizar los datos visibles.');

            return self::FAILURE;
        }

        $actualizados = DB::transaction(function (): int {
            $total = 0;
            for ($i = 1; $i <= 30; $i++) {
                $anterior = sprintf('tfm.oct26.%03d@demo.revive.local', $i);
                $usuario = DB::table('seguridad.users')
                    ->where('email', $anterior)
                    ->orWhere('cedula', sprintf('REV26%05d', $i))
                    ->first();
                if (! $usuario) {
                    continue;
                }

                $local = Str::slug((string) $usuario->name, '.');
                $email = sprintf('%s.%02d@revive.local', $local, $i);
                DB::table('seguridad.users')->where('id', $usuario->id)->update([
                    'email' => $email,
                    'cedula' => sprintf('REV26%05d', $i),
                    'password' => Hash::make(sprintf('Revive26#Cliente%03d!', $i)),
                    'updated_at' => now(),
                ]);

                $deportista = DB::table('clientes.deportistas')->where('usuario_id', $usuario->id)->first();
                if (! $deportista) {
                    continue;
                }
                DB::table('personas.personas')->where('id', $deportista->persona_id)->update([
                    'identificacion' => sprintf('REV26%05d', $i),
                    'email' => $email,
                    'direccion' => 'Manta, Manabí',
                    'updated_at' => now(),
                ]);
                DB::table('clientes.deportistas')->where('id', $deportista->id)->update([
                    'codigo_deportista' => sprintf('REV-CLI-%04d', 1000 + $i),
                    'contacto_emergencia_nombre' => 'Contacto de emergencia',
                    'observaciones_medicas' => 'Sin observaciones médicas registradas.',
                    'updated_at' => now(),
                ]);

                $membresia = DB::table('membresias.membresias')->where('deportista_id', $deportista->id)->where(function ($q): void {
                    $q->where('codigo_contrato', 'like', 'DEMO-TFM-2026-%')
                        ->orWhere('codigo_contrato', 'like', 'REV-MEM-2026-%');
                })->first();
                if ($membresia) {
                    $condiciones = (array) json_decode((string) $membresia->condiciones_contratadas, true);
                    unset($condiciones['demo'], $condiciones['marca']);
                    $condiciones['origen'] = 'CARGA_INICIAL';
                    DB::table('membresias.membresias')->where('id', $membresia->id)->update([
                        'codigo_contrato' => sprintf('REV-MEM-2026-%04d', 1000 + $i),
                        'condiciones_contratadas' => json_encode($condiciones),
                        'updated_at' => now(),
                    ]);
                    DB::table('entrenamiento.asignaciones_entrenador_cliente')->where('membresia_id', $membresia->id)->update([
                        'observaciones' => 'Seguimiento deportivo asignado.',
                        'updated_at' => now(),
                    ]);
                }

                $venta = DB::table('ventas.ventas')->where('cliente_id', $deportista->id)->where(function ($q): void {
                    $q->where('numero', 'like', 'DEMO-TFM-V-%')->orWhere('numero', 'like', 'REV-VTA-2026-%');
                })->first();
                if ($venta) {
                    $planNombre = $membresia ? DB::table('membresias.planes')->where('id', $membresia->plan_id)->value('nombre') : 'Plan Revive';
                    DB::table('ventas.ventas')->where('id', $venta->id)->update([
                        'numero' => sprintf('REV-VTA-2026-%04d', 1000 + $i),
                        'concepto' => 'Membresía '.$planNombre,
                        'observaciones' => 'Venta de membresía registrada.',
                        'updated_at' => now(),
                    ]);
                    DB::table('ventas.venta_detalles')->where('venta_id', $venta->id)->update([
                        'descripcion' => 'Membresía '.$planNombre,
                        'updated_at' => now(),
                    ]);
                    DB::table('ventas.pagos')->where('venta_id', $venta->id)->update([
                        'numero_comprobante' => sprintf('REV-PAG-2026-%04d', 1000 + $i),
                        'operacion_cobro_id' => sprintf('REV-OP-2026-%04d', 1000 + $i),
                        'codigo_cobro' => sprintf('REV-COB-%04d', 1000 + $i),
                        'referencia' => 'Pago de membresía',
                        'observaciones' => 'Pago confirmado.',
                        'updated_at' => now(),
                    ]);
                }

                DB::table('clientes.fichas_iniciales')->where('deportista_id', $deportista->id)->get()->each(function ($ficha): void {
                    $antecedentes = (array) json_decode((string) $ficha->antecedentes, true);
                    $valoracion = (array) json_decode((string) $ficha->valoracion, true);
                    unset($antecedentes['demo'], $antecedentes['nota'], $valoracion['origen']);
                    $antecedentes['observacion'] = 'Sin antecedentes relevantes declarados.';
                    DB::table('clientes.fichas_iniciales')->where('id', $ficha->id)->update([
                        'antecedentes' => json_encode($antecedentes),
                        'valoracion' => json_encode($valoracion),
                        'updated_at' => now(),
                    ]);
                });

                DB::table('entrenamiento.planes')->where('cliente_id', $deportista->id)->where(function ($q): void {
                    $q->where('nombre', 'DEMO-TFM-OCT-2026')->orWhere('nombre', 'like', 'Plan personalizado REV-CLI-%');
                })->update([
                    'nombre' => sprintf('Plan personalizado REV-CLI-%04d', 1000 + $i),
                    'observaciones' => 'Plan individual activo.',
                    'updated_at' => now(),
                ]);
                DB::table('entrenamiento.rm_registros')->where('cliente_id', $deportista->id)->update(['observaciones' => 'RM estimada mediante fórmula de Epley.', 'updated_at' => now()]);
                DB::table('entrenamiento.progresos_corporales')->where('cliente_id', $deportista->id)->update(['observaciones' => 'Control mensual de progreso.', 'updated_at' => now()]);
                DB::table('entrenamiento.rutinas')->whereIn('plan_id', DB::table('entrenamiento.planes')->where('cliente_id', $deportista->id)->pluck('id'))->update(['notas' => 'Progresión mensual planificada.', 'updated_at' => now()]);
                DB::table('entrenamiento.sesiones')->where('cliente_id', $deportista->id)->update(['observaciones' => 'Seguimiento de sesión.', 'updated_at' => now()]);
                DB::table('entrenamiento.series_ejecutadas')->whereIn('sesion_ejercicio_id', function ($q) use ($deportista): void {
                    $q->from('entrenamiento.sesiones_ejercicios as se')->join('entrenamiento.sesiones as s', 's.id', '=', 'se.sesion_id')->where('s.cliente_id', $deportista->id)->select('se.id');
                })->update(['observaciones' => 'Serie completada.', 'updated_at' => now()]);
                $total++;
            }

            return $total;
        }, 3);

        $this->info("Perfiles normalizados: {$actualizados}.");

        return self::SUCCESS;
    }
}
