<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PrepararModalidadesMensualesRevive extends Command
{
    protected $signature = 'revive:preparar-modalidades-mensuales {--aplicar : Guarda el catálogo sin habilitar su venta}';
    protected $description = 'Prepara 3 planes mensuales y 8 modalidades semanales; simulación por defecto, sin tarifas ni cobros ficticios.';

    private const PLANES = [
        'DEPORTIVO' => ['Entrenamiento Deportivo', [1, 2, 3, 5]],
        'MUSCULACION' => ['Musculación', [3, 4, 5]],
        'HIBRIDO' => ['Entrenamiento Híbrido', [5]],
    ];

    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $this->warn($aplicar ? 'APLICAR: catálogo INACTIVO, sin precios comerciales aprobados.' : 'SIMULACIÓN: no se escribirá ningún dato.');
        foreach (self::PLANES as $clave => [$nombre, $dias]) {
            $this->line($nombre.': '.implode(', ', $dias).' días por semana; duración: 1 MES.');
        }
        if (!$aplicar) {
            $this->info('Se requieren tarifas aprobadas antes de activar modalidades o contratar membresías.');
            return self::SUCCESS;
        }
        if (!$this->confirm('¿Existe respaldo y autorizas crear únicamente planes/modalidades INACTIVOS sin precios reales?')) {
            return self::FAILURE;
        }
        DB::transaction(function (): void {
            foreach (self::PLANES as $clave => [$nombre, $frecuencias]) {
                $codigo = 'REV-MENSUAL-'.$clave;
                $plan = DB::table('membresias.planes')->where('codigo', $codigo)->lockForUpdate()->first();
                if (!$plan) {
                    $id = DB::table('membresias.planes')->insertGetId([
                        'codigo' => $codigo, 'nombre' => $nombre,
                        'descripcion' => 'Membresía mensual con frecuencia semanal según modalidad; semana de lunes a domingo, sin acumulación de usos.',
                        'tipo_duracion' => 'MESES', 'duracion' => 1,
                        'precio_base' => 0, 'tarifa_inscripcion' => 0,
                        'tipo_producto' => 'MEMBRESIA', 'tipo_cobro' => 'RECURRENTE',
                        'generar_venta' => false, 'requiere_pago' => true,
                        'renovable' => true, 'requiere_modalidades' => true,
                        'activo' => false, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $this->info('Plan creado inactivo: '.$nombre);
                } else {
                    $id = $plan->id;
                    $this->warn('Plan existente sin modificaciones: '.$nombre);
                }
                foreach ($frecuencias as $dias) {
                    $modCodigo = 'SEM-'.$dias;
                    if (DB::table('membresias.plan_modalidades')->where('plan_id',$id)->where('codigo',$modCodigo)->exists()) {
                        $this->line('Modalidad existente sin modificar: '.$nombre.' '.$dias.' días.');
                        continue;
                    }
                    DB::table('membresias.plan_modalidades')->insert([
                        'plan_id'=>$id, 'codigo'=>$modCodigo,
                        'nombre'=>$dias.' días por semana',
                        'descripcion'=>'Vigencia de 1 mes calendario, con '.$dias.' días y '.$dias.' usos semanales; reinicio lunes, sin rollover.',
                        'dias_por_semana'=>$dias, 'usos_por_semana'=>$dias, 'uso_ilimitado'=>false,
                        'tipo_duracion'=>'MESES', 'duracion'=>1,
                        'precio_base'=>0, 'tarifa_inscripcion'=>0,
                        'modelo_cobro'=>'FIJO_POR_PERIODO', 'momento_cobro'=>'ANTICIPADO',
                        'permite_prorrateo'=>false, 'permite_extension'=>false,
                        'extension_automatica'=>false, 'permite_rollover'=>false,
                        'activo'=>false, 'created_at'=>now(), 'updated_at'=>now(),
                    ]);
                    $this->info('Modalidad creada inactiva: '.$nombre.' '.$dias.' días.');
                }
            }
        });
        $this->info('Catálogo preparado. No se crearon deportistas, membresías, precios por sede ni ventas.');
        return self::SUCCESS;
    }
}
