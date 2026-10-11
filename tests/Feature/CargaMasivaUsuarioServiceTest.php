<?php

namespace Tests\Feature;

use App\Services\Seguridad\CargaMasivaUsuarioService;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

class CargaMasivaUsuarioServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::statement("ATTACH DATABASE ':memory:' AS seguridad");
        DB::statement("ATTACH DATABASE ':memory:' AS institucional");
        DB::statement('CREATE TABLE seguridad.users (id INTEGER PRIMARY KEY, email TEXT, cedula TEXT)');
        DB::statement('CREATE TABLE seguridad.cpu_userrole (id_userrole INTEGER PRIMARY KEY, role TEXT, activo INTEGER)');
        DB::statement('CREATE TABLE institucional.sedes (id_sede INTEGER PRIMARY KEY, codigo TEXT, nombre TEXT, activo INTEGER)');
        DB::statement('CREATE TABLE institucional.unidades (id_unidad INTEGER PRIMARY KEY, codigo TEXT, nombre TEXT, tipo TEXT, activo INTEGER)');
        DB::statement('CREATE TABLE institucional.carreras_areas (id_carrera_area INTEGER PRIMARY KEY, id_unidad INTEGER, codigo TEXT, nombre TEXT, tipo TEXT, activo INTEGER)');
        DB::statement('CREATE TABLE institucional.contextos (id_contexto INTEGER PRIMARY KEY, id_sede INTEGER, id_unidad INTEGER, id_carrera_area INTEGER, activo INTEGER)');
        DB::table('seguridad.cpu_userrole')->insert(['id_userrole' => 2, 'role' => 'USUARIO', 'activo' => 1]);
        DB::table('institucional.sedes')->insert(['id_sede' => 1, 'codigo' => 'MATRIZ', 'nombre' => 'Matriz', 'activo' => 1]);
        DB::table('institucional.unidades')->insert(['id_unidad' => 1, 'codigo' => 'FAC', 'nombre' => 'Facultad', 'tipo' => 'FACULTAD', 'activo' => 1]);
        DB::table('institucional.carreras_areas')->insert(['id_carrera_area' => 1, 'id_unidad' => 1, 'codigo' => 'SW', 'nombre' => 'Software', 'tipo' => 'CARRERA', 'activo' => 1]);
        DB::table('institucional.contextos')->insert(['id_contexto' => 1, 'id_sede' => 1, 'id_unidad' => 1, 'id_carrera_area' => 1, 'activo' => 1]);
    }

    public function test_valida_campos_obligatorios_y_rol_activo(): void
    {
        $resultado = app(CargaMasivaUsuarioService::class)->validar([[
            'nombres' => 'Ana',
            'apellidos' => 'Pérez',
            'cedula' => '1300000001',
            'email' => 'ana@example.com',
            'rol' => 'USUARIO',
            'contexto' => 'MATRIZ | Facultad | Software',
            'estado' => 'ACTIVO',
        ]]);

        $this->assertSame('valido', $resultado[0]['estado']);
        $this->assertSame(2, (int) $resultado[0]['datos']['usr_tipo']);
    }

    public function test_rechaza_identidad_incompleta_y_duplicados_existentes(): void
    {
        DB::table('seguridad.users')->insert(['id' => 1, 'email' => 'existente@example.com', 'cedula' => '1300000002']);

        $resultado = app(CargaMasivaUsuarioService::class)->validar([[
            'nombres' => 'Ana',
            'apellidos' => '',
            'cedula' => '1300000002',
            'email' => 'existente@example.com',
            'rol' => 'USUARIO',
            'contexto' => 'MATRIZ | Facultad | Software',
            'estado' => 'ACTIVO',
        ]]);

        $this->assertSame('error', $resultado[0]['estado']);
        $this->assertContains('Los apellidos son obligatorios.', $resultado[0]['errores']);
        $this->assertContains('El correo ya pertenece a un usuario existente.', $resultado[0]['errores']);
        $this->assertContains('La cédula ya pertenece a un usuario existente.', $resultado[0]['errores']);
    }

    public function test_ignora_cualquier_columna_de_password_del_archivo(): void
    {
        $resultado = app(CargaMasivaUsuarioService::class)->validar([[
            'nombres' => 'Ana', 'apellidos' => 'Pérez', 'cedula' => '1300000009',
            'email' => 'ana.segura@example.com', 'password' => '123', 'rol' => 'USUARIO',
            'contexto' => 'MATRIZ | Facultad | Software', 'estado' => 'ACTIVO',
        ]]);

        $this->assertSame('valido', $resultado[0]['estado']);
        $this->assertArrayNotHasKey('password', $resultado[0]['datos']);
    }

    public function test_genera_plantilla_excel_con_roles_actuales_y_listas_desplegables(): void
    {
        ob_start();
        app(CargaMasivaUsuarioService::class)->escribirPlantilla();
        $contenido = ob_get_clean();
        $ruta = tempnam(sys_get_temp_dir(), 'plantilla_usuarios_').'.xlsx';
        file_put_contents($ruta, $contenido);

        try {
            $libro = IOFactory::load($ruta);

            $this->assertSame(['Usuarios', 'Roles', 'Contextos', 'Instrucciones'], $libro->getSheetNames());
            $this->assertSame('USUARIO', $libro->getSheetByName('Roles')->getCell('A2')->getValue());
            $this->assertSame(Worksheet::SHEETSTATE_VERYHIDDEN, $libro->getSheetByName('Roles')->getSheetState());
            $this->assertTrue($libro->getSheetByName('Roles')->getProtection()->getSheet());
            $this->assertSame("'Roles'!\$A\$2:\$A\$2", $libro->getDefinedName('RolesActivos')->getValue());
            $this->assertSame(['nombres', 'apellidos', 'cedula', 'email', 'rol', 'contexto', 'estado'], $libro->getSheetByName('Usuarios')->rangeToArray('A1:G1')[0]);
            $this->assertSame('RolesActivos', $libro->getSheetByName('Usuarios')->getCell('E2')->getDataValidation()->getFormula1());
            $this->assertTrue($libro->getSheetByName('Usuarios')->getCell('E2')->getDataValidation()->getShowDropDown());
            $this->assertSame('ContextosActivos', $libro->getSheetByName('Usuarios')->getCell('F2')->getDataValidation()->getFormula1());
            $this->assertSame('"ACTIVO,INACTIVO"', $libro->getSheetByName('Usuarios')->getCell('G2')->getDataValidation()->getFormula1());

            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($ruta));
            $this->assertStringContainsString("<definedName name=\"RolesActivos\">'Roles'!\$A\$2:\$A\$2</definedName>", $zip->getFromName('xl/workbook.xml'));
            $this->assertStringContainsString('sqref="E2:E1001"', $zip->getFromName('xl/worksheets/sheet1.xml'));
            $zip->close();
        } finally {
            @unlink($ruta);
        }
    }
}
