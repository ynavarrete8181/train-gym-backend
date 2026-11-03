<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Schedule;
use App\Services\Gimnasio\CobroProgramadoMembresiaServicio;
use App\Services\Gimnasio\ActualizarEstadosMembresiaServicio;
use App\Services\Gimnasio\CicloVidaMembresiaServicio;
use App\Services\Gimnasio\DiagnosticoConfiguracionInicialServicio;
use App\Services\Alertas\AlertaOperativaServicio;
use App\Services\Logs\LogRetencionServicio;
use App\Services\Ventas\CierreAutomaticoCajaServicio;
use App\Services\Ventas\SincronizarVentasMembresiasServicio;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('revive:reset-passwords-prueba', function (): int {
    if (! app()->environment(['local', 'testing'])) {
        $this->error('Este comando solo puede ejecutarse en entornos local o testing.');
        return 1;
    }

    $total = DB::table('seguridad.users')->count();

    if ($total === 0) {
        $this->info('No existen usuarios para actualizar.');
        return 0;
    }

    DB::transaction(function (): void {
        DB::table('seguridad.users')->update([
            'password' => Hash::make('123456'),
            'updated_at' => now(),
        ]);

        // Obliga a iniciar sesión nuevamente con la contraseña de prueba.
        DB::table('seguridad.tokens_acceso')->delete();
    });

    $this->warn("Contraseña de prueba aplicada a {$total} usuario(s).");
    $this->info('Clave temporal de pruebas: 123456');
    $this->info('Todos los tokens de acceso fueron invalidados.');

    return 0;
})->purpose('Restablece temporalmente la contraseña de todos los usuarios a 123456 solo en local/testing.');

Artisan::command('revive:limpiar-usuarios-prueba-carga', function (): int {
    if (DB::getDriverName() !== 'pgsql') {
        $this->error('Este comando de limpieza está preparado para PostgreSQL.');
        return 1;
    }

    $patron = 'carga.masiva.%@example.com';
    $usuarios = DB::table('seguridad.users')->where('email', 'like', $patron)->get(['id', 'email']);

    if ($usuarios->isEmpty()) {
        $this->info('No existen usuarios de prueba de carga masiva para eliminar.');
        return 0;
    }

    $this->warn("Se eliminarán {$usuarios->count()} usuario(s) de prueba y sus relaciones asociadas.");

    DB::transaction(function () use ($usuarios, $patron): void {
        $idsCargas = DB::table('seguridad.carga_masiva_usuario_detalles')
            ->whereRaw("datos->>'email' LIKE ?", [$patron])
            ->pluck('carga_id')
            ->unique()
            ->values();

        if ($idsCargas->isNotEmpty()) {
            if (Schema::hasTable('seguridad.avisos_usuario')) {
                DB::table('seguridad.avisos_usuario')
                    ->where('referencia_tipo', 'CARGA_MASIVA_USUARIOS')
                    ->whereIn('referencia_id', $idsCargas)
                    ->delete();
            }

            DB::table('seguridad.cargas_masivas_usuario')->whereIn('id', $idsCargas)->delete();
        }

        DB::table('seguridad.users')->whereIn('id', $usuarios->pluck('id'))->delete();
    });

    $this->info("Limpieza completada: {$usuarios->count()} usuario(s) de prueba eliminados.");
    return 0;
})->purpose('Elimina únicamente usuarios carga.masiva.*@example.com, sus lotes y avisos de prueba.');


Artisan::command('revive:procesar-congelaciones-programadas', function (CicloVidaMembresiaServicio $servicio): int {
    $resultado = $servicio->procesarProgramadas();
    $this->info(
        "Vacaciones programadas revisadas: {$resultado['programadas_revisadas']} | "
        . "activadas: {$resultado['activadas']} | "
        . "no aplicadas: {$resultado['no_aplicadas']} | "
        . "reactivadas: {$resultado['reactivadas']}"
    );
    return 0;
})->purpose('Activa vacaciones en su fecha y restablece la membresía al día siguiente del fin previsto.');

Schedule::command('revive:procesar-congelaciones-programadas')
    ->dailyAt('00:02')
    ->timezone('America/Guayaquil')
    ->withoutOverlapping();

