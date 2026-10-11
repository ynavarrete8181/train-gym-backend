<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->validarPostgreSql();

        DB::transaction(function (): void {
            DB::statement('ALTER TABLE seguridad.users ADD CONSTRAINT fk_seguridad_users_rol FOREIGN KEY (usr_tipo) REFERENCES seguridad.cpu_userrole (id_userrole) ON UPDATE CASCADE ON DELETE RESTRICT');

            DB::statement('ALTER TABLE seguridad.cpu_userrolefunction ADD CONSTRAINT fk_seguridad_rolefunction_rol FOREIGN KEY (id_userrole) REFERENCES seguridad.cpu_userrole (id_userrole) ON UPDATE CASCADE ON DELETE RESTRICT');
            DB::statement('ALTER TABLE seguridad.cpu_userrolefunction ADD CONSTRAINT fk_seguridad_rolefunction_menu FOREIGN KEY (id_usermenu) REFERENCES seguridad.cpu_usermenu (id_usermenu) ON UPDATE CASCADE ON DELETE RESTRICT');

            DB::statement('ALTER TABLE seguridad.cpu_userfunction ADD CONSTRAINT fk_seguridad_userfunction_usuario FOREIGN KEY (id_users) REFERENCES seguridad.users (id) ON UPDATE CASCADE ON DELETE CASCADE');
            DB::statement('ALTER TABLE seguridad.cpu_userfunction ADD CONSTRAINT fk_seguridad_userfunction_rol FOREIGN KEY (id_userrole) REFERENCES seguridad.cpu_userrole (id_userrole) ON UPDATE CASCADE ON DELETE RESTRICT');
            DB::statement('ALTER TABLE seguridad.cpu_userfunction ADD CONSTRAINT fk_seguridad_userfunction_menu FOREIGN KEY (id_usermenu) REFERENCES seguridad.cpu_usermenu (id_usermenu) ON UPDATE CASCADE ON DELETE RESTRICT');

            DB::statement('ALTER TABLE seguridad.tokens_acceso ADD CONSTRAINT fk_seguridad_tokens_usuario FOREIGN KEY (id_usuario) REFERENCES seguridad.users (id) ON UPDATE CASCADE ON DELETE CASCADE');
            DB::statement('ALTER TABLE seguridad.preferencias_usuario ADD CONSTRAINT fk_seguridad_preferencias_usuario FOREIGN KEY (id_usuario) REFERENCES seguridad.users (id) ON UPDATE CASCADE ON DELETE CASCADE');
        });
    }

    public function down(): void
    {
        $this->validarPostgreSql();

        DB::transaction(function (): void {
            DB::statement('ALTER TABLE seguridad.preferencias_usuario DROP CONSTRAINT fk_seguridad_preferencias_usuario');
            DB::statement('ALTER TABLE seguridad.tokens_acceso DROP CONSTRAINT fk_seguridad_tokens_usuario');

            DB::statement('ALTER TABLE seguridad.cpu_userfunction DROP CONSTRAINT fk_seguridad_userfunction_menu');
            DB::statement('ALTER TABLE seguridad.cpu_userfunction DROP CONSTRAINT fk_seguridad_userfunction_rol');
            DB::statement('ALTER TABLE seguridad.cpu_userfunction DROP CONSTRAINT fk_seguridad_userfunction_usuario');

            DB::statement('ALTER TABLE seguridad.cpu_userrolefunction DROP CONSTRAINT fk_seguridad_rolefunction_menu');
            DB::statement('ALTER TABLE seguridad.cpu_userrolefunction DROP CONSTRAINT fk_seguridad_rolefunction_rol');

            DB::statement('ALTER TABLE seguridad.users DROP CONSTRAINT fk_seguridad_users_rol');
        });
    }

    private function validarPostgreSql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Esta migración solo puede ejecutarse en PostgreSQL.');
        }
    }
};
