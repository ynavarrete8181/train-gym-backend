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
        DB::statement('CREATE TABLE seguridad.users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, cedula TEXT, persona_id INTEGER, password TEXT, usr_tipo INTEGER, usr_estado INTEGER, email_verified_at TEXT, created_at TEXT, updated_at TEXT)');
        DB::statement('CREATE TABLE seguridad.cpu_userrole (id_userrole INTEGER PRIMARY KEY, role TEXT, activo INTEGER)');
        DB::statement("ATTACH DATABASE ':memory:' AS personas");
        DB::statement("CREATE TABLE personas.personas (id INTEGER PRIMARY KEY, identificacion TEXT)");
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

    public function test_permite_iniciar_sesion_por_cedula_del_usuario(): void
    {
        DB::table('seguridad.cpu_userrole')->insert(['id_userrole' => 1, 'role' => 'CLIENTE', 'activo' => true]);
        DB::table('seguridad.users')->insert([
            'id' => 11, 'name' => 'Deportista',
            'email' => 'deportista@example.test', 'cedula' => '1312345678',
            'password' => Hash::make('ClaveSegura123*'),
            'usr_tipo' => 1, 'usr_estado' => 1,
        ]);
        $this->mock(AuditoriaServicio::class)->shouldReceive('registrarAcceso')->once()
            ->with(11, 'deportista@example.test', 'LOGIN');

        $resultado = app(AuthService::class)->intentarLogin('1312345678', 'ClaveSegura123*');
        $this->assertIsArray($resultado);
        $this->assertSame(11, $resultado['usuario']->id);
        $this->assertSame(1, DB::table('seguridad.tokens_acceso')->count());
    }

    public function test_permite_iniciar_sesion_por_identificacion_de_persona_vinculada(): void
    {
        DB::table('seguridad.cpu_userrole')->insert(['id_userrole' => 1, 'role' => 'CLIENTE', 'activo' => true]);
        DB::table('personas.personas')->insert(['id' => 5, 'identificacion' => '1309876543']);
        DB::table('seguridad.users')->insert([
            'id' => 12, 'persona_id' => 5, 'name' => 'Persona vinculada',
            'email' => 'vinculada@example.test', 'cedula' => null,
            'password' => Hash::make('ClaveSegura123*'),
            'usr_tipo' => 1, 'usr_estado' => 1,
        ]);
        $this->mock(AuditoriaServicio::class)->shouldReceive('registrarAcceso')->once()
            ->with(12, 'vinculada@example.test', 'LOGIN');

        $resultado = app(AuthService::class)->intentarLogin('1309876543', 'ClaveSegura123*');
        $this->assertIsArray($resultado);
        $this->assertSame(12, $resultado['usuario']->id);
    }

    public function test_rechaza_cedula_duplicada_sin_autenticar_a_ninguna_cuenta(): void
    {
        DB::table('seguridad.cpu_userrole')->insert(['id_userrole' => 1, 'role' => 'CLIENTE', 'activo' => true]);
        foreach ([1, 2] as $id) {
            DB::table('seguridad.users')->insert([
                'id' => $id, 'name' => 'Cuenta ' . $id,
                'email' => 'cuenta' . $id . '@example.test',
                'cedula' => '1301234567',
                'password' => Hash::make('ClaveSegura123*'),
                'usr_tipo' => 1, 'usr_estado' => 1,
            ]);
        }
        $this->mock(AuditoriaServicio::class)->shouldReceive('registrarAcceso')->once()
            ->with(null, '1301234567', 'LOGIN_FALLIDO', 'Usuario no encontrado o identificador ambiguo.');

        $this->assertNull(app(AuthService::class)->intentarLogin('1301234567', 'ClaveSegura123*'));
        $this->assertSame(0, DB::table('seguridad.tokens_acceso')->count());
    }

    public function test_rechaza_cedula_con_clave_incorrecta(): void
    {
        DB::table('seguridad.cpu_userrole')->insert(['id_userrole' => 1, 'role' => 'CLIENTE', 'activo' => true]);
        DB::table('seguridad.users')->insert([
            'id' => 15, 'name' => 'Cliente',
            'email' => 'cliente@example.test', 'cedula' => '1301234599',
            'password' => Hash::make('ClaveSegura123*'),
            'usr_tipo' => 1, 'usr_estado' => 1,
        ]);
        $this->mock(AuditoriaServicio::class)->shouldReceive('registrarAcceso')->once();
        $this->assertNull(app(AuthService::class)->intentarLogin('1301234599', 'ClaveIncorrecta'));
    }

}