// Activar las suspensiones ANTES de los cobros recurrentes del día.
Artisan::command('revive:procesar-cobros-membresias {--fecha=}', function (CobroProgramadoMembresiaServicio $servicio): int {
    $resultado = $servicio->procesar($this->option('fecha') ?: null);

    $this->info(
        "Cobros procesados: {$resultado['procesadas']} | "
        . "ventas creadas: {$resultado['ventas_creadas']} | "
        . "omitidas: {$resultado['omitidas']} | "
        . "fecha: {$resultado['fecha']}"
    );

    return 0;
})->purpose('Genera ventas pendientes de membresías cuando llega su fecha programada de cobro.');

Schedule::command('revive:procesar-cobros-membresias')
    ->dailyAt('00:05')
    ->timezone('America/Guayaquil')
    ->withoutOverlapping();

Artisan::command('revive:actualizar-estados-membresias {--fecha=}', function (ActualizarEstadosMembresiaServicio $servicio): int {
    $resultado = $servicio->procesar($this->option('fecha') ?: null);

    $this->info(
        "Membresías revisadas: {$resultado['procesadas']} | "
        . "vencidas: {$resultado['vencidas']} | "
        . "asignaciones finalizadas: {$resultado['asignaciones_finalizadas']} | "
        . "fecha: {$resultado['fecha']}"
    );

    return 0;
})->purpose('Vence membresías fuera de vigencia y finaliza sus asignaciones de entrenador.');

Schedule::command('revive:actualizar-estados-membresias')
    ->dailyAt('00:15')
    ->timezone('America/Guayaquil')
    ->withoutOverlapping();

Artisan::command('revive:diagnosticar-configuracion-inicial', function (DiagnosticoConfiguracionInicialServicio $servicio): int {
    $resultado = $servicio->ejecutar();

    $this->table(['Control', 'Pendientes'], collect($resultado)
        ->map(fn ($cantidad, $control) => [$control, $cantidad])
        ->values()
        ->all());

    return collect($resultado)->sum() > 0 ? 2 : 0;
})->purpose('Audita inconsistencias entre planes, entrenadores, membresías, pagos y asignaciones.');


Artisan::command('revive:sincronizar-ventas-membresias {--membresia=}', function (SincronizarVentasMembresiasServicio $servicio): int {
    $membresiaId = $this->option('membresia') ? (int) $this->option('membresia') : null;

    if (! $membresiaId) {
        $this->error('Indica una membresía específica con --membresia=ID. Este comando es solo de reparación y no genera ventas masivamente.');
        return 1;
    }

    $resultado = $servicio->procesar($membresiaId);

    $this->info(
        "Membresías detectadas: {$resultado['detectadas']} | "
        . "ventas creadas: {$resultado['ventas_creadas']} | "
        . "omitidas: {$resultado['omitidas']} | "
        . "errores: " . count($resultado['errores'])
    );

    foreach ($resultado['errores'] as $error) {
        $this->error("Membresía {$error['membresia_id']}: {$error['error']}");
    }

    return empty($resultado['errores']) ? 0 : 1;
})->purpose('Repara de forma explícita la venta faltante de una membresía específica.');


Artisan::command('revive:cerrar-turnos-caja-vencidos {--fecha=}', function (CierreAutomaticoCajaServicio $servicio): int {
    $resultado = $servicio->procesar($this->option('fecha') ?: null);

    $this->info(
        "Turnos detectados: {$resultado['turnos_detectados']} | "
        . "cerrados: {$resultado['turnos_cerrados']} | "
        . "destinatarios notificados: {$resultado['destinatarios_notificados']} | "
        . "fecha: {$resultado['fecha']}"
    );

    return 0;
})->purpose('Cierra automáticamente turnos de caja de días anteriores y deja el arqueo pendiente de conciliación.');

Schedule::command('revive:cerrar-turnos-caja-vencidos')
    ->dailyAt('00:01')
    ->withoutOverlapping();


Artisan::command('revive:limpiar-logs', function (LogRetencionServicio $servicio): int {
    $resultado = $servicio->limpiar();

    $this->info(
        "Logs eliminados: {$resultado['total']} | "
        . "INFO: {$resultado['eventos_info']} | "
        . "WARNING: {$resultado['eventos_warning']} | "
        . "ERROR: {$resultado['eventos_error']} | "
        . "Excepciones: {$resultado['excepciones']} | "
        . "Integraciones OK: {$resultado['integraciones_ok']} | "
        . "Integraciones error: {$resultado['integraciones_error']}"
    );

    return 0;
})->purpose('Aplica la política de retención de logs técnicos. Nunca elimina auditoría funcional.');

Schedule::command('revive:limpiar-logs')
    ->dailyAt('02:30')
    ->withoutOverlapping();


