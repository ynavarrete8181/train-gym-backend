<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo de bancos y cooperativas para registrar transferencias y depósitos.
 * Cada pago guarda la institución elegida (`ventas.pagos.institucion_financiera_id`)
 * y su nombre en `banco`, para que el historial no cambie si luego se renombra.
 * Se administra en Configuración › Instituciones financieras.
 */
return new class extends Migration
{
    private const MENU = 'CONFIGURACION-INSTITUCIONES-FINANCIERAS';

    private const BANCOS = [
        'Banco Pichincha', 'Banco Guayaquil', 'Banco del Pacífico', 'Produbanco', 'Banco Internacional',
        'Banco Bolivariano', 'Banco del Austro', 'Banco General Rumiñahui', 'Banco de Machala', 'Banco de Loja',
        'Banco Solidario', 'Banco ProCredit', 'Banco Amazonas', 'Banco Diners Club', 'Banco Capital',
        'Banco Comercial de Manabí', 'Banco Coopnacional', 'Banco Litoral', 'Banco D-Miro', 'Banco Finca',
        'Banco Desarrollo de los Pueblos', 'Banco VisionFund Ecuador', 'Banco Delbank', 'Citibank Ecuador',
        'BanEcuador', 'Corporación Financiera Nacional',
    ];

    private const COOPERATIVAS = [
        'Cooperativa JEP', 'Cooperativa Jardín Azuayo', 'Cooperativa Policía Nacional', 'Cooperativa 29 de Octubre',
        'Cooperativa Alianza del Valle', 'Cooperativa Cooprogreso', 'Cooperativa Andalucía', 'Cooperativa Oscus',
        'Cooperativa San Francisco', 'Cooperativa Mushuc Runa', 'Cooperativa Fernando Daquilema', 'Cooperativa CACPECO',
        'Cooperativa Atuntaqui', 'Cooperativa Pablo Muñoz Vega', 'Cooperativa Tulcán', 'Cooperativa Riobamba',
        'Cooperativa Chibuleo', 'Cooperativa Ambato', 'Cooperativa El Sagrario', 'Cooperativa 23 de Julio',
        'Cooperativa Pilahuin Tío', 'Cooperativa Kullki Wasi', 'Cooperativa Vicentina', 'Cooperativa Coopmego',
        'Cooperativa Padre Julián Lorente', 'Cooperativa CREA', 'Cooperativa Biblián', 'Cooperativa Santa Rosa',
        'Cooperativa Pedro Moncayo', 'Cooperativa 15 de Abril', 'Cooperativa Chone', 'Cooperativa Calceta',
    ];

    private const MUTUALISTAS = ['Mutualista Pichincha', 'Mutualista Azuay', 'Mutualista Ambato', 'Mutualista Imbabura'];

    public function up(): void
    {
        DB::statement("CREATE TABLE IF NOT EXISTS configuracion.instituciones_financieras (
            id bigserial PRIMARY KEY,
            nombre varchar(120) NOT NULL,
            tipo varchar(20) NOT NULL CHECK (tipo IN ('BANCO', 'COOPERATIVA', 'MUTUALISTA', 'OTRA')),
            orden integer NOT NULL DEFAULT 100,
            activo boolean NOT NULL DEFAULT true,
            created_at timestamp NULL,
            updated_at timestamp NULL
        )");
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_instituciones_financieras_nombre ON configuracion.instituciones_financieras (LOWER(nombre))');

        $orden = 0;
        foreach ([['BANCO', self::BANCOS], ['COOPERATIVA', self::COOPERATIVAS], ['MUTUALISTA', self::MUTUALISTAS], ['OTRA', ['Otra institución']]] as [$tipo, $nombres]) {
            foreach ($nombres as $nombre) {
                $orden += 10;
                if (! DB::table('configuracion.instituciones_financieras')->whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])->exists()) {
                    DB::table('configuracion.instituciones_financieras')->insert([
                        'nombre' => $nombre, 'tipo' => $tipo, 'orden' => $orden, 'activo' => true,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }

        // Cada pago apunta a la institución; los ya registrados con nombre se enlazan.
        DB::statement('ALTER TABLE ventas.pagos ADD COLUMN IF NOT EXISTS institucion_financiera_id bigint NULL');
        DB::statement('DO $$ BEGIN
            IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = \'fk_pagos_institucion_financiera\') THEN
                ALTER TABLE ventas.pagos ADD CONSTRAINT fk_pagos_institucion_financiera
                    FOREIGN KEY (institucion_financiera_id) REFERENCES configuracion.instituciones_financieras(id);
            END IF;
        END $$');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_pagos_institucion_financiera ON ventas.pagos (institucion_financiera_id)');
        DB::statement('UPDATE ventas.pagos p SET institucion_financiera_id = i.id
            FROM configuracion.instituciones_financieras i
            WHERE p.institucion_financiera_id IS NULL AND p.banco IS NOT NULL AND LOWER(TRIM(p.banco)) = LOWER(i.nombre)');

        $this->registrarMenu();
    }

    public function down(): void
    {
        DB::table('seguridad.cpu_userfunction')->where('id_menu', self::MENU)->delete();
        DB::table('seguridad.cpu_userrolefunction')->where('id_menu', self::MENU)->delete();
        DB::table('seguridad.cpu_pagina_sistema')->where('id_menu', self::MENU)->delete();
        DB::statement('ALTER TABLE ventas.pagos DROP CONSTRAINT IF EXISTS fk_pagos_institucion_financiera');
        DB::statement('ALTER TABLE ventas.pagos DROP COLUMN IF EXISTS institucion_financiera_id');
        DB::statement('DROP TABLE IF EXISTS configuracion.instituciones_financieras');
    }

    private function registrarMenu(): void
    {
        $menuId = DB::table('seguridad.cpu_usermenu')->where('menu', 'Configuración')->value('id_usermenu');
        if (! $menuId) {
            return;
        }

        DB::table('seguridad.cpu_pagina_sistema')->updateOrInsert(
            ['id_menu' => self::MENU],
            ['clave_pagina' => 'InstitucionesFinancierasPage', 'created_at' => now(), 'updated_at' => now()]
        );

        $funcion = [
            'id_usermenu' => $menuId,
            'nombre' => 'Instituciones financieras',
            'accion' => '/configuracion/instituciones-financieras',
            'icono' => 'account_balance',
            'activo' => true,
            'orden' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $roles = DB::table('seguridad.cpu_userrole')->whereIn('role', ['SUPERADMINISTRADOR', 'ADMINISTRADOR'])->get(['id_userrole']);
        foreach ($roles as $rol) {
            DB::table('seguridad.cpu_userrolefunction')->updateOrInsert(
                ['id_userrole' => $rol->id_userrole, 'id_menu' => self::MENU], $funcion);
            foreach (DB::table('seguridad.users')->where('usr_tipo', $rol->id_userrole)->get(['id']) as $usuario) {
                DB::table('seguridad.cpu_userfunction')->updateOrInsert(
                    ['id_users' => $usuario->id, 'id_menu' => self::MENU], $funcion + ['id_userrole' => $rol->id_userrole]);
            }
        }
    }
};
