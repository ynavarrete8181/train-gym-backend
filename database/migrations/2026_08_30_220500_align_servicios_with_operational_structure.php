<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE gimnasio.servicios ADD COLUMN IF NOT EXISTS linea_servicio_id BIGINT NULL');
        DB::statement('ALTER TABLE gimnasio.servicios ALTER COLUMN categoria_id DROP NOT NULL');

        DB::statement(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'gimnasio_servicios_linea_servicio_id_foreign'
    ) THEN
        ALTER TABLE gimnasio.servicios
            ADD CONSTRAINT gimnasio_servicios_linea_servicio_id_foreign
            FOREIGN KEY (linea_servicio_id)
            REFERENCES institucional.carreras_areas(id_carrera_area)
            ON DELETE RESTRICT;
    END IF;
END $$;
SQL);

        DB::table('gimnasio.servicios')
            ->whereNull('linea_servicio_id')
            ->update(['updated_at' => Carbon::now()]);

        $menuEstructura = DB::table('seguridad.cpu_usermenu')->where('menu', 'Estructura operativa')->value('id_usermenu');
        if ($menuEstructura) {
            foreach (['seguridad.cpu_userrolefunction', 'seguridad.cpu_userfunction'] as $tabla) {
                DB::table($tabla)
                    ->where('id_menu', 'GIMNASIO-HORARIOS')
                    ->update([
                        'id_usermenu' => $menuEstructura,
                        'orden' => 5,
                        'updated_at' => Carbon::now(),
                    ]);
            }
        }

        foreach (['seguridad.cpu_userrolefunction', 'seguridad.cpu_userfunction'] as $tabla) {
            DB::table($tabla)
                ->where('id_menu', 'GIMNASIO-CATEGORIAS-SERVICIO')
                ->update(['activo' => false, 'updated_at' => Carbon::now()]);

            DB::table($tabla)
                ->where('id_menu', 'GIMNASIO-SERVICIOS')
                ->update(['orden' => 1, 'updated_at' => Carbon::now()]);

            DB::table($tabla)
                ->where('id_menu', 'GIMNASIO-RESERVAS-DIA')
                ->update(['orden' => 2, 'updated_at' => Carbon::now()]);
        }
    }

    public function down(): void
    {
        $menuServicios = DB::table('seguridad.cpu_usermenu')->where('menu', 'Servicios y Agenda')->value('id_usermenu');
        if ($menuServicios) {
            foreach (['seguridad.cpu_userrolefunction', 'seguridad.cpu_userfunction'] as $tabla) {
                DB::table($tabla)
                    ->where('id_menu', 'GIMNASIO-HORARIOS')
                    ->update([
                        'id_usermenu' => $menuServicios,
                        'orden' => 3,
                        'updated_at' => Carbon::now(),
                    ]);
            }
        }

        foreach (['seguridad.cpu_userrolefunction', 'seguridad.cpu_userfunction'] as $tabla) {
            DB::table($tabla)
                ->where('id_menu', 'GIMNASIO-CATEGORIAS-SERVICIO')
                ->update(['activo' => true, 'updated_at' => Carbon::now()]);

            DB::table($tabla)
                ->where('id_menu', 'GIMNASIO-SERVICIOS')
                ->update(['orden' => 2, 'updated_at' => Carbon::now()]);

            DB::table($tabla)
                ->where('id_menu', 'GIMNASIO-RESERVAS-DIA')
                ->update(['orden' => 4, 'updated_at' => Carbon::now()]);
        }

        DB::statement('ALTER TABLE gimnasio.servicios DROP CONSTRAINT IF EXISTS gimnasio_servicios_linea_servicio_id_foreign');
        DB::statement('ALTER TABLE gimnasio.servicios DROP COLUMN IF EXISTS linea_servicio_id');
    }
};