Artisan::command('revive:procesar-alertas-operativas', function (AlertaOperativaServicio $servicio): int {
    $resultado = $servicio->procesar();

    $this->info(
        "Alertas detectadas: {$resultado['detectadas']} | "
        . "resueltas: {$resultado['resueltas']}"
    );

    foreach ($resultado['tipos'] as $tipo => $cantidad) {
        $this->line("{$tipo}: {$cantidad}");
    }

    return 0;
})->purpose('Evalúa condiciones operativas y actualiza las alertas de Revive.');

Schedule::command('revive:procesar-alertas-operativas')
    ->hourly()
    ->withoutOverlapping();


/**
 * Auditoría no destructiva de la identidad unificada.
 * No altera registros, perfiles, cuentas ni permisos.
 */
Artisan::command('revive:auditar-identidades', function (): int {
    $resumen = [
        'Personas' => DB::table('personas.personas')->count(),
        'Clientes' => DB::table('clientes.deportistas')->count(),
        'Usuarios' => DB::table('seguridad.users')->count(),
        'Entrenadores' => DB::table('entrenamiento.entrenadores')->count(),
    ];

    $hallazgos = [
        'Documentos repetidos (normalizados)' => DB::table('personas.personas')
            ->selectRaw('UPPER(TRIM(identificacion)) AS documento')
            ->whereNotNull('identificacion')
            ->whereRaw("TRIM(identificacion) <> ''")
            ->groupByRaw('UPPER(TRIM(identificacion))')
            ->havingRaw('COUNT(*) > 1')
            ->get()->count(),
        'Clientes con persona inexistente' => DB::table('clientes.deportistas as d')
            ->leftJoin('personas.personas as p', 'p.id', '=', 'd.persona_id')
            ->whereNull('p.id')->count(),
        'Usuarios con persona inexistente' => DB::table('seguridad.users as u')
            ->leftJoin('personas.personas as p', 'p.id', '=', 'u.persona_id')
            ->whereNull('p.id')->count(),
        'Clientes con usuario de otra persona' => DB::table('clientes.deportistas as d')
            ->join('seguridad.users as u', 'u.id', '=', 'd.usuario_id')
            ->whereColumn('u.persona_id', '<>', 'd.persona_id')->count(),
        'Personas con más de un perfil de cliente' => DB::table('clientes.deportistas')
            ->whereNotNull('persona_id')
            ->select('persona_id')->groupBy('persona_id')
            ->havingRaw('COUNT(*) > 1')->get()->count(),
        'Personas con más de una cuenta' => DB::table('seguridad.users')
            ->whereNotNull('persona_id')
            ->select('persona_id')->groupBy('persona_id')
            ->havingRaw('COUNT(*) > 1')->get()->count(),
        'Entrenadores con usuario inexistente' => DB::table('entrenamiento.entrenadores as e')
            ->leftJoin('seguridad.users as u', 'u.id', '=', 'e.usuario_id')
            ->whereNull('u.id')->count(),
    ];

    $this->info('REVIVE: inventario de identidades');
    $this->table(['Entidad', 'Registros'], collect($resumen)->map(fn ($v, $k) => [$k, $v])->values()->all());
    $this->info('Inconsistencias detectadas (solo lectura)');
    $this->table(['Regla', 'Cantidad'], collect($hallazgos)->map(fn ($v, $k) => [$k, $v])->values()->all());

    $total = array_sum($hallazgos);
    $this->{$total ? 'warn' : 'info'}($total
        ? 'Existen inconsistencias por revisar; no se modificó ningún dato.'
        : 'No se detectaron inconsistencias con las reglas comprobadas.');

    return $total ? 1 : 0;
})->purpose('Audita Personas, Clientes, Usuarios y Entrenadores sin modificar datos.');

/**
 * Registra únicamente los catálogos del ciclo de vida JUNTO a «Planes» sin crear otro
 * menú principal. La gestión individual se realiza exclusivamente en Clientes → Membresías.
 * El menú padre se resuelve por la función GIMNASIO-PLANES.
 * Sin --aplicar solo muestra el plan. No otorga nuevas capacidades.
 */
