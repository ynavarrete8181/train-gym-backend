<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PrepararDeportistaPresentacion extends Command
{
    protected $signature = 'revive:preparar-deportista-presentacion {cedula} {--confirm}';

    protected $description = 'Completa el flujo deportivo y comercial de un deportista existente para presentación local.';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) || DB::connection()->getDatabaseName() !== 'db_gimnasio') {
            $this->error('Operación permitida únicamente en db_gimnasio local/testing.');

            return self::FAILURE;
        }
        if (! $this->option('confirm')) {
            $this->error('Añade --confirm para escribir.');

            return self::FAILURE;
        }

        $documento = trim((string) $this->argument('cedula'));
        $deportista = DB::table('clientes.deportistas as d')
            ->join('personas.personas as p', 'p.id', '=', 'd.persona_id')
            ->where('p.identificacion', $documento)
            ->first(['d.*', 'p.nombre_completo']);
        if (! $deportista) {
            $this->error('No existe un deportista con la identificación indicada.');

            return self::FAILURE;
        }

        $resultado = DB::transaction(fn (): array => $this->completar($deportista), 3);
        $this->table(['Entidad', 'Total'], collect($resultado)->map(fn ($v, $k) => [$k, $v])->all());

        return self::SUCCESS;
    }

    private function completar(object $deportista): array
    {
        $inicio = CarbonImmutable::create(2026, 10, 1);
        $fin = CarbonImmutable::create(2026, 11, 30);
        $sedeId = (int) ($deportista->sede_principal_id ?: DB::table('institucional.sedes')->orderBy('id_sede')->value('id_sede'));
        $planComercial = DB::table('membresias.planes')->where('codigo', 'PLAN-8')->first()
            ?: DB::table('membresias.planes')->where('activo', true)->where('tipo_producto', 'MEMBRESIA')->first();
        $modalidad = DB::table('membresias.plan_modalidades')->where('plan_id', $planComercial->id)->where('activo', true)->orderByDesc('dias_por_semana')->first();
        $precio = (float) ($modalidad?->precio_base ?: $planComercial->precio_base);
        $entrenador = DB::table('entrenamiento.entrenadores')->where('estado', 'ACTIVO')->where('usuario_id', '<>', $deportista->usuario_id)->orderBy('id')->first()
            ?: DB::table('entrenamiento.entrenadores')->where('estado', 'ACTIVO')->first();
        $operadorId = (int) DB::table('seguridad.users')->where('usr_estado', 1)->orderBy('id')->value('id');

        DB::table('clientes.deportistas')->where('id', $deportista->id)->update(['sede_principal_id' => $sedeId, 'estado' => 'ACTIVO', 'updated_at' => now()]);
        DB::table('clientes.fichas_iniciales')->updateOrInsert(['deportista_id' => $deportista->id], [
            'registrado_por' => $operadorId, 'estado' => 'COMPLETADA',
            'antecedentes' => json_encode(['observacion' => 'Sin antecedentes relevantes declarados.']),
            'evaluacion' => json_encode(['nivel' => 'INTERMEDIO', 'movilidad' => 'ADECUADA']),
            'objetivos' => json_encode(['principal' => 'Rendimiento y preparación deportiva']),
            'valoracion' => json_encode(['apto_para_actividad' => true]),
            'completado_at' => $inicio->setTime(10, 0), 'created_at' => $inicio, 'updated_at' => $inicio,
        ]);

        $membresiaId = $this->upsertId('membresias.membresias', ['codigo_contrato' => 'REV-MEM-2026-2001'], [
            'deportista_id' => $deportista->id, 'plan_id' => $planComercial->id, 'fecha_inicio' => $inicio->toDateString(),
            'fecha_fin' => $fin->toDateString(), 'estado' => 'ACTIVA', 'dias_gracia' => 5, 'renovacion_automatica' => false,
            'sede_id' => $sedeId, 'precio_aplicado' => $precio, 'entrenador_id' => $entrenador?->id,
            'modalidad_id' => $modalidad?->id, 'generar_venta_automatica' => false, 'cantidad_periodos_contratados' => 1,
            'condiciones_contratadas' => json_encode(['origen' => 'CARGA_INICIAL', 'precio' => $precio, 'modalidad' => $modalidad?->nombre]),
            'created_at' => $inicio, 'updated_at' => $inicio,
        ]);
        DB::table('membresias.membresia_sedes')->updateOrInsert(['membresia_id' => $membresiaId, 'sede_id' => $sedeId], ['es_principal' => true, 'activo' => true, 'created_at' => $inicio, 'updated_at' => $inicio]);

        $ventaId = $this->upsertId('ventas.ventas', ['numero' => 'REV-VTA-2026-2001'], [
            'cliente_id' => $deportista->id, 'membresia_id' => $membresiaId, 'usuario_id' => $operadorId,
            'tipo_venta' => 'MEMBRESIA', 'concepto' => 'Membresía '.$planComercial->nombre, 'subtotal' => $precio,
            'descuento' => 0, 'impuesto' => 0, 'total' => $precio, 'estado' => 'PAGADA',
            'fecha_venta' => $inicio->setTime(10, 30), 'observaciones' => 'Venta de membresía registrada.',
            'origen_tipo' => 'MEMBRESIA', 'origen_id' => $membresiaId, 'generado_por_tipo' => 'USUARIO',
            'responsable_comercial_id' => $operadorId, 'created_at' => $inicio, 'updated_at' => $inicio,
        ]);
        DB::table('ventas.venta_detalles')->updateOrInsert(['venta_id' => $ventaId, 'tipo_item' => 'MEMBRESIA'], [
            'referencia_id' => $membresiaId, 'descripcion' => 'Membresía '.$planComercial->nombre,
            'cantidad' => 1, 'precio_unitario' => $precio, 'total_linea' => $precio, 'created_at' => $inicio, 'updated_at' => $inicio,
        ]);
        DB::table('membresias.membresia_periodos')->updateOrInsert(['membresia_id' => $membresiaId, 'numero_periodo' => 1], [
            'fecha_inicio' => $inicio->toDateString(), 'fecha_fin' => $fin->toDateString(), 'precio' => $precio,
            'estado' => 'ACTIVA', 'venta_id' => $ventaId, 'generado_at' => $inicio, 'descuento_importe' => 0,
            'renovacion_manual' => false, 'created_at' => $inicio, 'updated_at' => $inicio,
        ]);
        DB::table('ventas.pagos')->updateOrInsert(['numero_comprobante' => 'REV-PAG-2026-2001'], [
            'venta_id' => $ventaId, 'usuario_id' => $operadorId, 'metodo_pago' => 'TRANSFERENCIA', 'monto' => $precio,
            'estado' => 'CONFIRMADO', 'fecha_pago' => $inicio->addDay()->setTime(9, 0), 'referencia' => 'Pago de membresía',
            'observaciones' => 'Pago confirmado.', 'operacion_cobro_id' => 'REV-OP-2026-2001', 'codigo_cobro' => 'REV-COB-2001',
            'created_at' => $inicio->addDay(), 'updated_at' => $inicio->addDay(),
        ]);
        $this->crearPaseDiario($deportista, 1, 'HOME', $operadorId);
        $this->crearPaseDiario($deportista, 2, 'XPADEL', $operadorId);

        if ($entrenador) {
            DB::table('entrenamiento.asignaciones_entrenador_cliente')->updateOrInsert(['membresia_id' => $membresiaId, 'deportista_id' => $deportista->id], [
                'entrenador_id' => $entrenador->id, 'tipo_asignacion' => 'SEGUIMIENTO', 'fecha_inicio' => '2026-10-03',
                'estado' => 'ACTIVO', 'observaciones' => 'Seguimiento deportivo asignado.', 'sede_id' => $sedeId,
                'created_at' => $inicio->addDays(2), 'updated_at' => $inicio->addDays(2),
            ]);
        }

        $planId = $this->upsertId('entrenamiento.planes', ['cliente_id' => $deportista->id, 'nombre' => 'Plan personalizado '.$deportista->codigo_deportista], [
            'entrenador_id' => $entrenador?->usuario_id, 'objetivo' => 'Rendimiento y preparación deportiva',
            'fecha_inicio' => '2026-10-04', 'fecha_fin' => '2026-11-30', 'estado' => 'ACTIVO',
            'observaciones' => 'Plan individual activo.', 'created_at' => $inicio->addDays(3), 'updated_at' => $inicio->addDays(3),
        ]);
        $ejercicios = DB::table('entrenamiento.ejercicios')->where('activo', true)->orderBy('id')->get();
        foreach ($ejercicios as $pos => $ejercicio) {
            $reps = 5 + ($pos % 4);
            $peso = 28 + ($pos * 3);
            $rm = round($peso * (1 + $reps / 30), 2);
            DB::table('entrenamiento.rm_registros')->updateOrInsert(['cliente_id' => $deportista->id, 'ejercicio_id' => $ejercicio->id, 'fecha_registro' => '2026-10-05'], [
                'tipo_registro' => 'ESTIMADO', 'peso' => $peso, 'repeticiones' => $reps, 'rm_estimado' => $rm,
                'observaciones' => 'RM estimada mediante fórmula de Epley.', 'created_at' => $inicio->addDays(4), 'updated_at' => $inicio->addDays(4),
            ]);
            if ($pos < 6) {
                $pesoSeguimiento = $peso + 3;
                DB::table('entrenamiento.rm_registros')->updateOrInsert(['cliente_id' => $deportista->id, 'ejercicio_id' => $ejercicio->id, 'fecha_registro' => '2026-10-28'], [
                    'tipo_registro' => 'ESTIMADO', 'peso' => $pesoSeguimiento, 'repeticiones' => $reps,
                    'rm_estimado' => round($pesoSeguimiento * (1 + $reps / 30), 2),
                    'observaciones' => 'Control mensual de fuerza.', 'created_at' => CarbonImmutable::create(2026, 10, 28), 'updated_at' => CarbonImmutable::create(2026, 10, 28),
                ]);
            }
        }
        $this->crearProgresoCorporal($deportista->id);

        $dias = ['LUNES', 'MIERCOLES', 'VIERNES'];
        for ($semana = 1; $semana <= 4; $semana++) {
            foreach ($dias as $d => $dia) {
                $fecha = CarbonImmutable::create(2026, 10, 5)->addWeeks($semana - 1)->addDays($d * 2);
                $sesionId = $this->upsertId('entrenamiento.sesiones', ['cliente_id' => $deportista->id, 'plan_id' => $planId, 'semana' => $semana, 'dia' => $dia], [
                    'nombre' => 'Entrenamiento '.$dia, 'fecha_programada' => $fecha->toDateString(), 'iniciado_at' => $fecha->setTime(18, 0),
                    'finalizado_at' => $fecha->setTime(19, 5), 'estado' => 'COMPLETADA', 'duracion_segundos' => 3900,
                    'rpe_sesion' => 6 + ($semana / 2), 'energia' => 4, 'dolor' => 1, 'observaciones' => 'Sesión completada.',
                    'created_at' => $fecha, 'updated_at' => $fecha,
                ]);
                for ($orden = 1; $orden <= 4; $orden++) {
                    $ejercicio = $ejercicios[(($semana - 1) * 3 + $d + $orden - 1) % $ejercicios->count()];
                    $series = 3 + ($semana >= 3 ? 1 : 0);
                    $porcentaje = 50 + ($semana * 5);
                    $reps = 12 - $semana;
                    $rutinaId = $this->upsertId('entrenamiento.rutinas', ['plan_id' => $planId, 'semana' => $semana, 'dia' => $dia, 'orden' => $orden], [
                        'ejercicio_id' => $ejercicio->id, 'bloque' => 'Progresión semana '.$semana, 'series' => $series,
                        'repeticiones' => (string) $reps, 'carga_objetivo' => $porcentaje, 'tipo_carga' => 'PORCENTAJE_RM',
                        'descanso_segundos' => 60 + $semana * 15, 'notas' => 'Progresión mensual planificada.',
                        'created_at' => $inicio, 'updated_at' => $inicio,
                    ]);
                    $seId = $this->upsertId('entrenamiento.sesiones_ejercicios', ['sesion_id' => $sesionId, 'orden' => $orden], [
                        'rutina_id' => $rutinaId, 'ejercicio_id' => $ejercicio->id, 'series_objetivo' => $series,
                        'repeticiones_objetivo' => (string) $reps, 'carga_objetivo' => $porcentaje, 'tipo_carga' => 'PORCENTAJE_RM',
                        'descanso_objetivo_segundos' => 60 + $semana * 15, 'bloque' => 'Fuerza e hipertrofia',
                        'notas' => 'Ejecución controlada.', 'created_at' => $fecha, 'updated_at' => $fecha,
                    ]);
                    for ($serie = 1; $serie <= $series; $serie++) {
                        DB::table('entrenamiento.series_ejecutadas')->updateOrInsert(['sesion_ejercicio_id' => $seId, 'numero_serie' => $serie], [
                            'peso_kg' => 20 + $semana * 3 + $orden, 'repeticiones' => $reps, 'rpe' => 6 + $semana / 2,
                            'rir' => max(1, 4 - $semana), 'descanso_segundos' => 60 + $semana * 15, 'completada' => true,
                            'observaciones' => 'Serie completada.', 'registrado_at' => $fecha->setTime(18, 10 + $orden * 10),
                            'created_at' => $fecha, 'updated_at' => $fecha,
                        ]);
                    }
                }
            }
        }

        return [
            'membresías activas' => DB::table('membresias.membresias')->where('deportista_id', $deportista->id)->where('estado', 'ACTIVA')->count(),
            'registros RM' => DB::table('entrenamiento.rm_registros')->where('cliente_id', $deportista->id)->count(),
            'ejercicios de rutina' => DB::table('entrenamiento.rutinas')->where('plan_id', $planId)->count(),
            'sesiones completadas' => DB::table('entrenamiento.sesiones')->where('cliente_id', $deportista->id)->where('estado', 'COMPLETADA')->count(),
            'series ejecutadas' => DB::table('entrenamiento.series_ejecutadas as serie')->join('entrenamiento.sesiones_ejercicios as se', 'se.id', '=', 'serie.sesion_ejercicio_id')->join('entrenamiento.sesiones as s', 's.id', '=', 'se.sesion_id')->where('s.cliente_id', $deportista->id)->count(),
        ];
    }

    private function crearPaseDiario(object $deportista, int $sedeId, string $sufijo, int $operadorId): void
    {
        $plan = DB::table('membresias.planes')->where('codigo', 'PLAN-3')->first();
        $precio = (float) (DB::table('membresias.plan_precios_sede')->where('plan_id', $plan->id)->where('sede_id', $sedeId)->where('activo', true)->value('precio') ?: $plan->precio_base);
        $fecha = CarbonImmutable::create(2026, 11, 2);
        $membresiaId = $this->upsertId('membresias.membresias', ['codigo_contrato' => 'REV-PASE-'.$sufijo.'-20261102'], [
            'deportista_id' => $deportista->id, 'plan_id' => $plan->id, 'fecha_inicio' => $fecha->toDateString(),
            'fecha_fin' => $fecha->toDateString(), 'estado' => 'ACTIVA', 'dias_gracia' => 0, 'renovacion_automatica' => false,
            'sede_id' => $sedeId, 'precio_aplicado' => $precio, 'generar_venta_automatica' => false,
            'cantidad_periodos_contratados' => 1, 'condiciones_contratadas' => json_encode(['origen' => 'CARGA_INICIAL', 'sede_id' => $sedeId, 'precio' => $precio]),
            'created_at' => $fecha->setTime(7, 30), 'updated_at' => $fecha->setTime(7, 30),
        ]);
        DB::table('membresias.membresia_sedes')->updateOrInsert(['membresia_id' => $membresiaId, 'sede_id' => $sedeId], ['es_principal' => true, 'activo' => true, 'created_at' => $fecha, 'updated_at' => $fecha]);
        $ventaId = $this->upsertId('ventas.ventas', ['numero' => 'REV-VTA-PASE-'.$sufijo.'-20261102'], [
            'cliente_id' => $deportista->id, 'membresia_id' => $membresiaId, 'usuario_id' => $operadorId,
            'tipo_venta' => 'PASE_DIARIO', 'concepto' => 'Pase diario '.$sufijo, 'subtotal' => $precio, 'descuento' => 0,
            'impuesto' => 0, 'total' => $precio, 'estado' => 'PAGADA', 'fecha_venta' => $fecha->setTime(7, 30),
            'observaciones' => 'Pase diario registrado.', 'origen_tipo' => 'MEMBRESIA', 'origen_id' => $membresiaId,
            'generado_por_tipo' => 'USUARIO', 'responsable_comercial_id' => $operadorId, 'created_at' => $fecha, 'updated_at' => $fecha,
        ]);
        DB::table('ventas.venta_detalles')->updateOrInsert(['venta_id' => $ventaId, 'tipo_item' => 'PASE_DIARIO'], [
            'referencia_id' => $membresiaId, 'descripcion' => 'Pase diario '.$sufijo, 'cantidad' => 1,
            'precio_unitario' => $precio, 'total_linea' => $precio, 'created_at' => $fecha, 'updated_at' => $fecha,
        ]);
        DB::table('membresias.membresia_periodos')->updateOrInsert(['membresia_id' => $membresiaId, 'numero_periodo' => 1], [
            'fecha_inicio' => $fecha->toDateString(), 'fecha_fin' => $fecha->toDateString(), 'precio' => $precio,
            'estado' => 'ACTIVA', 'venta_id' => $ventaId, 'generado_at' => $fecha, 'descuento_importe' => 0,
            'renovacion_manual' => false, 'created_at' => $fecha, 'updated_at' => $fecha,
        ]);
        DB::table('ventas.pagos')->updateOrInsert(['numero_comprobante' => 'REV-PAG-PASE-'.$sufijo.'-20261102'], [
            'venta_id' => $ventaId, 'usuario_id' => $operadorId, 'metodo_pago' => 'EFECTIVO', 'monto' => $precio,
            'estado' => 'CONFIRMADO', 'fecha_pago' => $fecha->setTime(7, 35), 'referencia' => 'Pago de pase diario',
            'observaciones' => 'Pago confirmado.', 'operacion_cobro_id' => 'REV-OP-PASE-'.$sufijo.'-20261102',
            'codigo_cobro' => 'REV-COB-PASE-'.$sufijo, 'created_at' => $fecha, 'updated_at' => $fecha,
        ]);
    }

    private function crearProgresoCorporal(int $deportistaId): void
    {
        $controles = [
            ['2026-10-04', 82.4, 178, 91.0, 23.5],
            ['2026-10-11', 81.9, 178, 89.8, 22.9],
            ['2026-10-20', 81.2, 178, 88.9, 22.2],
            ['2026-10-31', 80.6, 178, 87.8, 21.6],
        ];
        foreach ($controles as [$fecha, $peso, $talla, $cintura, $grasa]) {
            DB::table('entrenamiento.progresos_corporales')->updateOrInsert(['cliente_id' => $deportistaId, 'fecha_registro' => $fecha], [
                'peso_kg' => $peso, 'talla_cm' => $talla, 'cintura_cm' => $cintura, 'grasa_corporal_pct' => $grasa,
                'masa_magra_kg' => round($peso * (1 - $grasa / 100), 2), 'imc' => round($peso / (($talla / 100) ** 2), 2),
                'objetivo' => 'Ganancia muscular y fuerza general', 'observaciones' => 'Control mensual de progreso.',
                'created_at' => CarbonImmutable::parse($fecha), 'updated_at' => CarbonImmutable::parse($fecha),
            ]);
        }
    }

    private function upsertId(string $tabla, array $clave, array $datos): int
    {
        $id = DB::table($tabla)->where($clave)->value('id');
        if ($id) {
            DB::table($tabla)->where('id', $id)->update($datos);

            return (int) $id;
        }

        return (int) DB::table($tabla)->insertGetId(array_merge($clave, $datos));
    }
}
