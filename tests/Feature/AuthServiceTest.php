<?php

namespace Tests\Feature;

use App\Services\Seguridad\AuthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement("ATTACH DATABASE ':memory:' AS seguridad");
        DB::statement('CREATE TABLE seguridad.users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, password TEXT, usr_tipo INTEGER, usr_estado INTEGER, created_at TEXT, updated_at TEXT)');
        DB::statement('CREATE TABLE seguridad.cpu_userrole (id_userrole INTEGER PRIMARY KEY, role TEXT, activo INTEGER)');
        DB::statement('CREATE TABLE seguridad.tokens_acceso (id INTEGER PRIMARY KEY AUTOINCREMENT, id_usuario INTEGER, nombre TEXT, token_hash TEXT, ultimo_uso_en TEXT, expira_en TEXT, created_at TEXT, updated_at TEXT)');
    }

    public function test_no_permite_iniciar_sesion_si_el_rol_esta_inactivo(): void
    {
        DB::table('seguridad.cpu_userrole')->insert([
            'id_userrole' => 1,
            'role' => 'USUARIO',
            'activo' => false,
        ]);
        DB::table('seguridad.users')->insert([
            'id' => 1,
            'name' => 'Usuario de prueba',
            'email' => 'usuario@base.local',
            'password' => Hash::make('ClaveSegura123*'),
            'usr_tipo' => 1,
            'usr_estado' => 1,
        ]);

        $resultado = (new AuthService)->intentarLogin('usuario@base.local', 'ClaveSegura123*');

        $this->assertNull($resultado);
        $this->assertSame(0, DB::table('seguridad.tokens_acceso')->count());
    }
}
