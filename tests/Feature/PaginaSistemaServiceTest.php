<?php

namespace Tests\Feature;

use App\Services\Seguridad\PaginaSistemaService;
use App\Services\Seguridad\TiempoRealNavegacionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaginaSistemaServiceTest extends TestCase
{
    private function servicio(): PaginaSistemaService
    {
        return new PaginaSistemaService($this->mock(TiempoRealNavegacionService::class, function ($mock): void {
            $mock->shouldReceive('emitir')->zeroOrMoreTimes();
        }));
    }

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement("ATTACH DATABASE ':memory:' AS seguridad");
        DB::statement('CREATE TABLE seguridad.cpu_usermenu (id_usermenu INTEGER PRIMARY KEY, menu TEXT, icono TEXT, activo INTEGER, orden INTEGER)');
        DB::statement('CREATE TABLE seguridad.cpu_userrolefunction (id_userrf INTEGER PRIMARY KEY, id_userrole INTEGER, id_usermenu INTEGER, nombre TEXT, icono TEXT, id_menu TEXT)');
        DB::statement('CREATE TABLE seguridad.cpu_pagina_sistema (id_pagina_sistema INTEGER PRIMARY KEY AUTOINCREMENT, id_menu TEXT UNIQUE, clave_pagina TEXT, created_at TEXT, updated_at TEXT)');

        DB::table('seguridad.cpu_usermenu')->insert(['id_usermenu' => 1, 'menu' => 'Administración', 'orden' => 1]);
        DB::table('seguridad.cpu_userrolefunction')->insert([
            ['id_userrf' => 1, 'id_userrole' => 1, 'id_usermenu' => 1, 'nombre' => 'Usuarios', 'icono' => 'people', 'id_menu' => 'SEGURIDAD-USUARIOS'],
            ['id_userrf' => 2, 'id_userrole' => 2, 'id_usermenu' => 1, 'nombre' => 'Usuarios', 'icono' => 'people', 'id_menu' => 'SEGURIDAD-USUARIOS'],
        ]);
    }

    public function test_asocia_una_pagina_una_sola_vez_por_codigo_de_submenu(): void
    {
        $servicio = $this->servicio();

        $servicio->asociar('SEGURIDAD-USUARIOS', 'UsuariosPage');
        $servicio->asociar('SEGURIDAD-USUARIOS', 'DashboardPage');

        $this->assertSame(1, DB::table('seguridad.cpu_pagina_sistema')->count());
        $this->assertSame('DashboardPage', DB::table('seguridad.cpu_pagina_sistema')->value('clave_pagina'));
        $this->assertCount(1, $servicio->listar());
    }

    public function test_rechaza_la_asociacion_de_un_submenu_inexistente(): void
    {
        $this->expectException(ValidationException::class);

        $this->servicio()->asociar('NO-EXISTE', 'DashboardPage');
    }

    public function test_desasocia_una_pagina_sin_eliminar_el_submenu(): void
    {
        $servicio = $this->servicio();
        $servicio->asociar('SEGURIDAD-USUARIOS', 'UsuariosPage');

        $servicio->desasociar('SEGURIDAD-USUARIOS');

        $this->assertSame(0, DB::table('seguridad.cpu_pagina_sistema')->count());
        $this->assertSame(2, DB::table('seguridad.cpu_userrolefunction')->count());
    }
}
