<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Seguridad\PermisoService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SuperadministradorPermisosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement("ATTACH DATABASE ':memory:' AS seguridad");
        DB::statement('CREATE TABLE seguridad.cpu_userrole (id_userrole INTEGER PRIMARY KEY, role TEXT, activo INTEGER)');
        DB::statement('CREATE TABLE seguridad.cpu_userfunction (id INTEGER PRIMARY KEY, id_users INTEGER, id_menu TEXT, activo INTEGER)');
    }

    public function test_superadministrador_activo_accede_sin_permisos_personales(): void
    {
        DB::table('seguridad.cpu_userrole')->insert([
            'id_userrole' => 1, 'role' => 'SUPERADMINISTRADOR', 'activo' => true,
        ]);

        $usuario = new User;
        $usuario->id = 1;
        $usuario->usr_tipo = 1;

        $this->assertTrue(app(PermisoService::class)->usuarioTieneAlgunaFuncion(
            $usuario, ['GIMNASIO-DEPORTISTAS'],
        ));
    }

    public function test_superadministrador_inactivo_no_omite_permisos(): void
    {
        DB::table('seguridad.cpu_userrole')->insert([
            'id_userrole' => 1, 'role' => 'SUPERADMINISTRADOR', 'activo' => false,
        ]);

        $usuario = new User;
        $usuario->id = 1;
        $usuario->usr_tipo = 1;

        $this->assertFalse(app(PermisoService::class)->usuarioTieneAlgunaFuncion(
            $usuario, ['GIMNASIO-DEPORTISTAS'],
        ));
    }

    public function test_otros_roles_sin_permisos_siguen_restringidos(): void
    {
        DB::table('seguridad.cpu_userrole')->insert([
            'id_userrole' => 2, 'role' => 'ENTRENADOR', 'activo' => true,
        ]);

        $usuario = new User;
        $usuario->id = 2;
        $usuario->usr_tipo = 2;

        $this->assertFalse(app(PermisoService::class)->usuarioTieneAlgunaFuncion(
            $usuario, ['GIMNASIO-DEPORTISTAS'],
        ));
    }

    public function test_administrador_activo_puede_operar_clientes_y_entrenamiento(): void
    {
        DB::table('seguridad.cpu_userrole')->insert([
            'id_userrole' => 3, 'role' => 'ADMINISTRADOR', 'activo' => true,
        ]);

        $usuario = new User;
        $usuario->id = 3;
        $usuario->usr_tipo = 3;

        $servicio = app(PermisoService::class);
        $this->assertTrue($servicio->usuarioTieneAlgunaFuncion($usuario, ['GIMNASIO-DEPORTISTAS']));
        $this->assertTrue($servicio->usuarioTieneAlgunaFuncion($usuario, ['ENTRENAMIENTO-EVALUACIONES-CREAR']));
        $this->assertFalse($servicio->usuarioTieneAlgunaFuncion($usuario, ['SEGURIDAD-ROLES']));
    }

    public function test_administrador_inactivo_no_obtiene_acceso_operativo_global(): void
    {
        DB::table('seguridad.cpu_userrole')->insert([
            'id_userrole' => 3, 'role' => 'ADMINISTRADOR', 'activo' => false,
        ]);

        $usuario = new User;
        $usuario->id = 3;
        $usuario->usr_tipo = 3;

        $this->assertFalse(app(PermisoService::class)->usuarioTieneAlgunaFuncion(
            $usuario, ['GIMNASIO-DEPORTISTAS'],
        ));
    }
}
