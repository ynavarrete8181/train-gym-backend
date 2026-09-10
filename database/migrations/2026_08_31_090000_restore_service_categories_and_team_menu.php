<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();

        $this->ordenarMenusPrincipales($now);
        $this->restaurarServiciosAgenda($now);
        $this->moverEntrenadoresAEquipo($now);
    }

    public function down(): void
    {
        $now = Carbon::now();
        $menuOperaciones = DB::table('seguridad.cpu_usermenu')->where('menu', 'Operaciones')->value('id_usermenu');
        $menuEstructura = DB::table('seguridad.cpu_usermenu')->where('menu', 'Estructura operativa')->value('id_usermenu');
        $menuServicios = DB::table('seguridad.cpu_usermenu')->where('menu', 'Servicios y Agenda')->value('id_usermenu');

        DB::table('seguridad.cpu_usermenu')
            ->where('menu', 'Operaciones')
            ->update(['activo' => true, 'orden' => 3, 'updated_at' => $now]);

        DB::table('seguridad.cpu_usermenu')
            ->where('menu', 'Equipo')
            ->update(['activo' => false, 'updated_at' => $now]);

        if ($menuOperaciones) {
            $this->actualizarFuncion('GIMNASIO-ENTRENADORES', $menuOperaciones, 4, true, $now);
        }

        if ($menuEstructura) {
            $this->actualizarFuncion('GIMNASIO-HORARIOS', $menuEstructura, 5, true, $now);
        }

        if ($menuServicios) {
            $this->actualizarFuncion('GIMNASIO-CATEGORIAS-SERVICIO', $menuServicios, 1, false, $now);
            $this->actualizarFuncion('GIMNASIO-SERVICIOS', $menuServicios, 1, true, $now);
            $this->actualizarFuncion('GIMNASIO-RESERVAS-DIA', $menuServicios, 2, true, $now);
        }
    }

    private function ordenarMenusPrincipales(Carbon $now): void
    {
        $menus = [
            'Seguridad' => ['orden' => 1, 'activo' => true],
            'Estructura operativa' => ['orden' => 2, 'activo' => true],
            'Equipo' => ['orden' => 3, 'activo' => true],
            'Operaciones' => ['orden' => 99, 'activo' => false],
            'Integraciones' => ['orden' => 4, 'activo' => true],
            'Notificaciones' => ['orden' => 5, 'activo' => true],
            'Clientes' => ['orden' => 6, 'activo' => true],
            'Membresías' => ['orden' => 7, 'activo' => true],
            'Servicios y Agenda' => ['orden' => 8, 'activo' => true],
            'Entrenamiento' => ['orden' => 9, 'activo' => true],
            'Inventario' => ['orden' => 10, 'activo' => true],
            'Ventas' => ['orden' => 11, 'activo' => true],
            'Acceso' => ['orden' => 12, 'activo' => true],
            'Resultados' => ['orden' => 13, 'activo' => true],
            'Comunicaciones' => ['orden' => 14, 'activo' => true],
            'Reportes' => ['orden' => 15, 'activo' => true],
        ];

        foreach ($menus as $menu => $datos) {
            DB::table('seguridad.cpu_usermenu')
                ->where('menu', $menu)
                ->update([
                    'orden' => $datos['orden'],
                    'activo' => $datos['activo'],
                    'updated_at' => $now,
                ]);
        }
    }

    private function restaurarServiciosAgenda(Carbon $now): void
    {
        $menuServicios = DB::table('seguridad.cpu_usermenu')->where('menu', 'Servicios y Agenda')->value('id_usermenu');
        if (! $menuServicios) {
            return;
        }

        $funciones = [
            'GIMNASIO-CATEGORIAS-SERVICIO' => ['nombre' => 'Categorías', 'icono' => 'category', 'accion' => '/categoria-servicio', 'orden' => 1],
            'GIMNASIO-SERVICIOS' => ['nombre' => 'Servicios', 'icono' => 'fitness_center', 'accion' => '/servicios', 'orden' => 2],
            'GIMNASIO-HORARIOS' => ['nombre' => 'Horarios', 'icono' => 'schedule', 'accion' => '/horarios', 'orden' => 3],
            'GIMNASIO-RESERVAS-DIA' => ['nombre' => 'Reservas del Día', 'icono' => 'event_available', 'accion' => '/reservas-dia', 'orden' => 4],
        ];

        foreach ($funciones as $codigo => $datos) {
            $this->actualizarFuncion($codigo, $menuServicios, $datos['orden'], true, $now, $datos);
        }
    }

    private function moverEntrenadoresAEquipo(Carbon $now): void
    {
        $menuEquipo = DB::table('seguridad.cpu_usermenu')->where('menu', 'Equipo')->value('id_usermenu');
        if (! $menuEquipo) {
            return;
        }

        $this->actualizarFuncion('GIMNASIO-ENTRENADORES', $menuEquipo, 1, true, $now, [
            'nombre' => 'Entrenadores',
            'icono' => 'groups',
            'accion' => '/entrenadores',
        ]);
    }

    private function actualizarFuncion(string $codigo, int $menuId, int $orden, bool $activo, Carbon $now, array $datos = []): void
    {
        foreach (['seguridad.cpu_userrolefunction', 'seguridad.cpu_userfunction'] as $tabla) {
            $actualizacion = [
                'id_usermenu' => $menuId,
                'orden' => $orden,
                'activo' => $activo,
                'updated_at' => $now,
            ];

            foreach (['nombre', 'icono', 'accion'] as $campo) {
                if (array_key_exists($campo, $datos)) {
                    $actualizacion[$campo] = $datos[$campo];
                }
            }

            DB::table($tabla)->where('id_menu', $codigo)->update($actualizacion);
        }
    }
};
