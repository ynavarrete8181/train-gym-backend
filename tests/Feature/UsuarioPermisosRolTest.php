<?php

namespace Tests\Feature;

use App\Services\Seguridad\UsuarioService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UsuarioPermisosRolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement("ATTACH DATABASE ':memory:' AS seguridad");
        DB::statement('CREATE TABLE seguridad.cpu_userrolefunction (id INTEGER PRIMARY KEY, id_userrole INTEGER, id_usermenu INTEGER, id_menu TEXT, nombre TEXT, icono TEXT, accion TEXT, activo INTEGER, orden INTEGER)');
        DB::statement('CREATE TABLE seguridad.cpu_userfunction (id INTEGER PRIMARY KEY, id_users INTEGER, id_userrole INTEGER, id_usermenu INTEGER, id_menu TEXT, nombre TEXT, icono TEXT, accion TEXT, activo INTEGER, orden INTEGER, created_at TEXT, updated_at TEXT)');
        DB::table('seguridad.cpu_userrolefunction')->insert([
            ['id_userrole' => 1, 'id_usermenu' => 1, 'id_menu' => 'VER', 'nombre' => 'Ver', 'activo' => 1, 'orden' => 1],
            ['id_userrole' => 2, 'id_usermenu' => 2, 'id_menu' => 'EDITAR', 'nombre' => 'Editar', 'activo' => 1, 'orden' => 2],
        ]);
    }

    public function test_no_asigna_funciones_de_otro_rol(): void
    {
        $this->expectException(ValidationException::class);
        app(UsuarioService::class)->guardarFuncionesUsuario(5, 1, ['EDITAR']);
    }

    public function test_no_asigna_funciones_inexistentes(): void
    {
        $this->expectException(ValidationException::class);
        app(UsuarioService::class)->guardarFuncionesUsuario(5, 1, ['INEXISTENTE']);
    }
}