Artisan::command('revive:configurar-submenus-membresias {--aplicar}', function (
    \App\Services\Seguridad\RolPermisoService $roles,
    \App\Services\Seguridad\PaginaSistemaService $paginas
): int {
    $referencia = DB::table('seguridad.cpu_userrolefunction')
        ->where('id_menu', 'GIMNASIO-PLANES')->where('activo', true)
        ->orderBy('id_userrf')->first();

    if (! $referencia) {
        $this->error('No se encontró el submenú Planes (GIMNASIO-PLANES). No se realizaron cambios.');
        return 1;
    }

    $menu = DB::table('seguridad.cpu_usermenu')
        ->where('id_usermenu', $referencia->id_usermenu)
        ->where('activo', true)->first();

    if (! $menu) {
        $this->error('El menú padre de Planes no está activo.');
        return 1;
    }

    $opciones = [
        ['codigo' => 'GIMNASIO-MOTIVOS-CICLO-VIDA', 'nombre' => 'Motivos del ciclo de vida',
            'pagina' => 'MotivosCicloVidaPage', 'requisito' => 'GIMNASIO-PLANES'],
    ];
    $permisos = DB::table('seguridad.cpu_userrolefunction')
        ->whereIn('id_menu', ['GIMNASIO-MEMBRESIAS', 'GIMNASIO-PLANES'])
        ->where('activo', true)->get(['id_userrole', 'id_menu'])
        ->groupBy('id_userrole');

    $pendientes = [];
    foreach ($permisos as $idRol => $funciones) {
        $disponibles = $funciones->pluck('id_menu')->all();
        foreach ($opciones as $opcion) {
            if (! in_array($opcion['requisito'], $disponibles, true)) continue;
            $actual = DB::table('seguridad.cpu_userrolefunction')
                ->where('id_userrole', $idRol)->where('id_menu', $opcion['codigo'])->first();
            if ($actual && $actual->activo && (int) $actual->id_usermenu === (int) $menu->id_usermenu) continue;
            $pendientes[] = ['rol' => (int) $idRol, 'opcion' => $opcion, 'actual' => $actual];
        }
    }

    $planes = DB::table('seguridad.cpu_userrolefunction')
        ->where('id_menu', 'GIMNASIO-PLANES')->where('activo', true)->get();
    $renombrar = $planes->filter(fn ($plan) => $plan->nombre !== 'Planes');
    $this->info('Menú padre existente: ' . $menu->menu . ' (#' . $menu->id_usermenu . ')');
    $this->info('Submenús nuevos/por reubicar: ' . count($pendientes)
        . ' | Etiquetas «Planes» por actualizar: ' . $renombrar->count());

    if (! $this->option('aplicar')) {
        $this->line('Solo simulación. Revisa la navegación antes de ejecutar --aplicar en desarrollo.');
        return 0;
    }

    foreach ($planes as $plan) {
        if ($plan->nombre === 'Planes') continue;
        $roles->guardarFuncionRol([
            'id_userrole' => $plan->id_userrole,
            'id_usermenu' => $plan->id_usermenu,
            'nombre' => 'Planes', 'icono' => $plan->icono,
            'accion' => $plan->accion, 'id_menu' => 'GIMNASIO-PLANES',
            'activo' => true, 'orden' => $plan->orden,
        ], (int) $plan->id_userrf);
    }

    foreach ($pendientes as $item) {
        $opcion = $item['opcion'];
        $actual = $item['actual'];
        $orden = $actual && (int) $actual->id_usermenu === (int) $menu->id_usermenu
            ? $actual->orden
            : ((int) DB::table('seguridad.cpu_userrolefunction')
                ->where('id_userrole', $item['rol'])
                ->where('id_usermenu', $menu->id_usermenu)->max('orden')) + 1;
        $roles->guardarFuncionRol([
            'id_userrole' => $item['rol'],
            'id_usermenu' => $menu->id_usermenu,
            'nombre' => $opcion['nombre'],
            'icono' => 'CardMembership',
            'accion' => 'membresias/' . strtolower(str_replace('GIMNASIO-', '', $opcion['codigo'])),
            'id_menu' => $opcion['codigo'],
            'activo' => true, 'orden' => $orden,
        ], $actual ? (int) $actual->id_userrf : null);
    }

    foreach ($opciones as $opcion) {
        if (DB::table('seguridad.cpu_userrolefunction')
            ->where('id_menu', $opcion['codigo'])->where('activo', true)->exists()) {
            $paginas->asociar($opcion['codigo'], $opcion['pagina']);
        }
    }
    $this->info('Navegación actualizada. Conserva el menú padre y muestra «Planes».');
    return 0;
})->purpose('Configura catálogos del ciclo de vida junto a Planes; la gestión individual permanece en Clientes.');
