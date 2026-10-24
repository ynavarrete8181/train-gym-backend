<?php

namespace Tests\Feature;

use App\Services\Seguridad\AuthService;
use App\Services\Auditoria\AuditoriaServicio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement("ATTACH DATABASE ':memory:' AS seguridad");
        DB::statement('CREATE TABLE seguridad.users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, password TEXT, usr_tipo INTEGER, usr_estado INTEGER, email_verified_at TEXT, created_at TEXT, updated_at TEXT)');
        DB::statement('CREATE TABLE seguridad.cpu_userrole (id_userrole INTEGER PRIMARY KEY, role TEXT, activo INTEGER)');
        DB::statement("ATTACH DATABASE ':memory:' AS notificaciones");
        DB::statement('CREATE TABLE notificaciones.notificaciones (id INTEGER PRIMARY KEY, usuario_id INTEGER, tipo TEXT)');
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

        $auditoria = $this->mock(AuditoriaServicio::class);
        $auditoria->shouldReceive('registrarAcceso')->once()
            ->with(1, 'usuario@base.local', 'LOGIN_FALLIDO', 'Credenciales inválidas o usuario deshabilitado.');

        $resultado = app(AuthService::class)->intentarLogin('usuario@base.local', 'ClaveSegura123*');

        $this->assertNull($resultado);
        $this->assertSame(0, DB::table('seguridad.tokens_acceso')->count());
    }

    public function test_rechaza_cuenta_con_invitacion_pendiente(): void
    {
        DB::table('seguridad.cpu_userrole')->insert(['id_userrole' => 1, 'role' => 'ADMIN', 'activo' => true]);
        DB::table('seguridad.users')->insert([
            'id' => 1, 'name' => 'Admin', 'email' => 'admin@example.test',
            'password' => Hash::make('ClaveDePruebaSegura'),
            'usr_tipo' => 1, 'usr_estado' => 1,
        ]);
        DB::table('notificaciones.notificaciones')->insert(['usuario_id' => 1, 'tipo' => 'INVITACION_USUARIO']);
        $this->mock(AuditoriaServicio::class)->shouldReceive('registrarAcceso')->once();

        $this->assertNull(app(AuthService::class)->intentarLogin('admin@example.test', 'ClaveDePruebaSegura'));
        $this->assertSame(0, DB::table('seguridad.tokens_acceso')->count());
    }

    public function test_rechaza_contrasena_incorrecta(): void
    {
        DB::table('seguridad.cpu_userrole')->insert(['id_userrole' => 1, 'role' => 'ADMIN', 'activo' => true]);
        DB::table('seguridad.users')->insert([
            'id' => 1, 'name' => 'Admin', 'email' => 'admin@example.test',
            'password' => Hash::make('ClaveDePruebaSegura'),
            'usr_tipo' => 1, 'usr_estado' => 1,
        ]);
        $this->mock(AuditoriaServicio::class)->shouldReceive('registrarAcceso')->once();

        $this->assertNull(app(AuthService::class)->intentarLogin('admin@example.test', 'ClaveEquivocada'));
    }


    public function test_permite_login_con_invitacion_activada(): void
    {
        DB::table('seguridad.cpu_userrole')->insert(['id_userrole' => 1, 'role' => 'ADMIN', 'activo' => true]);
        DB::table('seguridad.users')->insert([
            'id' => 1, 'name' => 'Admin', 'email' => 'admin@example.test',
            'password' => Hash::make('ClaveDePruebaSegura'),
            'usr_tipo' => 1, 'usr_estado' => 1,
            'email_verified_at' => now(),
        ]);
        DB::table('notificaciones.notificaciones')->insert(['usuario_id' => 1, 'tipo' => 'INVITACION_USUARIO']);
        $this->mock(AuditoriaServicio::class)->shouldReceive('registrarAcceso')->once();

        $resultado = app(AuthService::class)->intentarLogin('admin@example.test', 'ClaveDePruebaSegura');
        $this->assertIsArray($resultado);
        $this->assertNotEmpty($resultado['token']);
        $this->assertSame(1, DB::table('seguridad.tokens_acceso')->count());
    }

}
