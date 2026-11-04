<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class GenerarDemoTfmOctubre2026 extends Command
{
    protected $signature = 'revive:demo-tfm-octubre-2026
        {--dry-run : Analiza prerrequisitos sin escribir}
        {--pilot : Limita la carga a tres deportistas}
        {--confirm : Confirma expresamente la escritura}';

    protected $description = 'Genera el escenario histórico DEMO/TFM de octubre de 2026 de forma determinista e idempotente.';

    private const MARCA = 'DEMO-TFM-OCT-2026';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Carga rechazada: solo se permite en local o testing.');

            return self::FAILURE;
        }

        if (DB::connection()->getDatabaseName() !== 'db_gimnasio') {
            $this->error('Carga rechazada: la base no es db_gimnasio.');

            return self::FAILURE;
        }

        $total = $this->option('pilot') ? 3 : 30;
        $diagnostico = $this->diagnosticar($total);
        $this->table(['Comprobación', 'Resultado'], collect($diagnostico)->map(fn ($v, $k) => [$k, $v])->all());

        if ($this->option('dry-run')) {
            $this->info("Dry-run correcto: se prepararían {$total} deportistas DEMO/TFM.");

            return self::SUCCESS;
        }

        if (! $this->option('confirm')) {
            $this->error('Para escribir debes añadir --confirm.');

            return self::FAILURE;
        }

        $resultado = DB::transaction(fn () => $this->generar($total), 3);
        $this->newLine();
        $this->table(['Entidad', 'Total DEMO'], collect($resultado)->map(fn ($v, $k) => [$k, $v])->all());
        $this->info('Carga DEMO/TFM terminada. Una segunda ejecución no duplica los agregados.');

        return self::SUCCESS;
    }

    private function diagnosticar(int $total): array
    {
        $rol = DB::table('seguridad.cpu_userrole')->where('role', 'DEPORTISTA')->value('id_userrole');
        $planes = DB::table('membresias.planes')->where('activo', true)->where('tipo_producto', 'MEMBRESIA')->count();
        $ejercicios = DB::table('entrenamiento.ejercicios')->where('activo', true)->count();
        $entrenadores = DB::table('entrenamiento.entrenadores')->where('estado', 'ACTIVO')->count();
        if (! $rol || ! $planes || $ejercicios < 3 || ! $entrenadores) {
            throw new \RuntimeException('Faltan catálogos obligatorios para construir el escenario DEMO.');
        }

        return [
            'entorno' => app()->environment(),
            'base' => DB::connection()->getDatabaseName(),
            'deportistas previstos' => $total,
            'planes vigentes' => $planes,
            'entrenadores activos' => $entrenadores,
            'ejercicios activos' => $ejercicios,
            'registros DEMO existentes' => DB::table('clientes.deportistas')->where('codigo_deportista', 'like', 'TFM26-%')->count(),
        ];
    }

    private function generar(int $total): array
    {
        mt_srand(20261002);
        $rolId = (int) DB::table('seguridad.cpu_userrole')->where('role', 'DEPORTISTA')->value('id_userrole');
        $sedeIds = DB::table('institucional.sedes')->orderBy('id_sede')->pluck('id_sede')->map(fn ($v) => (int) $v)->all();
        $planes = DB::table('membresias.planes')->where('activo', true)->where('tipo_producto', 'MEMBRESIA')->orderBy('id')->get();
        $modalidades = DB::table('membresias.plan_modalidades')->where('activo', true)->get()->groupBy('plan_id');
        $entrenadores = DB::table('entrenamiento.entrenadores')->where('estado', 'ACTIVO')->orderBy('id')->get();
        $ejercicios = DB::table('entrenamiento.ejercicios')->where('activo', true)->orderBy('id')->get();
        $operadorId = (int) DB::table('seguridad.users')->where('usr_estado', 1)->orderBy('id')->value('id');

        $nombres = ['Alejandra', 'Bruno', 'Camila', 'Diego', 'Elena', 'Felipe', 'Gabriela', 'Hugo', 'Isabela', 'Joaquín', 'Karen', 'Leonardo', 'Mariana', 'Nicolás', 'Olivia', 'Pablo', 'Renata', 'Samuel', 'Tamara', 'Uriel', 'Valentina', 'Walter', 'Ximena', 'Yago', 'Zoe', 'Adrián', 'Bianca', 'Cristian', 'Diana', 'Esteban'];
        $apellidos = ['Alcívar Mero', 'Baque Cedeño', 'Cantos Vélez', 'Delgado Zambrano', 'Espinales Moreira', 'Figueroa Loor', 'García Macías', 'Hidalgo Palma', 'Intriago Vera', 'Jaramillo Pico'];
        $objetivos = ['Ganancia muscular', 'Acondicionamiento físico', 'Resistencia', 'Fuerza general', 'Actividad física inicial', 'Mantenimiento'];

        for ($i = 1; $i <= $total; $i++) {
            $codigo = sprintf('TFM26-%03d', $i);
            $email = sprintf('tfm.oct26.%03d@demo.revive.local', $i);
            $nombre = $nombres[$i - 1];
            $apellido = $apellidos[($i - 1) % count($apellidos)];
            $fechaAlta = CarbonImmutable::create(2026, 10, 1)->addDays(($i * 7) % 28)->setTime(9 + ($i % 9), ($i * 11) % 60);
            $sedeId = $sedeIds[($i - 1) % count($sedeIds)];
            $plan = $planes[($i - 1) % $planes->count()];
            $modalidad = ($modalidades[$plan->id] ?? collect())->values()->get(($i - 1) % max(1, ($modalidades[$plan->id] ?? collect())->count()));
            $precio = (float) ($modalidad?->precio_base ?: $plan->precio_base);
            $entrenador = $entrenadores[($i - 1) % $entrenadores->count()];

            $personaId = $this->upsertId('personas.personas', ['identificacion' => sprintf('TFM261%05d', $i)], [
                'tipo_identificacion' => 'PASAPORTE', 'nombres' => $nombre, 'apellidos' => $apellido,
                'nombre_completo' => "{$nombre} {$apellido}", 'fecha_nacimiento' => CarbonImmutable::create(1988 + ($i % 15), 1 + ($i % 12), 1 + ($i % 25))->toDateString(),
                'genero' => $i % 2 ? 'FEMENINO' : 'MASCULINO', 'telefono' => sprintf('09926%05d', $i),
                'email' => $email, 'direccion' => 'Dirección sintética '.self::MARCA, 'activo' => true,
                'created_at' => $fechaAlta, 'updated_at' => $fechaAlta,
            ]);

            $usuarioId = $this->upsertId('seguridad.users', ['email' => $email], [
                'name' => "{$nombre} {$apellido}", 'password' => Hash::make(sprintf('ReviveTFM#%03dAa!', $i)), 'email_verified_at' => $fechaAlta,
                'cedula' => sprintf('TFM261%05d', $i), 'usr_tipo' => $rolId, 'usr_estado' => 1,
                'nombres' => $nombre, 'apellidos' => $apellido, 'persona_id' => $personaId,
                'created_at' => $fechaAlta, 'updated_at' => $fechaAlta,
            ]);

            $deportistaId = $this->upsertId('clientes.deportistas', ['codigo_deportista' => $codigo], [
                'usuario_id' => $usuarioId, 'persona_id' => $personaId, 'fecha_nacimiento' => CarbonImmutable::create(1988 + ($i % 15), 1 + ($i % 12), 1 + ($i % 25))->toDateString(),
                'genero' => $i % 2 ? 'FEMENINO' : 'MASCULINO', 'telefono' => sprintf('09926%05d', $i),
                'contacto_emergencia_nombre' => 'Contacto sintético DEMO', 'contacto_emergencia_telefono' => sprintf('09826%05d', $i),
                'observaciones_medicas' => 'Sin diagnóstico médico. Registro '.self::MARCA, 'sede_principal_id' => $sedeId,
                'estado' => 'ACTIVO', 'requiere_representante_legal' => false, 'created_at' => $fechaAlta, 'updated_at' => $fechaAlta,
            ]);
            $this->sincronizarPermisosUsuario($usuarioId, $rolId);

            DB::table('clientes.fichas_iniciales')->updateOrInsert(['deportista_id' => $deportistaId], [
                'registrado_por' => $operadorId, 'estado' => 'COMPLETADA',
                'antecedentes' => json_encode(['demo' => true, 'nota' => self::MARCA]),
                'evaluacion' => json_encode(['nivel' => $i % 3 ? 'INTERMEDIO' : 'INICIAL']),
                'objetivos' => json_encode(['principal' => $objetivos[($i - 1) % count($objetivos)]]),
                'valoracion' => json_encode(['apto_para_actividad' => true, 'origen' => self::MARCA]),
                'completado_at' => $fechaAlta->addDay(), 'created_at' => $fechaAlta, 'updated_at' => $fechaAlta->addDay(),
            ]);

            $inicio = $fechaAlta->addDay()->startOfDay();
            $estadoMembresia = $total <= 3
                ? ($i === 3 ? 'PENDIENTE_PAGO' : 'ACTIVA')
                : ($i > $total - 3 ? 'PENDIENTE_PAGO' : ($i > $total - 6 ? 'VENCIDA' : 'ACTIVA'));
            $fin = $estadoMembresia === 'VENCIDA'
                ? CarbonImmutable::create(2026, 10, 28)
                : CarbonImmutable::create(2026, 11, 30);
            $membresiaId = $this->upsertId('membresias.membresias', ['codigo_contrato' => sprintf('DEMO-TFM-2026-%03d', $i)], [
                'deportista_id' => $deportistaId, 'plan_id' => $plan->id, 'fecha_inicio' => $inicio->toDateString(), 'fecha_fin' => $fin->toDateString(),
                'estado' => $estadoMembresia, 'dias_gracia' => 0, 'renovacion_automatica' => false, 'sede_id' => $sedeId,
                'precio_aplicado' => $precio, 'entrenador_id' => $entrenador->id, 'modalidad_id' => $modalidad?->id,
                'generar_venta_automatica' => false, 'cantidad_periodos_contratados' => 1,
                'condiciones_contratadas' => json_encode(['demo' => true, 'marca' => self::MARCA, 'precio' => $precio]),
                'created_at' => $inicio, 'updated_at' => $inicio,
            ]);
            DB::table('membresias.membresia_sedes')->updateOrInsert(['membresia_id' => $membresiaId, 'sede_id' => $sedeId], ['es_principal' => true, 'activo' => true, 'created_at' => $inicio, 'updated_at' => $inicio]);
            DB::table('entrenamiento.asignaciones_entrenador_cliente')->updateOrInsert(
                ['membresia_id' => $membresiaId, 'deportista_id' => $deportistaId],
                [
                    'entrenador_id' => $entrenador->id, 'tipo_asignacion' => 'SEGUIMIENTO',
                    'fecha_inicio' => $inicio->addDays(3)->toDateString(),
                    'fecha_fin' => $estadoMembresia === 'VENCIDA' ? $fin->toDateString() : null,
                    'estado' => $estadoMembresia === 'VENCIDA' ? 'FINALIZADO' : 'ACTIVO',
                    'observaciones' => self::MARCA, 'sede_id' => $sedeId,
                    'created_at' => $inicio->addDays(3), 'updated_at' => $inicio->addDays(3),
                ]
            );

            $numeroVenta = sprintf('DEMO-TFM-V-%03d', $i);
            $ventaId = $this->upsertId('ventas.ventas', ['numero' => $numeroVenta], [
                'cliente_id' => $deportistaId, 'membresia_id' => $membresiaId, 'usuario_id' => $operadorId,
                'tipo_venta' => 'MEMBRESIA', 'concepto' => self::MARCA.' / '.$plan->nombre, 'subtotal' => $precio,
                'descuento' => $i % 7 === 0 ? round($precio * .1, 2) : 0, 'impuesto' => 0,
                'total' => $i % 7 === 0 ? round($precio * .9, 2) : $precio,
                'estado' => $estadoMembresia === 'PENDIENTE_PAGO' ? 'PENDIENTE' : 'PAGADA', 'fecha_venta' => $inicio,
                'observaciones' => self::MARCA, 'origen_tipo' => 'MEMBRESIA', 'origen_id' => $membresiaId,
                'generado_por_tipo' => 'SISTEMA', 'responsable_comercial_id' => $operadorId, 'created_at' => $inicio, 'updated_at' => $inicio,
            ]);
            DB::table('ventas.venta_detalles')->updateOrInsert(['venta_id' => $ventaId, 'tipo_item' => 'MEMBRESIA'], [
                'producto_id' => null, 'referencia_id' => $membresiaId, 'descripcion' => self::MARCA.' / '.$plan->nombre,
                'cantidad' => 1, 'precio_unitario' => $precio, 'total_linea' => $i % 7 === 0 ? round($precio * .9, 2) : $precio,
                'created_at' => $inicio, 'updated_at' => $inicio,
            ]);
            DB::table('membresias.membresia_periodos')->updateOrInsert(['membresia_id' => $membresiaId, 'numero_periodo' => 1], [
                'fecha_inicio' => $inicio->toDateString(), 'fecha_fin' => $fin->toDateString(), 'precio' => $precio,
                'estado' => $estadoMembresia, 'venta_id' => $ventaId, 'generado_at' => $inicio,
                'descuento_importe' => $i % 7 === 0 ? round($precio * .1, 2) : 0, 'renovacion_manual' => false,
                'created_at' => $inicio, 'updated_at' => $inicio,
            ]);

            if ($estadoMembresia !== 'PENDIENTE_PAGO') {
                DB::table('ventas.pagos')->updateOrInsert(['numero_comprobante' => sprintf('DEMO-TFM-P-%03d', $i)], [
                    'venta_id' => $ventaId, 'usuario_id' => $operadorId, 'metodo_pago' => $i % 3 === 0 ? 'TRANSFERENCIA' : 'EFECTIVO',
                    'monto' => $i % 7 === 0 ? round($precio * .9, 2) : $precio, 'estado' => 'CONFIRMADO',
                    'fecha_pago' => $inicio->addDay()->setTime(10, 0), 'referencia' => self::MARCA, 'observaciones' => 'Pago interno no tributario DEMO',
                    'operacion_cobro_id' => sprintf('DEMO-TFM-OP-%03d', $i), 'codigo_cobro' => sprintf('DTFM-%03d', $i),
                    'created_at' => $inicio->addDay(), 'updated_at' => $inicio->addDay(),
                ]);
            }

            $planEntrenoId = $this->upsertId('entrenamiento.planes', ['cliente_id' => $deportistaId, 'nombre' => self::MARCA], [
                'entrenador_id' => $entrenador->usuario_id, 'objetivo' => $objetivos[($i - 1) % count($objetivos)],
                'fecha_inicio' => $inicio->addDays(4)->toDateString(), 'fecha_fin' => '2026-11-30', 'estado' => 'ACTIVO',
                'observaciones' => self::MARCA, 'created_at' => $inicio->addDays(4), 'updated_at' => $inicio->addDays(4),
            ]);
            $this->generarRegistrosRm($deportistaId, $ejercicios, $inicio, $i);
            $this->generarRutinaYSesiones($deportistaId, $planEntrenoId, $ejercicios, $inicio, $i);
            $this->generarProgreso($deportistaId, $inicio, $i, $objetivos[($i - 1) % count($objetivos)]);
        }

        return $this->resumen();
    }

    private function generarRutinaYSesiones(int $deportistaId, int $planId, $ejercicios, CarbonImmutable $inicio, int $indice): void
    {
        $dias = ['LUNES', 'MIERCOLES', 'VIERNES'];
        for ($semana = 1; $semana <= 4; $semana++) {
            foreach ($dias as $d => $dia) {
                $rutinas = [];
                for ($e = 0; $e < 3; $e++) {
                    $ejercicio = $ejercicios[($indice + $d + $e) % $ejercicios->count()];
                    $rutinaId = $this->upsertId('entrenamiento.rutinas', ['plan_id' => $planId, 'semana' => $semana, 'dia' => $dia, 'orden' => $e + 1], [
                        'ejercicio_id' => $ejercicio->id, 'bloque' => 'Progresión semana '.$semana, 'series' => 2 + min($semana, 2),
                        'repeticiones' => (12 - $semana).'-'.(14 - $semana), 'carga_objetivo' => 50 + ($semana * 5),
                        'tipo_carga' => 'PORCENTAJE_RM', 'descanso_segundos' => 60 + ($semana * 15),
                        'notas' => self::MARCA, 'created_at' => $inicio, 'updated_at' => $inicio,
                    ]);
                    $rutinas[] = [$rutinaId, $ejercicio->id, 2 + min($semana, 2), 12 - $semana, 60 + ($semana * 15)];
                }
                $fecha = CarbonImmutable::create(2026, 10, 5)->addWeeks($semana - 1)->addDays($d * 2);
                $completada = $fecha->day <= (18 + ($indice % 10));
                $sesionId = $this->upsertId('entrenamiento.sesiones', ['cliente_id' => $deportistaId, 'plan_id' => $planId, 'semana' => $semana, 'dia' => $dia], [
                    'nombre' => self::MARCA.' / '.$dia, 'fecha_programada' => $fecha->toDateString(),
                    'iniciado_at' => $completada ? $fecha->setTime(18, 0) : null, 'finalizado_at' => $completada ? $fecha->setTime(19, 0) : null,
                    'estado' => $completada ? 'COMPLETADA' : 'PROGRAMADA', 'duracion_segundos' => $completada ? 3600 : null,
                    'rpe_sesion' => $completada ? 6 + ($semana / 2) : null, 'energia' => $completada ? 4 : null, 'dolor' => $completada ? 1 : null,
                    'observaciones' => self::MARCA, 'created_at' => $fecha, 'updated_at' => $fecha,
                ]);
                foreach ($rutinas as $orden => [$rutinaId, $ejercicioId, $series, $reps, $descanso]) {
                    $seId = $this->upsertId('entrenamiento.sesiones_ejercicios', ['sesion_id' => $sesionId, 'orden' => $orden + 1], [
                        'rutina_id' => $rutinaId, 'ejercicio_id' => $ejercicioId, 'series_objetivo' => $series,
                        'repeticiones_objetivo' => (string) $reps, 'carga_objetivo' => 50 + ($semana * 5), 'tipo_carga' => 'PORCENTAJE_RM',
                        'descanso_objetivo_segundos' => $descanso, 'bloque' => 'DEMO', 'notas' => self::MARCA,
                        'created_at' => $fecha, 'updated_at' => $fecha,
                    ]);
                    if ($completada) {
                        for ($serie = 1; $serie <= $series; $serie++) {
                            DB::table('entrenamiento.series_ejecutadas')->updateOrInsert(['sesion_ejercicio_id' => $seId, 'numero_serie' => $serie], [
                                'peso_kg' => 15 + $semana * 2 + $orden, 'repeticiones' => $reps, 'rpe' => 6 + $semana / 2,
                                'rir' => max(1, 4 - $semana), 'descanso_segundos' => $descanso, 'completada' => true,
                                'observaciones' => self::MARCA, 'registrado_at' => $fecha->setTime(18, 20 + $orden * 10),
                                'created_at' => $fecha, 'updated_at' => $fecha,
                            ]);
                        }
                    }
                }
            }
        }
    }

    private function generarRegistrosRm(int $deportistaId, $ejercicios, CarbonImmutable $inicio, int $indice): void
    {
        foreach ($ejercicios as $posicion => $ejercicio) {
            $repeticiones = 5 + (($indice + $posicion) % 4);
            $peso = 18 + ($indice % 12) + ($posicion * 2);
            $rmEstimado = round($peso * (1 + ($repeticiones / 30)), 2);
            DB::table('entrenamiento.rm_registros')->updateOrInsert(
                [
                    'cliente_id' => $deportistaId,
                    'ejercicio_id' => $ejercicio->id,
                    'fecha_registro' => $inicio->addDays(5)->toDateString(),
                ],
                [
                    'tipo_registro' => 'ESTIMADO', 'peso' => $peso, 'repeticiones' => $repeticiones,
                    'rm_estimado' => $rmEstimado, 'observaciones' => self::MARCA,
                    'created_at' => $inicio->addDays(5), 'updated_at' => $inicio->addDays(5),
                ]
            );
        }
    }

    private function sincronizarPermisosUsuario(int $usuarioId, int $rolId): void
    {
        foreach (DB::table('seguridad.cpu_userrolefunction')->where('id_userrole', $rolId)->where('activo', true)->get() as $funcion) {
            DB::table('seguridad.cpu_userfunction')->updateOrInsert(
                ['id_users' => $usuarioId, 'id_menu' => $funcion->id_menu],
                [
                    'id_userrole' => $rolId, 'id_usermenu' => $funcion->id_usermenu,
                    'nombre' => $funcion->nombre, 'icono' => $funcion->icono,
                    'accion' => $funcion->accion, 'activo' => true, 'orden' => $funcion->orden,
                    'created_at' => now(), 'updated_at' => now(),
                ]
            );
        }
    }

    private function generarProgreso(int $deportistaId, CarbonImmutable $inicio, int $indice, string $objetivo): void
    {
        $peso = 58 + ($indice % 20);
        $talla = 158 + ($indice % 25);
        foreach ([0, 21] as $n => $dias) {
            $pesoActual = $peso - ($n * .6);
            $grasa = 18 + ($indice % 10) - ($n * .5);
            DB::table('entrenamiento.progresos_corporales')->updateOrInsert(['cliente_id' => $deportistaId, 'fecha_registro' => $inicio->addDays($dias)->toDateString()], [
                'peso_kg' => $pesoActual, 'talla_cm' => $talla, 'cintura_cm' => 72 + ($indice % 18) - $n,
                'grasa_corporal_pct' => $grasa, 'masa_magra_kg' => round($pesoActual * (1 - $grasa / 100), 2),
                'imc' => round($pesoActual / (($talla / 100) ** 2), 2), 'objetivo' => $objetivo,
                'observaciones' => self::MARCA, 'created_at' => $inicio->addDays($dias), 'updated_at' => $inicio->addDays($dias),
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

    private function resumen(): array
    {
        return [
            'deportistas' => DB::table('clientes.deportistas')->where('codigo_deportista', 'like', 'TFM26-%')->count(),
            'usuarios' => DB::table('seguridad.users')->where('email', 'like', 'tfm.oct26.%@demo.revive.local')->count(),
            'membresías' => DB::table('membresias.membresias')->where('codigo_contrato', 'like', 'DEMO-TFM-2026-%')->count(),
            'ventas' => DB::table('ventas.ventas')->where('numero', 'like', 'DEMO-TFM-V-%')->count(),
            'pagos' => DB::table('ventas.pagos')->where('numero_comprobante', 'like', 'DEMO-TFM-P-%')->count(),
            'planes entrenamiento' => DB::table('entrenamiento.planes')->where('nombre', self::MARCA)->count(),
            'rutinas' => DB::table('entrenamiento.rutinas')->where('notas', self::MARCA)->count(),
            'sesiones' => DB::table('entrenamiento.sesiones')->where('observaciones', self::MARCA)->count(),
            'progresos' => DB::table('entrenamiento.progresos_corporales')->where('observaciones', self::MARCA)->count(),
            'registros RM' => DB::table('entrenamiento.rm_registros')->where('observaciones', self::MARCA)->count(),
        ];
    }
}
