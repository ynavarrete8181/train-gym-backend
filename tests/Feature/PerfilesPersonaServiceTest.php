<?php

namespace Tests\Feature;

use App\Services\Seguridad\PerfilesPersonaService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PerfilesPersonaServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['personas', 'clientes', 'seguridad', 'gimnasio'] as $schema) {
            DB::statement("ATTACH DATABASE ':memory:' AS {$schema}");
        }
        DB::statement('CREATE TABLE personas.personas (id INTEGER PRIMARY KEY)');
        DB::statement('CREATE TABLE clientes.deportistas (id INTEGER PRIMARY KEY, persona_id INTEGER)');
        DB::statement('CREATE TABLE seguridad.users (id INTEGER PRIMARY KEY, persona_id INTEGER, usr_tipo INTEGER)');
        DB::statement('CREATE TABLE seguridad.cpu_userrole (id_userrole INTEGER PRIMARY KEY, role TEXT)');
        DB::statement('CREATE TABLE gimnasio.entrenadores (id INTEGER PRIMARY KEY, usuario_id INTEGER)');
    }

    public function test_una_persona_puede_ser_cliente_y_entrenador_aunque_su_rol_principal_sea_administrador(): void
    {
        DB::table('personas.personas')->insert(['id' => 10]);
        DB::table('clientes.deportistas')->insert(['id' => 20, 'persona_id' => 10]);
        DB::table('seguridad.cpu_userrole')->insert(['id_userrole' => 3, 'role' => 'ADMINISTRADOR']);
        DB::table('seguridad.users')->insert(['id' => 30, 'persona_id' => 10, 'usr_tipo' => 3]);
        DB::table('gimnasio.entrenadores')->insert(['id' => 40, 'usuario_id' => 30]);

        $perfiles = app(PerfilesPersonaService::class)->consultar(10);

        $this->assertTrue($perfiles['existe']);
        $this->assertTrue($perfiles['cliente']);
        $this->assertTrue($perfiles['entrenador']);
        $this->assertSame(30, $perfiles['usuario_id']);
        $this->assertSame('ADMINISTRADOR', $perfiles['rol_principal']);
    }

    public function test_un_cliente_sin_cuenta_no_se_convierte_en_usuario(): void
    {
        DB::table('personas.personas')->insert(['id' => 11]);
        DB::table('clientes.deportistas')->insert(['id' => 21, 'persona_id' => 11]);

        $perfiles = app(PerfilesPersonaService::class)->consultar(11);

        $this->assertTrue($perfiles['cliente']);
        $this->assertFalse($perfiles['entrenador']);
        $this->assertNull($perfiles['usuario_id']);
        $this->assertNull($perfiles['rol_principal']);
    }
}
