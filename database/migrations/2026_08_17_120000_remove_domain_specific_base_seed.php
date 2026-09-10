<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new \RuntimeException('Esta migración solo puede ejecutarse en PostgreSQL.');
        }

        DB::transaction(function (): void {
            $codigosDominio = ['ACADEMICO-PERIODOS', 'MATRICULAS', 'ASISTENCIA'];
            $rolesDominio = [
                'DIRECTOR DE NIVELACIÓN',
                'RESPONSABLE O SUPERVISOR',
                'DOCENTE',
                'SECRETARÍA',
                'ESTUDIANTE',
            ];

            DB::table('seguridad.cpu_userfunction')
                ->whereIn('id_menu', $codigosDominio)
                ->delete();

            DB::table('seguridad.cpu_userrolefunction')
                ->whereIn('id_menu', $codigosDominio)
                ->delete();

            $rolesSinUsuarios = DB::table('seguridad.cpu_userrole as rol')
                ->whereIn('rol.role', $rolesDominio)
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('seguridad.users as usuario')
                        ->whereColumn('usuario.usr_tipo', 'rol.id_userrole');
                })
                ->pluck('rol.id_userrole');

            if ($rolesSinUsuarios->isNotEmpty()) {
                DB::table('seguridad.cpu_userrolefunction')
                    ->whereIn('id_userrole', $rolesSinUsuarios)
                    ->delete();

                DB::table('seguridad.cpu_userrole')
                    ->whereIn('id_userrole', $rolesSinUsuarios)
                    ->delete();
            }

            $menuAcademico = DB::table('seguridad.cpu_usermenu')
                ->where('menu', 'Gestión de dominio')
                ->value('id_usermenu');

            if ($menuAcademico) {
                $tieneFuncionesRol = DB::table('seguridad.cpu_userrolefunction')
                    ->where('id_usermenu', $menuAcademico)
                    ->exists();
                $tieneFuncionesUsuario = DB::table('seguridad.cpu_userfunction')
                    ->where('id_usermenu', $menuAcademico)
                    ->exists();

                if (!$tieneFuncionesRol && !$tieneFuncionesUsuario) {
                    DB::table('seguridad.cpu_usermenu')
                        ->where('id_usermenu', $menuAcademico)
                        ->delete();
                }
            }
        });
    }

    public function down(): void
    {
        // Los datos eliminados eran ejemplos específicos de un dominio anterior y
        // no forman parte del núcleo reusable. No se recrean durante rollback.
    }
};
