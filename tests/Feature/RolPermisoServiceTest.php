<?php

namespace Tests\Feature;

use App\Services\Seguridad\RolPermisoService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RolPermisoServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement("ATTACH DATABASE ':memory:' AS seguridad");
        DB::statement('CREATE TABLE seguridad.users (id INTEGER PRIMARY KEY, usr_tipo INTEGER, usr_estado INTEGER)');
        DB::statement('CREATE TABLE seguridad.cpu_userrole (id_userrole INTEGER PRIMARY KEY, role TEXT, activo INTEGER, created_at TEXT, updated_at TEXT)');
        DB::statement('CREATE TABLE seguridad.cpu_usermenu (id_usermenu INTEGER PRIMARY KEY, menu TEXT, icono TEXT, activo INTEGER, orden INTEGER, created_at TEXT, updated_at TEXT)');
        DB::statement('CREATE TABLE seguridad.cpu_userrolefunction (id_userrf INTEGER PRIMARY KEY, id_userrole INTEGER, id_usermenu INTEGER, nombre TEXT, icono TEXT, accion TEXT, id_menu TEXT, activo INTEGER, orden INTEGER, created_at TEXT, updated_at TEXT)');
        DB::statement('CREATE TABLE seguridad.cpu_userfunction (id_userfunction INTEGER PRIMARY KEY AUTOINCREMENT, id_users INTEGER, id_userrole INTEGER, id_usermenu INTEGER, nombre TEXT, icono TEXT, accion TEXT, id_menu TEXT, activo INTEGER, orden INTEGER, created_at TEXT, updated_at TEXT, UNIQUE(id_users, id_menu))');
        DB::statement('CREATE TABLE seguridad.tokens_acceso (id INTEGER PRIMARY KEY AUTOINCREMENT, id_usuario INTEGER)');
    }

    public function test_desactivar_un_menu_no_cambia_el_estado_individual_de_sus_funciones(): void
    {
        DB::table('seguridad.cpu_usermenu')->insert([
            'id_usermenu' => 1,
            'menu' => 'Administracion',
            'activo' => true,
            'orden' => 1,
        ]);
        DB::table('seguridad.cpu_userrolefunction')->insert([
            'id_userrf' => 1,
            'id_userrole' => 1,
            'id_usermenu' => 1,
            'nombre' => 'Usuarios',
            'accion' => 'seguridad/usuarios',
            'id_menu' => 'SEGURIDAD-USUARIOS',
            'activo' => true,
            'orden' => 1,
        ]);
        DB::table('seguridad.cpu_userfunction')->insert([
            'id_users' => 1,
            'id_userrole' => 1,
            'id_usermenu' => 1,
            'nombre' => 'Usuarios',
            'accion' => 'seguridad/usuarios',
            'id_menu' => 'SEGURIDAD-USUARIOS',
            'activo' => true,
            'orden' => 1,
        ]);

        (new RolPermisoService)->cambiarEstadoMenu(1, false);

        $this->assertSame(0, (int) DB::table('seguridad.cpu_usermenu')->where('id_usermenu', 1)->value('activo'));
        $this->assertSame(1, (int) DB::table('seguridad.cpu_userrolefunction')->where('id_userrf', 1)->value('activo'));
        $this->assertSame(1, (int) DB::table('seguridad.cpu_userfunction')->where('id_userfunction', 1)->value('activo'));
    }

    public function test_editar_el_codigo_de_una_funcion_elimina_el_permiso_anterior_y_sincroniza_el_nuevo(): void
    {
        DB::table('seguridad.users')->insert(['id' => 1, 'usr_tipo' => 1, 'usr_estado' => 1]);
        DB::table('seguridad.cpu_userrole')->insert(['id_userrole' => 1, 'role' => 'ADMINISTRADOR', 'activo' => true]);
        DB::table('seguridad.cpu_usermenu')->insert(['id_usermenu' => 1, 'menu' => 'Administracion', 'activo' => true, 'orden' => 1]);
        DB::table('seguridad.cpu_userrolefunction')->insert([
            'id_userrf' => 1,
            'id_userrole' => 1,
            'id_usermenu' => 1,
            'nombre' => 'Usuarios',
            'accion' => 'seguridad/usuarios',
            'id_menu' => 'CODIGO-ANTERIOR',
            'activo' => true,
            'orden' => 1,
        ]);
        DB::table('seguridad.cpu_userfunction')->insert([
            'id_users' => 1,
            'id_userrole' => 1,
            'id_usermenu' => 1,
            'nombre' => 'Usuarios',
            'accion' => 'seguridad/usuarios',
            'id_menu' => 'CODIGO-ANTERIOR',
            'activo' => true,
            'orden' => 1,
        ]);

        (new RolPermisoService)->guardarFuncionRol([
            'id_userrole' => 1,
            'id_usermenu' => 1,
            'nombre' => 'Usuarios',
            'icono' => 'manage_accounts',
            'accion' => 'seguridad/usuarios',
            'id_menu' => 'CODIGO-NUEVO',
            'activo' => true,
            'orden' => 1,
        ], 1);

        $this->assertFalse(DB::table('seguridad.cpu_userfunction')->where('id_menu', 'CODIGO-ANTERIOR')->exists());
        $this->assertTrue(DB::table('seguridad.cpu_userfunction')->where('id_menu', 'CODIGO-NUEVO')->where('activo', true)->exists());
    }
}
